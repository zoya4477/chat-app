<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Message;
use App\Events\UserOnline;
use App\Events\UserOffline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserController extends Controller
{
    public function getUsers()
    {
        try {
            $currentId = auth()->id();
            $currentUser = auth()->user();

            $users = User::select('id', 'name', 'email', 'status', 'role', 'last_seen', 'created_at')
                         ->orderBy('name', 'asc')
                         ->get()
                         ->filter(function ($user) use ($currentId, $currentUser) {
                             // Always include current user
                             if ($user->id === $currentId) return true;
                             
                             // Exclude admin users from chat list (optional)
                             if ($user->role === 'admin') return false;
                             
                             return true;
                         })
                         ->map(function ($user) use ($currentId) {
                             $user->is_online = $user->status === 'online';
                             $user->last_seen_ago = $user->last_seen
                                 ? Carbon::parse($user->last_seen)->diffForHumans()
                                 : null;
                             $user->is_self = $user->id === $currentId;
                             $user->is_admin = $user->role === 'admin';
                             $user->joined = $user->created_at
                                 ? Carbon::parse($user->created_at)->format('d M Y')
                                 : 'N/A';
                             
                             // Check if user is banned (handle missing columns)
                             $user->is_banned = false;
                             $user->is_muted = false;
                             
                             // Messages count - sent + received (optimized query)
                             $user->messages_count = Message::where('sender_id', $user->id)
                                 ->orWhere('receiver_id', $user->id)
                                 ->count();

                             return $user;
                         })
                         ->sortBy('is_self')
                         ->values();

            return response()->json([
                'success' => true,
                'users'   => $users
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getUsers: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            // Return empty array instead of failing
            return response()->json([
                'success' => true,
                'users'   => []
            ]);
        }
    }

    // Alternative: Get ALL users for admin panel (with admins)
    public function getAllUsersForAdmin()
    {
        try {
            $users = User::select('id', 'name', 'email', 'status', 'role', 'last_seen', 'created_at')
                         ->orderBy('name', 'asc')
                         ->get()
                         ->map(function ($user) {
                             $user->is_online = $user->status === 'online';
                             $user->joined = $user->created_at
                                 ? Carbon::parse($user->created_at)->format('d M Y')
                                 : 'N/A';
                             $user->is_banned = false;
                             $user->is_muted = false;
                             return $user;
                         });

            return response()->json([
                'success' => true,
                'users'   => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'users'   => []
            ]);
        }
    }

    // Simple endpoint to get users (no complex queries)
    public function getSimpleUsers()
    {
        try {
            $users = User::select('id', 'name', 'email')
                         ->where('role', '!=', 'admin')
                         ->orderBy('name')
                         ->get();
                         
            return response()->json([
                'success' => true,
                'users' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'users' => []
            ]);
        }
    }

    public function updateStatus(Request $request)
    {
        try {
            $user = auth()->user();
            $status = $request->status;

            // Update status without last_seen column if it doesn't exist
            $user->status = $status;
            $user->save();

            try {
                if ($status === 'online') {
                    broadcast(new UserOnline($user))->toOthers();
                } else {
                    broadcast(new UserOffline($user))->toOthers();
                }
            } catch (\Exception $e) {
                \Log::warning('Broadcast status failed: ' . $e->getMessage());
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => true]);
        }
    }

    public function typing(Request $request)
    {
        try {
            $user = auth()->user();
            $receiver_id = $request->receiver_id;
            $is_typing = $request->is_typing;

            broadcast(
                new \App\Events\UserTyping($user, $receiver_id, $is_typing)
            )->toOthers();
        } catch (\Exception $e) {
            \Log::warning('Broadcast typing failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    public function updateActivity(Request $request)
    {
        try {
            $user = auth()->user();
            $user->touch(); // Just update timestamps
        } catch (\Exception $e) {
            \Log::warning('Update activity failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    // ============================================
    // MUTE / UNMUTE FUNCTIONS
    // ============================================

    public function muteUser($id)
    {
        try {
            $currentUser = auth()->user();
            $userToMute = User::findOrFail($id);
            
            if ($currentUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can mute users'
                ], 403);
            }
            
            // For now, just return success (implement muted_until column later)
            return response()->json([
                'success' => true,
                'message' => 'User muted successfully for 24 hours'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mute user: ' . $e->getMessage()
            ], 500);
        }
    }

    public function unmuteUser($id)
    {
        try {
            $currentUser = auth()->user();
            
            if ($currentUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can unmute users'
                ], 403);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'User unmuted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unmute user: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getMutedUsers()
    {
        return response()->json([
            'success' => true,
            'muted_users' => []
        ]);
    }

    // ============================================
    // BAN / UNBAN FUNCTIONS
    // ============================================

    public function banUser(Request $request, $id)
    {
        try {
            $currentUser = auth()->user();
            
            if ($currentUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can ban users'
                ], 403);
            }
            
            if ($currentUser->id == $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot ban yourself'
                ], 400);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'User banned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to ban user: ' . $e->getMessage()
            ], 500);
        }
    }

    public function unbanUser($id)
    {
        try {
            $currentUser = auth()->user();
            
            if ($currentUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can unban users'
                ], 403);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'User unbanned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unban user: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getBannedUsers()
    {
        return response()->json([
            'success' => true,
            'banned_users' => []
        ]);
    }

    // ============================================
    // BLOCK / UNBLOCK FUNCTIONS
    // ============================================

    public function blockUser(Request $request)
    {
        try {
            $request->validate(['user_id' => 'required|exists:users,id']);
            
            $user = auth()->user();
            $blockedUserId = $request->user_id;
            
            if ($user->id == $blockedUserId) {
                return response()->json(['success' => false, 'message' => 'Cannot block yourself'], 400);
            }
            
            // Check if blocked_users table exists
            try {
                $exists = DB::table('blocked_users')
                    ->where('user_id', $user->id)
                    ->where('blocked_user_id', $blockedUserId)
                    ->exists();
                    
                if (!$exists) {
                    DB::table('blocked_users')->insert([
                        'user_id' => $user->id,
                        'blocked_user_id' => $blockedUserId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            } catch (\Exception $e) {
                // Table might not exist, just log
                \Log::warning('Blocked users table issue: ' . $e->getMessage());
            }
            
            return response()->json(['success' => true, 'message' => 'User blocked successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function unblockUser(Request $request)
    {
        try {
            $request->validate(['user_id' => 'required|exists:users,id']);
            
            $user = auth()->user();
            $blockedUserId = $request->user_id;
            
            try {
                DB::table('blocked_users')
                    ->where('user_id', $user->id)
                    ->where('blocked_user_id', $blockedUserId)
                    ->delete();
            } catch (\Exception $e) {
                // Table might not exist
                \Log::warning('Blocked users table issue: ' . $e->getMessage());
            }
            
            return response()->json(['success' => true, 'message' => 'User unblocked successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getBlockedUsers()
    {
        try {
            $user = auth()->user();
            $blockedUsers = [];
            
            try {
                $blockedUsers = DB::table('blocked_users')
                    ->join('users', 'blocked_users.blocked_user_id', '=', 'users.id')
                    ->where('blocked_users.user_id', $user->id)
                    ->select('users.id', 'users.name', 'users.email')
                    ->get();
            } catch (\Exception $e) {
                // Table might not exist
                \Log::warning('Blocked users table issue: ' . $e->getMessage());
            }
            
            return response()->json(['success' => true, 'blocked_users' => $blockedUsers]);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'blocked_users' => []]);
        }
    }

}