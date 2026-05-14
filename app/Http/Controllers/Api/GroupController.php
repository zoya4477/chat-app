<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GroupController extends Controller
{
    // Get all groups current user belongs to
    public function index()
    {
        try {
            $userId = auth()->id();
            
            $groups = Group::whereHas('members', function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    })
                    ->with(['members' => function($q) {
                        $q->select('users.id', 'users.name', 'users.email', 'users.status')
                          ->withPivot('role', 'joined_at');
                    }, 'creator:id,name'])
                    ->withCount('members')
                    ->orderBy('updated_at', 'desc')
                    ->get()
                    ->map(function($group) use ($userId) {
                        // Add user's role in this group
                        $userMember = $group->members->firstWhere('id', $userId);
                        $group->user_role = $userMember ? $userMember->pivot->role : null;
                        $group->is_archived = (bool) $group->is_archived;
                        $group->is_member = $userMember !== null;
                        return $group;
                    });

            return response()->json(['success' => true, 'groups' => $groups]);
        } catch (\Exception $e) {
            Log::error('Get groups error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch groups'], 500);
        }
    }

    // Get single group details
    public function show($groupId)
    {
        try {
            $userId = auth()->id();
            
            $group = Group::with(['members' => function($q) {
                        $q->select('users.id', 'users.name', 'users.email', 'users.status', 'users.avatar')
                          ->withPivot('role', 'joined_at');
                    }, 'creator:id,name'])
                    ->withCount('members')
                    ->findOrFail($groupId);
            
            // Check if user is member
            $isMember = $group->members->contains('id', $userId);
            if (!$isMember && $group->is_archived) {
                return response()->json(['success' => false, 'message' => 'Group not found'], 404);
            }
            
            $userMember = $group->members->firstWhere('id', $userId);
            $group->user_role = $userMember ? $userMember->pivot->role : null;
            $group->is_archived = (bool) $group->is_archived;
            
            // Get recent messages
            $recentMessages = Message::where('group_id', $groupId)
                ->with('sender:id,name,email,status')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->reverse()
                ->values();
            
            return response()->json([
                'success' => true,
                'group' => $group,
                'recent_messages' => $recentMessages
            ]);
        } catch (\Exception $e) {
            Log::error('Get group details error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }
    }

    // Create group
    public function create(Request $request)
    {
        try {
            $request->validate([
                'name'        => 'required|string|max:100',
                'description' => 'nullable|string|max:500',
                'avatar'      => 'nullable|string|max:255',
                'member_ids'  => 'nullable|array',
                'member_ids.*'=> 'exists:users,id',
            ]);

            DB::beginTransaction();

            $group = Group::create([
                'name'         => $request->name,
                'description'  => $request->description,
                'avatar'       => $request->avatar,
                'created_by'   => auth()->id(),
                'is_archived'  => false,
                'member_count' => 1,
            ]);

            // Add creator as admin
            $group->members()->attach(auth()->id(), [
                'role' => 'admin',
                'joined_at' => Carbon::now()
            ]);

            // Add other members
            $memberIds = $request->member_ids ?? [];
            foreach ($memberIds as $memberId) {
                if ($memberId != auth()->id()) {
                    $group->members()->attach($memberId, [
                        'role' => 'member',
                        'joined_at' => Carbon::now()
                    ]);
                    $group->increment('member_count');
                }
            }

            DB::commit();

            $group->load(['members:id,name,email,status', 'creator:id,name']);
            $group->member_count = $group->members()->count();

            return response()->json(['success' => true, 'group' => $group], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Create group error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create group: ' . $e->getMessage()], 500);
        }
    }

    // Update group settings
    public function update(Request $request, $groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Check if user is admin or creator
            $member = $group->members()->where('user_id', $userId)->first();
            $isCreator = ($group->created_by == $userId);
            
            if ((!$member || $member->pivot->role !== 'admin') && !$isCreator) {
                return response()->json(['success' => false, 'message' => 'Only group admin can update settings'], 403);
            }
            
            $request->validate([
                'name'        => 'sometimes|string|max:100',
                'description' => 'nullable|string|max:500',
                'avatar'      => 'nullable|string|max:255',
            ]);
            
            if ($request->has('name')) $group->name = $request->name;
            if ($request->has('description')) $group->description = $request->description;
            if ($request->has('avatar')) $group->avatar = $request->avatar;
            
            $group->save();
            
            return response()->json(['success' => true, 'group' => $group, 'message' => 'Group settings updated']);
        } catch (\Exception $e) {
            Log::error('Update group error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update group'], 500);
        }
    }

    // Add member to group
    public function addMember(Request $request, $groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Check if user is admin or creator
            $member = $group->members()->where('user_id', $userId)->first();
            $isCreator = ($group->created_by == $userId);
            
            if ((!$member || $member->pivot->role !== 'admin') && !$isCreator) {
                return response()->json(['success' => false, 'message' => 'Only group admin can add members'], 403);
            }
            
            $request->validate(['user_id' => 'required|exists:users,id']);
            $newMemberId = $request->user_id;
            
            // Check if already member
            $exists = $group->members()->where('user_id', $newMemberId)->exists();
            if ($exists) {
                return response()->json(['success' => false, 'message' => 'User is already a member'], 400);
            }
            
            $group->members()->attach($newMemberId, [
                'role' => 'member',
                'joined_at' => Carbon::now()
            ]);
            $group->increment('member_count');
            
            return response()->json(['success' => true, 'message' => 'Member added successfully']);
        } catch (\Exception $e) {
            Log::error('Add member error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to add member'], 500);
        }
    }

    // Bulk add members
    public function bulkAddMembers(Request $request, $groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Check if user is admin or creator
            $member = $group->members()->where('user_id', $userId)->first();
            $isCreator = ($group->created_by == $userId);
            
            if ((!$member || $member->pivot->role !== 'admin') && !$isCreator) {
                return response()->json(['success' => false, 'message' => 'Only group admin can add members'], 403);
            }

            $request->validate([
                'member_ids' => 'required|array|min:1',
                'member_ids.*' => 'exists:users,id'
            ]);

            $addedMembers = [];
            $alreadyMembers = [];

            foreach ($request->member_ids as $memberId) {
                if ($memberId == $group->created_by) {
                    continue;
                }

                $exists = $group->members()->where('user_id', $memberId)->exists();
                if (!$exists) {
                    $group->members()->attach($memberId, [
                        'role' => 'member',
                        'joined_at' => Carbon::now()
                    ]);
                    $addedMembers[] = $memberId;
                    $group->increment('member_count');
                } else {
                    $alreadyMembers[] = $memberId;
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($addedMembers) . ' members added successfully',
                'added_members' => $addedMembers,
                'already_members' => $alreadyMembers,
                'members_count' => $group->member_count
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk add members error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to add members: ' . $e->getMessage()], 500);
        }
    }

    // Remove member
    public function removeMember(Request $request, $groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Check if user is admin or creator
            $member = $group->members()->where('user_id', $userId)->first();
            $isCreator = ($group->created_by == $userId);
            
            if ((!$member || $member->pivot->role !== 'admin') && !$isCreator) {
                return response()->json(['success' => false, 'message' => 'Only group admin can remove members'], 403);
            }
            
            $request->validate(['user_id' => 'required|exists:users,id']);
            $removeMemberId = $request->user_id;
            
            // Cannot remove creator
            if ($removeMemberId == $group->created_by) {
                return response()->json(['success' => false, 'message' => 'Cannot remove group creator'], 400);
            }
            
            $group->members()->detach($removeMemberId);
            $group->decrement('member_count');
            
            return response()->json(['success' => true, 'message' => 'Member removed successfully']);
        } catch (\Exception $e) {
            Log::error('Remove member error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to remove member'], 500);
        }
    }

    // Leave group
    public function leaveGroup($groupId)
    {
        try {
            $user = auth()->user();
            $group = Group::findOrFail($groupId);
            
            // Check if user is member
            $isMember = $group->members()->where('user_id', $user->id)->exists();
            if (!$isMember) {
                return response()->json(['success' => false, 'message' => 'You are not a member of this group'], 400);
            }
            
            // Check if user is the creator - they cannot leave, must delete or transfer ownership
            if ($group->created_by == $user->id) {
                return response()->json(['success' => false, 'message' => 'Group creator cannot leave. Delete the group or transfer ownership first.'], 400);
            }
            
            // Remove user from group
            $group->members()->detach($user->id);
            $group->decrement('member_count');
            
            return response()->json(['success' => true, 'message' => 'Left group successfully']);
        } catch (\Exception $e) {
            Log::error('Leave group error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to leave group'], 500);
        }
    }

    // Archive group
    public function archiveGroup($groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Check if user is admin or creator
            $member = $group->members()->where('user_id', $userId)->first();
            $isCreator = ($group->created_by == $userId);
            
            if ((!$member || $member->pivot->role !== 'admin') && !$isCreator) {
                return response()->json(['success' => false, 'message' => 'Only group admin can archive group'], 403);
            }
            
            $group->is_archived = true;
            $group->archived_at = Carbon::now();
            $group->archived_by = $userId;
            $group->save();
            
            return response()->json(['success' => true, 'message' => 'Group archived successfully']);
        } catch (\Exception $e) {
            Log::error('Archive group error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to archive group'], 500);
        }
    }

    // Restore archived group
    public function restoreGroup($groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Check if user is admin or creator
            $member = $group->members()->where('user_id', $userId)->first();
            $isCreator = ($group->created_by == $userId);
            
            if ((!$member || $member->pivot->role !== 'admin') && !$isCreator) {
                return response()->json(['success' => false, 'message' => 'Only group admin can restore group'], 403);
            }
            
            $group->is_archived = false;
            $group->archived_at = null;
            $group->archived_by = null;
            $group->save();
            
            return response()->json(['success' => true, 'message' => 'Group restored successfully']);
        } catch (\Exception $e) {
            Log::error('Restore group error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to restore group'], 500);
        }
    }

    // Transfer ownership
    public function transferOwnership(Request $request, $groupId)
    {
        try {
            $request->validate(['new_owner_id' => 'required|exists:users,id']);
            
            $user = auth()->user();
            $group = Group::findOrFail($groupId);
            
            // Check if current user is creator
            if ($group->created_by != $user->id) {
                return response()->json(['success' => false, 'message' => 'Only group creator can transfer ownership'], 403);
            }
            
            $newOwnerId = $request->new_owner_id;
            
            // Check if new owner is a member of the group
            $isMember = $group->members()->where('user_id', $newOwnerId)->exists();
            if (!$isMember) {
                return response()->json(['success' => false, 'message' => 'New owner must be a member of the group'], 400);
            }
            
            DB::beginTransaction();
            
            // Update group ownership
            $group->created_by = $newOwnerId;
            $group->save();
            
            // Make old creator a regular admin
            $group->members()->updateExistingPivot($user->id, ['role' => 'admin']);
            
            // Make new owner an admin
            $group->members()->updateExistingPivot($newOwnerId, ['role' => 'admin']);
            
            DB::commit();
            
            return response()->json(['success' => true, 'message' => 'Ownership transferred successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transfer ownership error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to transfer ownership'], 500);
        }
    }

    // Make admin / Remove admin
    public function updateMemberRole(Request $request, $groupId)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'role' => 'required|in:admin,member'
            ]);
            
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Check if current user is creator
            if ($group->created_by != $userId) {
                return response()->json(['success' => false, 'message' => 'Only group creator can change member roles'], 403);
            }
            
            $targetUserId = $request->user_id;
            
            // Cannot change creator's role
            if ($targetUserId == $group->created_by) {
                return response()->json(['success' => false, 'message' => 'Cannot change creator\'s role'], 400);
            }
            
            $group->members()->updateExistingPivot($targetUserId, ['role' => $request->role]);
            
            return response()->json(['success' => true, 'message' => 'Member role updated successfully']);
        } catch (\Exception $e) {
            Log::error('Update member role error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update member role'], 500);
        }
    }

    // Delete group (permanent)
    public function deleteGroup($groupId)
    {
        try {
            $group = Group::findOrFail($groupId);
            $userId = auth()->id();
            
            // Only creator can delete group
            if ($group->created_by !== $userId) {
                return response()->json(['success' => false, 'message' => 'Only group creator can delete the group'], 403);
            }
            
            DB::beginTransaction();
            
            // Delete all messages in the group
            Message::where('group_id', $groupId)->delete();
            
            // Detach all members
            $group->members()->detach();
            
            // Delete the group
            $group->delete();
            
            DB::commit();
            
            return response()->json(['success' => true, 'message' => 'Group deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete group error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete group'], 500);
        }
    }

    // Get archived groups
    public function getArchivedGroups()
    {
        try {
            $userId = auth()->id();
            
            $archivedGroups = Group::where('is_archived', true)
                ->whereHas('members', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->with(['members' => function($q) {
                    $q->select('users.id', 'users.name')
                      ->withPivot('role');
                }, 'creator:id,name'])
                ->withCount('members')
                ->get()
                ->map(function($group) use ($userId) {
                    $userMember = $group->members->firstWhere('id', $userId);
                    $group->user_role = $userMember ? $userMember->pivot->role : null;
                    return $group;
                });
            
            return response()->json(['success' => true, 'archived_groups' => $archivedGroups]);
        } catch (\Exception $e) {
            Log::error('Get archived groups error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch archived groups'], 500);
        }
    }

    // Search groups
    public function searchGroups(Request $request)
    {
        try {
            $request->validate(['query' => 'required|string|min:2']);
            $userId = auth()->id();
            
            $groups = Group::where('name', 'LIKE', "%{$request->query}%")
                ->whereHas('members', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->where('is_archived', false)
                ->with(['members' => function($q) {
                    $q->select('users.id', 'users.name');
                }])
                ->withCount('members')
                ->limit(20)
                ->get();
            
            return response()->json(['success' => true, 'groups' => $groups]);
        } catch (\Exception $e) {
            Log::error('Search groups error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to search groups'], 500);
        }
    }
}