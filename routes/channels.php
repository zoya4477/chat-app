<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Group;
use App\Models\Workspace;
use App\Models\Channel;

// ✅ Direct messages - private channel
Broadcast::channel('chat.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// ✅ Group messages - presence channel
Broadcast::channel('group.{groupId}', function ($user, $groupId) {
    $group = Group::find($groupId);
    if (!$group) return false;

    $isMember  = $group->members()->where('user_id', $user->id)->exists();
    $isCreator = (int) $group->created_by === (int) $user->id;

    if (!$isMember && !$isCreator) return false;

    // Return array for presence channels (echo.join)
    return ['id' => $user->id, 'name' => $user->name];
});

// ✅ Workspace presence channel - for workspace members
Broadcast::channel('workspace.{workspaceId}', function ($user, $workspaceId) {
    $workspace = Workspace::find($workspaceId);
    if (!$workspace) return false;
    
    $isMember = $workspace->members()->where('user_id', $user->id)->exists();
    $isOwner = (int) $workspace->owner_id === (int) $user->id;
    
    if (!$isMember && !$isOwner) return false;
    
    // Return array for presence channel
    return [
        'id' => $user->id, 
        'name' => $user->name,
        'email' => $user->email,
        'avatar' => $user->avatar ?? null
    ];
});

// ✅ Channel presence channel - for channel members
Broadcast::channel('channel.{channelId}', function ($user, $channelId) {
    $channel = Channel::find($channelId);
    if (!$channel) return false;
    
    $isMember = $channel->members()->where('user_id', $user->id)->exists();
    
    if (!$isMember) return false;
    
    // Return array for presence channel
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email
    ];
});

// ✅ Online users presence channel (global)
Broadcast::channel('online', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'status' => $user->status ?? 'online'
    ];
});

// ✅ Workspace members presence channel (workspace-specific online users)
Broadcast::channel('workspace.{workspaceId}.members', function ($user, $workspaceId) {
    $workspace = Workspace::find($workspaceId);
    if (!$workspace) return false;
    
    $isMember = $workspace->members()->where('user_id', $user->id)->exists();
    $isOwner = (int) $workspace->owner_id === (int) $user->id;
    
    if (!$isMember && !$isOwner) return false;
    
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email
    ];
});