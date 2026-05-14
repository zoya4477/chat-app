<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Group;
use App\Models\Channel;
use App\Models\User;
use App\Events\MessageSent;
use App\Events\MessageDelivered;
use App\Events\MessageSeen;
use App\Events\MessageEdited;
use App\Events\MessageDeleted;
use App\Events\MessageReaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    // =========================================
    // GET DIRECT MESSAGES
    // =========================================
    public function getMessages($userId)
    {
        try {
            $authId = auth()->id();

            $messages = Message::where(function ($q) use ($userId, $authId) {
                            $q->where('sender_id', $authId)
                              ->where('receiver_id', $userId);
                        })
                        ->orWhere(function ($q) use ($userId, $authId) {
                            $q->where('sender_id', $userId)
                              ->where('receiver_id', $authId);
                        })
                        ->whereNull('group_id')
                        ->whereNull('channel_id')
                        ->with('sender')
                        ->orderBy('created_at', 'asc')
                        ->get();

            return response()->json(['success' => true, 'messages' => $messages]);

        } catch (\Exception $e) {
            Log::error('Get messages error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // SEND MESSAGE (Direct + Group + Channel)
    // =========================================
    public function sendMessage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'message'     => 'nullable|string|max:5000',
                'receiver_id' => 'nullable|exists:users,id',
                'group_id'    => 'nullable|exists:groups,id',
                'channel_id'  => 'nullable|exists:channels,id',
                'reply_to'    => 'nullable|exists:messages,id',
                'file'        => 'nullable|file|max:20480',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Koi target nahi
            if (!$request->receiver_id && !$request->group_id && !$request->channel_id) {
                return response()->json(['error' => 'receiver_id, group_id or channel_id is required'], 422);
            }

            // Message ya file zaroori hai
            if (!$request->filled('message') && !$request->hasFile('file')) {
                return response()->json(['error' => 'Message or file is required'], 422);
            }

            // Rate limit
            $user = $request->user();
            $key = 'message_limit_' . $user->id;
            $rateLimit = cache()->get($key, 0);
            if ($rateLimit >= 60) {
                return response()->json(['error' => 'Rate limit exceeded. Please slow down.'], 429);
            }
            cache()->put($key, $rateLimit + 1, now()->addMinute());

            // Inappropriate content check
            if ($request->filled('message') && $this->containsInappropriateContent($request->message)) {
                return response()->json(['error' => 'Message contains inappropriate content'], 422);
            }

            // Receiver check
            if ($request->receiver_id) {
                $receiver = User::find($request->receiver_id);
                if (!$receiver) {
                    return response()->json(['error' => 'Receiver not found'], 404);
                }
            }

            // Group membership check
            $group = null;
            if ($request->group_id) {
                $group = Group::findOrFail($request->group_id);
                $isMember = $group->members()->where('user_id', auth()->id())->exists();
                if (!$isMember && $group->created_by !== auth()->id()) {
                    return response()->json(['error' => 'You are not a member of this group'], 403);
                }
            }

            // Channel membership check
            $channel = null;
            if ($request->channel_id) {
                $channel = Channel::findOrFail($request->channel_id);
                if (!$channel->isMember(auth()->id())) {
                    return response()->json(['error' => 'Not a member of this channel'], 403);
                }
                if ($channel->is_archived) {
                    return response()->json(['error' => 'Channel is archived'], 403);
                }
            }

            // File upload
            $fileUrl     = null;
            $fileName    = null;
            $fileSize    = null;
            $messageType = 'text';

            if ($request->hasFile('file')) {
                $file        = $request->file('file');
                $fileName    = $file->getClientOriginalName();
                $fileSize    = $file->getSize();
                $mimeType    = $file->getMimeType();
                $path        = $file->store('chat-files', 'public');
                $fileUrl     = asset('storage/' . $path);

                if (str_starts_with($mimeType, 'image/')) {
                    $messageType = 'image';
                } elseif (str_starts_with($mimeType, 'video/')) {
                    $messageType = 'video';
                } elseif (str_starts_with($mimeType, 'audio/')) {
                    $messageType = 'audio';
                } else {
                    $messageType = 'file';
                }
            }

            // Message create
            $message = Message::create([
                'sender_id'    => auth()->id(),
                'receiver_id'  => $request->receiver_id  ?? null,
                'group_id'     => $request->group_id     ?? null,
                'channel_id'   => $request->channel_id   ?? null,
                'message'      => $request->message       ?? '',
                'reply_to'     => $request->reply_to      ?? null,
                'file_url'     => $fileUrl,
                'file_name'    => $fileName,
                'file_size'    => $fileSize,
                'message_type' => $messageType,
                'status'       => 'sent',
                'is_read'      => false,
                'is_edited'    => false,
                'reactions'    => null,
            ]);

            $message->load('sender');

            // Channel unread count update
            if ($channel) {
                foreach ($channel->members as $member) {
                    if ($member->id != auth()->id()) {
                        $currentUnread = $member->pivot->unread_count ?? 0;
                        $channel->members()->updateExistingPivot($member->id, [
                            'unread_count' => $currentUnread + 1
                        ]);
                    }
                }
            }

            // Broadcast (fail hone pe bhi message return hoga)
            try {
                broadcast(new MessageSent($message))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Broadcast failed: ' . $e->getMessage());
            }

            return response()->json(['success' => true, 'message' => $message], 201);

        } catch (\Exception $e) {
            Log::error('Send message error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // GET GROUP MESSAGES
    // =========================================
    public function getGroupMessages($groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $isMember = $group->members()->where('user_id', auth()->id())->exists();

            if (!$isMember && $group->created_by !== auth()->id()) {
                return response()->json(['error' => 'You are not a member of this group'], 403);
            }

            $messages = Message::where('group_id', $groupId)
                        ->with('sender')
                        ->orderBy('created_at', 'asc')
                        ->get();

            return response()->json(['success' => true, 'messages' => $messages]);

        } catch (\Exception $e) {
            Log::error('Get group messages error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get group messages'], 500);
        }
    }

    // =========================================
    // GET CHANNEL MESSAGES
    // =========================================
    public function getChannelMessages($channelId)
    {
        try {
            $channel = Channel::findOrFail($channelId);

            if (!$channel->isMember(auth()->id())) {
                return response()->json(['error' => 'Not a member of this channel'], 403);
            }

            $messages = Message::where('channel_id', $channelId)
                        ->with('sender')
                        ->orderBy('created_at', 'asc')
                        ->get();

            return response()->json(['success' => true, 'messages' => $messages]);

        } catch (\Exception $e) {
            Log::error('Get channel messages error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // MARK AS DELIVERED
    // =========================================
    public function markAsDelivered($messageId)
    {
        try {
            $message = Message::where('id', $messageId)
                        ->where('receiver_id', auth()->id())
                        ->first();

            if ($message && $message->status === 'sent') {
                $message->update(['status' => 'delivered']);
                try {
                    broadcast(new MessageDelivered($message))->toOthers();
                } catch (\Exception $e) {
                    Log::warning('Broadcast delivered failed: ' . $e->getMessage());
                }
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // MARK AS SEEN
    // =========================================
    public function markAsSeen($messageId)
    {
        try {
            $message = Message::where('id', $messageId)
                        ->where('receiver_id', auth()->id())
                        ->first();

            if ($message && $message->status !== 'seen') {
                $message->update(['status' => 'seen', 'is_read' => true]);
                try {
                    broadcast(new MessageSeen($message))->toOthers();
                } catch (\Exception $e) {
                    Log::warning('Broadcast seen failed: ' . $e->getMessage());
                }
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // MARK CONVERSATION AS SEEN
    // =========================================
    public function markConversationAsSeen($userId)
    {
        try {
            Message::where('sender_id', $userId)
                   ->where('receiver_id', auth()->id())
                   ->where('is_read', false)
                   ->update(['is_read' => true, 'status' => 'seen']);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // ADD REACTION
    // =========================================
    public function addReaction(Request $request, $messageId)
    {
        try {
            $request->validate(['emoji' => 'required|string|max:10']);

            $message   = Message::findOrFail($messageId);
            $userId    = auth()->id();
            $emoji     = $request->emoji;
            $reactions = is_array($message->reactions) ? $message->reactions : [];

            // Toggle reaction
            if (isset($reactions[$userId]) && $reactions[$userId] === $emoji) {
                unset($reactions[$userId]);
            } else {
                $reactions[$userId] = $emoji;
            }

            $message->update(['reactions' => $reactions]);

            try {
                broadcast(new MessageReaction($message, $reactions))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Broadcast reaction failed: ' . $e->getMessage());
            }

            return response()->json(['success' => true, 'reactions' => $reactions]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // EDIT MESSAGE (30 min limit)
    // =========================================
    public function editMessage(Request $request, $messageId)
    {
        try {
            $message = Message::findOrFail($messageId);

            if ($message->sender_id !== auth()->id()) {
                return response()->json(['error' => 'You can only edit your own messages'], 403);
            }

            // 30 minute edit window
            if ($message->created_at < now()->subMinutes(30)) {
                return response()->json(['error' => 'Edit time expired (30 minutes limit)'], 403);
            }

            $request->validate(['message' => 'required|string|max:5000']);

            $message->update([
                'message'          => $request->message,
                'is_edited'        => true,
                'edited_at'        => now(),
                'original_message' => $message->original_message ?? $message->message,
            ]);

            try {
                broadcast(new MessageEdited($message))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Broadcast edit failed: ' . $e->getMessage());
            }

            return response()->json(['success' => true, 'message' => $message->fresh()]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // DELETE MESSAGE
    // =========================================
    public function deleteMessage($messageId)
    {
        try {
            $message = Message::findOrFail($messageId);

            if ($message->sender_id !== auth()->id()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // File delete karo agar hai
            if ($message->file_url) {
                try {
                    $relativePath = str_replace(
                        '/storage/', '',
                        parse_url($message->file_url, PHP_URL_PATH)
                    );
                    Storage::disk('public')->delete($relativePath);
                } catch (\Exception $e) {
                    Log::warning('File delete failed: ' . $e->getMessage());
                }
            }

            $messageId = $message->id;
            $message->delete();

            try {
                broadcast(new MessageDeleted($message))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Broadcast delete failed: ' . $e->getMessage());
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // GET THREAD MESSAGES
    // =========================================
    public function getThreadMessages($messageId)
    {
        try {
            $messages = Message::where('reply_to', $messageId)
                        ->with('sender')
                        ->orderBy('created_at', 'asc')
                        ->get();

            return response()->json(['success' => true, 'messages' => $messages]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // MESSAGES COUNT (unread)
    // =========================================
    public function getMessagesCount()
    {
        try {
            $count = Message::where('receiver_id', auth()->id())
                     ->where('is_read', false)
                     ->count();

            return response()->json(['success' => true, 'unread_count' => $count]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // =========================================
    // INAPPROPRIATE CONTENT CHECK
    // =========================================
    private function containsInappropriateContent($message)
    {
        $blacklist = [
            '/\b(fuck|shit|asshole|bitch)\b/i',
            '/\b(spam|scam|hack|crack)\b/i'
        ];

        foreach ($blacklist as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }
    /**
 * Get recent messages for search
 */
public function getRecentMessages(Request $request)
{
    $user = auth()->user();
    
    $messages = Message::with(['sender', 'channel', 'group'])
        ->where(function($q) use ($user) {
            // User can access:
            $q->where(function($q2) use ($user) {
                // Direct messages
                $q2->whereNotNull('receiver_id')
                   ->where(function($q3) use ($user) {
                       $q3->where('sender_id', $user->id)
                          ->orWhere('receiver_id', $user->id);
                   });
            })
            ->orWhere(function($q2) use ($user) {
                // Group messages
                $q2->whereNotNull('group_id')
                   ->whereHas('group.members', function($q3) use ($user) {
                       $q3->where('user_id', $user->id);
                   });
            })
            ->orWhere(function($q2) use ($user) {
                // Channel messages
                $q2->whereNotNull('channel_id')
                   ->whereHas('channel.members', function($q3) use ($user) {
                       $q3->where('user_id', $user->id);
                   });
            });
        })
        ->orderBy('created_at', 'desc')
        ->limit(100)
        ->get();
    
    return response()->json([
        'success' => true,
        'messages' => $messages
    ]);
}
}