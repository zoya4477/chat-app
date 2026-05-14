<?php
// app/Http/Controllers/Api/SearchController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Global search across messages and users only
     */
    public function globalSearch(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1|max:100',
            'workspace_id' => 'required|exists:workspaces,id',
            'type' => 'nullable|in:messages,users,all'
        ]);

        $query = $request->query;
        $workspaceId = $request->workspace_id;
        $type = $request->type ?? 'all';
        $user = auth()->user();

        $results = [];

        if ($type === 'all' || $type === 'messages') {
            $results['messages'] = $this->searchMessages($query, $workspaceId, $user->id);
        }

        if ($type === 'all' || $type === 'users') {
            $results['users'] = $this->searchUsers($query, $workspaceId, $user->id);
        }

        return response()->json([
            'success' => true,
            'query' => $query,
            'results' => $results,
            'total' => (count($results['messages'] ?? []) + count($results['users'] ?? []))
        ]);
    }

    /**
     * Simple search - just messages and users
     */
    public function simpleSearch(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2|max:100',
            'workspace_id' => 'required|exists:workspaces,id'
        ]);

        $query = $request->query;
        $workspaceId = $request->workspace_id;
        $user = auth()->user();

        // Search messages
        $messages = $this->searchMessages($query, $workspaceId, $user->id);
        
        // Search users
        $users = $this->searchUsers($query, $workspaceId, $user->id);

        return response()->json([
            'success' => true,
            'query' => $query,
            'messages' => $messages,
            'users' => $users,
            'total' => (count($messages) + count($users))
        ]);
    }

    /**
     * Search messages
     */
    private function searchMessages($query, $workspaceId, $userId)
    {
        try {
            $messages = Message::with(['sender', 'channel', 'group'])
                ->where(function($q) use ($query) {
                    // Search in message text
                    $q->where('message', 'LIKE', "%{$query}%");
                })
                ->where(function($q) use ($userId) {
                    // User can access messages where:
                    $q->where(function($q2) use ($userId) {
                        // 1. Direct messages to/from user
                        $q2->whereNotNull('receiver_id')
                           ->where(function($q3) use ($userId) {
                               $q3->where('sender_id', $userId)
                                  ->orWhere('receiver_id', $userId);
                           });
                    })
                    // 2. Group messages where user is member
                    ->orWhere(function($q2) use ($userId) {
                        $q2->whereNotNull('group_id')
                           ->whereHas('group.members', function($q3) use ($userId) {
                               $q3->where('user_id', $userId);
                           });
                    })
                    // 3. Channel messages where user is member
                    ->orWhere(function($q2) use ($userId) {
                        $q2->whereNotNull('channel_id')
                           ->whereHas('channel.members', function($q3) use ($userId) {
                               $q3->where('user_id', $userId);
                           });
                    });
                });
            
            // Filter by workspace if channel or group exists
            if ($workspaceId) {
                $messages->where(function($q) use ($workspaceId) {
                    $q->whereHas('channel', function($q2) use ($workspaceId) {
                        $q2->where('workspace_id', $workspaceId);
                    })->orWhereHas('group', function($q2) use ($workspaceId) {
                        $q2->where('workspace_id', $workspaceId);
                    })->orWhereNull('channel_id')->orWhereNull('group_id');
                });
            }
            
            $messages = $messages->orderBy('created_at', 'desc')
                ->limit(50)
                ->get();
            
            // Highlight keywords in messages
            foreach ($messages as $message) {
                $message->highlighted_message = $this->highlightKeyword($message->message, $query);
                $message->context = $this->getMessageContext($message->message, $query);
            }
            
            return $messages;
            
        } catch (\Exception $e) {
            \Log::error('Message search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search users
     */
    private function searchUsers($query, $workspaceId, $userId)
    {
        try {
            $users = User::where('id', '!=', $userId)
                ->where(function($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('email', 'LIKE', "%{$query}%");
                });
            
            // Filter by workspace if provided
            if ($workspaceId) {
                $users->whereHas('workspaces', function($q) use ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                });
            }
            
            $users = $users->limit(20)->get();
            
            // Add online status
            foreach ($users as $user) {
                $user->is_online = $this->isUserOnline($user->id);
                $user->highlighted_name = $this->highlightKeyword($user->name, $query);
            }
            
            return $users;
            
        } catch (\Exception $e) {
            \Log::error('User search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if user is online
     */
    private function isUserOnline($userId)
    {
        // Check if user has been active in last 5 minutes
        $cacheKey = "user_{$userId}_online";
        if (\Cache::has($cacheKey)) {
            return \Cache::get($cacheKey);
        }
        
        // Fallback to database check
        $user = User::find($userId);
        if ($user && $user->last_activity) {
            $isOnline = $user->last_activity->gt(now()->subMinutes(5));
            \Cache::put($cacheKey, $isOnline, 1);
            return $isOnline;
        }
        
        return false;
    }

    /**
     * Highlight keywords in text
     */
    private function highlightKeyword($text, $keyword)
    {
        if (empty($text) || empty($keyword)) return $text;
        
        try {
            $pattern = '/(' . preg_quote($keyword, '/') . ')/iu';
            return preg_replace($pattern, '<mark class="search-highlight" style="background-color: #ffeb3b; color: #000; padding: 0 2px; border-radius: 2px; font-weight: bold;">$1</mark>', $text);
        } catch (\Exception $e) {
            return $text;
        }
    }

    /**
     * Get surrounding context for message
     */
    private function getMessageContext($message, $keyword)
    {
        if (empty($message) || empty($keyword)) return $message;
        
        $position = stripos($message, $keyword);
        if ($position === false) return $message;
        
        $start = max(0, $position - 50);
        $end = min(strlen($message), $position + strlen($keyword) + 50);
        
        $context = substr($message, $start, $end - $start);
        
        if ($start > 0) $context = '...' . $context;
        if ($end < strlen($message)) $context = $context . '...';
        
        return $this->highlightKeyword($context, $keyword);
    }

    /**
     * Get search suggestions (for autocomplete)
     */
    public function suggestions(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1',
            'workspace_id' => 'required|exists:workspaces,id'
        ]);

        $query = $request->query;
        $workspaceId = $request->workspace_id;
        $userId = auth()->id();

        $suggestions = [];

        // User suggestions
        $users = User::where('id', '!=', $userId)
            ->where('name', 'LIKE', "%{$query}%")
            ->whereHas('workspaces', function($q) use ($workspaceId) {
                $q->where('workspace_id', $workspaceId);
            })
            ->limit(5)
            ->get()
            ->map(function($user) {
                return [
                    'type' => 'user',
                    'icon' => '👤',
                    'label' => $user->name,
                    'sublabel' => $user->email,
                    'data' => $user
                ];
            });

        $suggestions = array_merge($suggestions, $users->toArray());

        return response()->json([
            'success' => true,
            'suggestions' => $suggestions
        ]);
    }
}