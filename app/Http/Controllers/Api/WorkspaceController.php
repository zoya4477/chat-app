<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class WorkspaceController extends Controller
{
    // Get user's workspaces
    public function getWorkspaces()
    {
        try {
            $workspaces = auth()->user()->workspaces()
                        ->withPivot('role')
                        ->withCount('members') 
                        ->get();
            
            // Add current workspace
            $currentWorkspace = auth()->user()->currentWorkspace;
            
            return response()->json([
                'success' => true,
                'workspaces' => $workspaces,
                'current_workspace' => $currentWorkspace
            ]);
        } catch (\Exception $e) {
            Log::error('Get workspaces error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Create workspace
    public function createWorkspace(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string'
            ]);
            
            DB::beginTransaction();
            
            $workspace = Workspace::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . uniqid(),
                'description' => $request->description,
                'owner_id' => auth()->id(),
                'created_by' => auth()->id(),
                'settings' => json_encode([
                    'allow_guest_invites' => true,
                    'default_role' => 'member'
                ])
            ]);
            
            // Add owner as member
            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => auth()->id(),
                'role' => 'owner',
                'joined_at' => now()
            ]);
            
            // Set as current workspace
            auth()->user()->update(['current_workspace_id' => $workspace->id]);
            
            DB::commit();
            
            // Load members
            $workspace->load('members.user');
            
            return response()->json([
                'success' => true,
                'workspace' => $workspace,
                'message' => 'Workspace created successfully!'
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Create workspace error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Switch workspace
    public function switchWorkspace($workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Check if user is member
            $isMember = WorkspaceMember::where('workspace_id', $workspaceId)
                ->where('user_id', auth()->id())
                ->exists();
                
            if (!$isMember) {
                return response()->json(['error' => 'You are not a member of this workspace'], 403);
            }
            
            auth()->user()->update(['current_workspace_id' => $workspace->id]);
            
            // Update last active
            WorkspaceMember::where('workspace_id', $workspaceId)
                          ->where('user_id', auth()->id())
                          ->update(['last_active_at' => now()]);
            
            return response()->json([
                'success' => true,
                'workspace' => $workspace,
                'message' => 'Switched to ' . $workspace->name
            ]);
            
        } catch (\Exception $e) {
            Log::error('Switch workspace error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Get workspace members
    public function getMembers($workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            $members = WorkspaceMember::where('workspace_id', $workspaceId)
                        ->with('user')
                        ->orderByRaw("CASE 
                            WHEN role = 'owner' THEN 1 
                            ELSE 2 END")
                        ->get();
            
            return response()->json([
                'success' => true,
                'members' => $members
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get members error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Get available users (not in workspace)
    public function getAvailableUsers($workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Get existing member user IDs
            $existingUserIds = WorkspaceMember::where('workspace_id', $workspaceId)
                                ->pluck('user_id')
                                ->toArray();
            
            // Get all users except workspace members and current user
            $availableUsers = User::whereNotIn('id', $existingUserIds)
                ->where('id', '!=', auth()->id())
                ->select('id', 'name', 'email')
                ->get();
            
            return response()->json([
                'success' => true,
                'users' => $availableUsers
            ]);
        } catch (\Exception $e) {
            Log::error('Get available users error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Add members directly to workspace (only owner can do this)
    public function addMembersDirectly(Request $request, $workspaceId)
    {
        try {
            $request->validate([
                'user_ids' => 'required|array',
                'user_ids.*' => 'exists:users,id'
            ]);
            
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Check if current user is the workspace owner
            if ($workspace->owner_id !== auth()->id()) {
                return response()->json(['error' => 'Only workspace owner can add members'], 403);
            }
            
            $addedCount = 0;
            $addedUsers = [];
            
            foreach ($request->user_ids as $userId) {
                // Check if user is already a member
                $exists = WorkspaceMember::where('workspace_id', $workspaceId)
                    ->where('user_id', $userId)
                    ->exists();
                    
                if (!$exists) {
                    $member = WorkspaceMember::create([
                        'workspace_id' => $workspaceId,
                        'user_id' => $userId,
                        'role' => 'member',
                        'joined_at' => now()
                    ]);
                    $addedCount++;
                    $addedUsers[] = $member->load('user');
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => $addedCount . ' member(s) added successfully',
                'added_count' => $addedCount,
                'members' => $addedUsers
            ]);
            
        } catch (\Exception $e) {
            Log::error('Add members error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Invite member by email (only owner can do this)
    public function inviteMember(Request $request, $workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Check if current user is the workspace owner
            if ($workspace->owner_id !== auth()->id()) {
                return response()->json(['error' => 'Only workspace owner can invite members'], 403);
            }
            
            $request->validate([
                'email' => 'required|email'
            ]);
            
            // Check if user exists
            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                // TODO: Send email invitation for non-existing users
                return response()->json([
                    'success' => true,
                    'message' => 'Invitation will be sent when user registers',
                    'invited' => false
                ]);
            }
            
            // Check if already member
            $exists = WorkspaceMember::where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->exists();
                
            if ($exists) {
                return response()->json(['error' => 'User is already a member'], 400);
            }
            
            // Add member
            $member = WorkspaceMember::create([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'role' => 'member',
                'invited_at' => now(),
                'joined_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Member invited successfully',
                'member' => $member->load('user')
            ]);
            
        } catch (\Exception $e) {
            Log::error('Invite member error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Remove member from workspace (only owner can do this)
    public function removeMember($workspaceId, $userId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Check if current user is the workspace owner
            if ($workspace->owner_id !== auth()->id()) {
                return response()->json(['error' => 'Only workspace owner can remove members'], 403);
            }
            
            // Cannot remove owner themselves
            if ($workspace->owner_id == $userId) {
                return response()->json(['error' => 'Cannot remove workspace owner'], 400);
            }
            
            // Cannot remove self if you're the owner (you'd need to transfer ownership first)
            if ($userId == auth()->id()) {
                return response()->json(['error' => 'Owner cannot leave workspace. Transfer ownership first.'], 400);
            }
            
            WorkspaceMember::where('workspace_id', $workspaceId)
                          ->where('user_id', $userId)
                          ->delete();
            
            // If removed user had this as current workspace, clear it
            User::where('id', $userId)
                ->where('current_workspace_id', $workspaceId)
                ->update(['current_workspace_id' => null]);
            
            return response()->json([
                'success' => true,
                'message' => 'Member removed successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Remove member error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Leave workspace (for regular members)
    public function leaveWorkspace($workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Owner cannot leave
            if ($workspace->owner_id == auth()->id()) {
                return response()->json(['error' => 'Owner cannot leave workspace. Delete or transfer ownership first.'], 400);
            }
            
            WorkspaceMember::where('workspace_id', $workspaceId)
                          ->where('user_id', auth()->id())
                          ->delete();
            
            // Clear current workspace if it was this one
            if (auth()->user()->current_workspace_id == $workspaceId) {
                $newWorkspace = WorkspaceMember::where('user_id', auth()->id())->first();
                auth()->user()->update(['current_workspace_id' => $newWorkspace?->workspace_id]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Left workspace successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Leave workspace error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Transfer ownership (only current owner can do this)
    public function transferOwnership(Request $request, $workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Only current owner can transfer
            if ($workspace->owner_id != auth()->id()) {
                return response()->json(['error' => 'Only workspace owner can transfer ownership'], 403);
            }
            
            $request->validate([
                'new_owner_id' => 'required|exists:users,id'
            ]);
            
            $newOwnerId = $request->new_owner_id;
            
            // Check if new owner is a member
            $isMember = WorkspaceMember::where('workspace_id', $workspaceId)
                ->where('user_id', $newOwnerId)
                ->exists();
                
            if (!$isMember) {
                return response()->json(['error' => 'New owner must be a workspace member'], 400);
            }
            
            DB::beginTransaction();
            
            // Update workspace owner
            $workspace->owner_id = $newOwnerId;
            $workspace->save();
            
            // Update roles: old owner becomes member, new owner becomes owner
            WorkspaceMember::where('workspace_id', $workspaceId)
                ->where('user_id', auth()->id())
                ->update(['role' => 'member']);
                
            WorkspaceMember::where('workspace_id', $workspaceId)
                ->where('user_id', $newOwnerId)
                ->update(['role' => 'owner']);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Ownership transferred successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transfer ownership error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Update workspace settings (only owner can do this)
    public function updateWorkspace(Request $request, $workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Check if user is the workspace owner
            if ($workspace->owner_id !== auth()->id()) {
                return response()->json(['error' => 'Only workspace owner can update workspace'], 403);
            }
            
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'logo' => 'nullable|image|max:2048'
            ]);
            
            if ($request->hasFile('logo')) {
                $logo = $request->file('logo');
                $logoPath = $logo->store('workspace-logos', 'public');
                $workspace->logo = $logoPath;
            }
            
            if ($request->has('name')) {
                $workspace->name = $request->name;
                $workspace->slug = Str::slug($request->name) . '-' . uniqid();
            }
            
            if ($request->has('description')) {
                $workspace->description = $request->description;
            }
            
            $workspace->save();
            
            return response()->json([
                'success' => true,
                'workspace' => $workspace,
                'message' => 'Workspace updated successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Update workspace error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Get workspace details
    public function getWorkspace($workspaceId)
    {
        try {
            $workspace = Workspace::with(['members.user', 'channels'])
                ->findOrFail($workspaceId);
            
            // Check if user is member
            $isMember = WorkspaceMember::where('workspace_id', $workspaceId)
                ->where('user_id', auth()->id())
                ->exists();
                
            if (!$isMember) {
                return response()->json(['error' => 'You are not a member of this workspace'], 403);
            }
            
            return response()->json([
                'success' => true,
                'workspace' => $workspace
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get workspace error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // Delete workspace (only owner can do this)
    public function deleteWorkspace($workspaceId)
    {
        try {
            $workspace = Workspace::findOrFail($workspaceId);
            
            // Only owner can delete
            if ($workspace->owner_id != auth()->id()) {
                return response()->json(['error' => 'Only workspace owner can delete the workspace'], 403);
            }
            
            DB::beginTransaction();
            
            // Delete all members first
            WorkspaceMember::where('workspace_id', $workspaceId)->delete();
            
            // Delete channels
            $workspace->channels()->delete();
            
            // Delete workspace
            $workspace->delete();
            
            // Clear current workspace for affected users
            User::where('current_workspace_id', $workspaceId)
                ->update(['current_workspace_id' => null]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Workspace deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete workspace error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}