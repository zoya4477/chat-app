<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChannelController extends Controller
{
    // Get all channels in workspace
    // ✅ FIX: Public channels sabko dikhao, private sirf members ko
    public function index($workspaceId)
    {
        $workspace = Workspace::findOrFail($workspaceId);

        if (!$workspace->isMember(auth()->id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $userId = auth()->id();

        // Public channels — sab workspace members dekh sakte hain
        $publicChannels = $workspace->channels()
            ->where('type', 'public')
            ->where('is_archived', false)
            ->withCount('members')
            ->get()
            ->map(function ($channel) use ($userId, $workspace) {
                $isMember = $channel->isMember($userId);

                // ✅ Agar member nahi hai toh auto-add karo public channel mein
                if (!$isMember) {
                    $channel->members()->attach($userId, [
                        'role'         => 'member',
                        'last_read_at' => now(),
                        'unread_count' => 0,
                    ]);
                    $isMember = true;
                }

                $channel->workspace_name = $workspace->name;
                $channel->is_member      = $isMember;
                $channel->unread_count   = $channel->getUnreadCount($userId);
                return $channel;
            });

        // Private channels — sirf jo member hain unhe dikhao
        $privateChannels = $workspace->channels()
            ->where('type', 'private')
            ->where('is_archived', false)
            ->withCount('members')
            ->get()
            ->filter(function ($channel) use ($userId) {
                return $channel->isMember($userId);
            })
            ->map(function ($channel) use ($userId, $workspace) {
                $channel->workspace_name = $workspace->name;
                $channel->is_member      = true;
                $channel->unread_count   = $channel->getUnreadCount($userId);
                return $channel;
            });

        // Dono merge karke return karo
        $channels = $publicChannels->merge($privateChannels)->values();

        return response()->json(['channels' => $channels]);
    }

    // Get all public channels
    public function getPublicChannels($workspaceId)
    {
        $workspace = Workspace::findOrFail($workspaceId);

        if (!$workspace->isMember(auth()->id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $channels = $workspace->channels()
            ->where('type', 'public')
            ->where('is_archived', false)
            ->withCount('members')
            ->get()
            ->map(function ($channel) {
                $channel->is_member = $channel->isMember(auth()->id());
                return $channel;
            });

        return response()->json(['channels' => $channels]);
    }

    // Create channel
    public function create(Request $request, $workspaceId)
    {
        $workspace = Workspace::findOrFail($workspaceId);

        if (!$workspace->isAdmin(auth()->id())) {
            return response()->json([
                'error' => 'Only workspace admins can create channels'
            ], 403);
        }

        $request->validate([
            'name'        => 'required|string|max:50|unique:channels,name,NULL,id,workspace_id,' . $workspaceId,
            'description' => 'nullable|string',
            'topic'       => 'nullable|string',
            'type'        => 'in:public,private'
        ]);

        $channel = Channel::create([
            'name'         => $request->name,
            'slug'         => Str::slug($request->name) . '-' . Str::random(5),
            'description'  => $request->description,
            'topic'        => $request->topic,
            'type'         => $request->type ?? 'public',
            'workspace_id' => $workspaceId,
            'created_by'   => auth()->id()
        ]);

        // Add creator as admin
        $channel->members()->attach(auth()->id(), [
            'role'         => 'admin',
            'last_read_at' => now(),
            'unread_count' => 0
        ]);

        // ✅ Public channel bana toh saare workspace members ko auto-add karo
        if ($channel->type === 'public') {
            foreach ($workspace->members as $member) {
                if (!$channel->isMember($member->id)) {
                    $channel->members()->attach($member->id, [
                        'role'         => 'member',
                        'last_read_at' => now(),
                        'unread_count' => 0
                    ]);
                }
            }
        }

        $channel->loadCount('members');
        $channel->is_member = true;

        return response()->json(['channel' => $channel], 201);
    }

    // Join channel
    public function join($channelId)
    {
        $channel   = Channel::findOrFail($channelId);
        $workspace = $channel->workspace;

        if ($channel->is_archived) {
            return response()->json(['error' => 'Channel is archived'], 403);
        }

        if (!$workspace->isMember(auth()->id())) {
            return response()->json(['error' => 'You must be workspace member to join'], 403);
        }

        if ($channel->type === 'private') {
            return response()->json(['error' => 'Private channel - invite only'], 403);
        }

        if ($channel->isMember(auth()->id())) {
            return response()->json(['success' => true, 'message' => 'Already a member']);
        }

        $channel->members()->attach(auth()->id(), [
            'role'         => 'member',
            'last_read_at' => now(),
            'unread_count' => 0
        ]);

        return response()->json(['success' => true]);
    }

    // Leave channel
    public function leave($channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isMember(auth()->id())) {
            return response()->json(['error' => 'Not a member'], 400);
        }

        $adminCount = $channel->members()->wherePivot('role', 'admin')->count();

        if ($adminCount === 1 && $channel->isAdmin(auth()->id())) {
            return response()->json([
                'error' => 'You are the last admin. Transfer ownership first'
            ], 403);
        }

        $channel->members()->detach(auth()->id());

        return response()->json(['success' => true]);
    }

    // Get messages
    public function getMessages($channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isMember(auth()->id())) {
            return response()->json(['error' => 'Not a member of this channel'], 403);
        }

        $messages = $channel->messages()
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($msg) {
                $msg->file_url = $msg->file_path
                    ? asset('storage/' . $msg->file_path)
                    : null;
                return $msg;
            });

        return response()->json($messages);
    }

    // Send message
    public function sendMessage(Request $request, $channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if ($channel->is_archived) {
            return response()->json(['error' => 'Channel is archived'], 403);
        }

        if (!$channel->isMember(auth()->id())) {
            return response()->json(['error' => 'Not a member of this channel'], 403);
        }

        $request->validate([
            'message' => 'nullable|string',
            'file'    => 'nullable|file|max:20480'
        ]);

        if (!$request->message && !$request->hasFile('file')) {
            return response()->json(['error' => 'Message or file is required'], 422);
        }

        $filePath    = null;
        $fileName    = null;
        $messageType = 'text';

        if ($request->hasFile('file')) {
            $file        = $request->file('file');
            $fileName    = $file->getClientOriginalName();
            $filePath    = $file->store('chat-files', 'public');
            $messageType = str_starts_with($file->getMimeType(), 'image/') ? 'image' : 'file';
        }

        $message = $channel->messages()->create([
            'sender_id'    => auth()->id(),
            'message'      => $request->message ?? '',
            'message_type' => $messageType,
            'file_path'    => $filePath,
            'file_name'    => $fileName,
            'is_read'      => false,
            'status'       => 'sent',
            'workspace_id' => $channel->workspace_id,
            'channel_id'   => $channelId
        ]);

        $message->load('sender');
        $message->file_url = $filePath ? asset('storage/' . $filePath) : null;

        // Update unread count for other members
        foreach ($channel->members as $member) {
            if ($member->id != auth()->id()) {
                $currentUnread = $member->pivot->unread_count ?? 0;
                $channel->members()->updateExistingPivot($member->id, [
                    'unread_count' => $currentUnread + 1
                ]);
            }
        }

        broadcast(new \App\Events\ChannelMessageSent($message))->toOthers();

        return response()->json($message, 201);
    }

    // Archive channel
    public function archive($channelId)
    {
        $channel   = Channel::findOrFail($channelId);
        $workspace = $channel->workspace;

        if (!$workspace->isAdmin(auth()->id()) && !$channel->isAdmin(auth()->id())) {
            return response()->json(['error' => 'Only admins can archive channels'], 403);
        }

        if ($channel->is_archived) {
            return response()->json(['error' => 'Channel already archived'], 400);
        }

        $channel->archive(auth()->id());

        return response()->json(['success' => true]);
    }

    // Unarchive channel
    public function unarchive($channelId)
    {
        $channel   = Channel::findOrFail($channelId);
        $workspace = $channel->workspace;

        if (!$workspace->isAdmin(auth()->id()) && !$channel->isAdmin(auth()->id())) {
            return response()->json(['error' => 'Only admins can unarchive channels'], 403);
        }

        if (!$channel->is_archived) {
            return response()->json(['error' => 'Channel is not archived'], 400);
        }

        $channel->unarchive();

        return response()->json(['success' => true]);
    }

    // Get channel members
    public function getMembers($channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isMember(auth()->id())) {
            return response()->json(['error' => 'Not a member of this channel'], 403);
        }

        $members = $channel->members()
            ->select('users.id', 'users.name', 'users.email', 'users.status', 'channel_members.role')
            ->get()
            ->map(function ($member) {
                $member->is_online = $member->status === 'online';
                return $member;
            });

        return response()->json(['members' => $members]);
    }

    // Add member
    public function addMember(Request $request, $channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isAdmin(auth()->id())) {
            return response()->json(['error' => 'Only channel admins can add members'], 403);
        }

        $request->validate(['user_id' => 'required|exists:users,id']);

        $userId = $request->user_id;

        if ($channel->isMember($userId)) {
            return response()->json(['error' => 'User already a member'], 400);
        }

        $channel->members()->attach($userId, [
            'role'         => 'member',
            'last_read_at' => now(),
            'unread_count' => 0
        ]);

        return response()->json(['success' => true]);
    }

    // Remove member
    public function removeMember($channelId, $userId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isAdmin(auth()->id())) {
            return response()->json(['error' => 'Only channel admins can remove members'], 403);
        }

        if ($channel->isAdmin($userId) && $channel->members()->wherePivot('role', 'admin')->count() === 1) {
            return response()->json(['error' => 'Cannot remove the last admin'], 403);
        }

        $channel->members()->detach($userId);

        return response()->json(['success' => true]);
    }

    // Mark channel as read
    public function markAsRead($channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isMember(auth()->id())) {
            return response()->json(['error' => 'Not a member'], 403);
        }

        $channel->markAsRead(auth()->id());

        return response()->json(['success' => true]);
    }

    // Show channel
    public function show($channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isMember(auth()->id()) && $channel->type !== 'public') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $channel->is_member     = $channel->isMember(auth()->id());
        $channel->members_count = $channel->members()->count();

        return response()->json(['channel' => $channel]);
    }

    // Update channel
    public function update(Request $request, $channelId)
    {
        $channel = Channel::findOrFail($channelId);

        if (!$channel->isAdmin(auth()->id())) {
            return response()->json(['error' => 'Only channel admins can update'], 403);
        }

        $request->validate([
            'name'        => 'sometimes|string|max:50',
            'description' => 'nullable|string',
            'topic'       => 'nullable|string'
        ]);

        if ($request->has('name')) {
            $channel->name = $request->name;
            $channel->slug = Str::slug($request->name) . '-' . Str::random(5);
        }

        if ($request->has('description')) $channel->description = $request->description;
        if ($request->has('topic'))       $channel->topic       = $request->topic;

        $channel->save();

        return response()->json(['channel' => $channel]);
    }

    // Delete channel
    public function delete($channelId)
    {
        $channel   = Channel::findOrFail($channelId);
        $workspace = $channel->workspace;

        if (!$workspace->isAdmin(auth()->id())) {
            return response()->json(['error' => 'Only workspace admins can delete channels'], 403);
        }

        $channel->delete();

        return response()->json(['success' => true]);
    }
}