<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\MessageReportController;

// =========================================
// PUBLIC ROUTES
// =========================================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// =========================================
// PROTECTED ROUTES
// =========================================
Route::middleware('auth:sanctum')->group(function () {

    // AUTH
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // =========================================
    // USERS
    // =========================================
    Route::get('/users',                  [UserController::class, 'getUsers']);
    Route::post('/update-status',         [UserController::class, 'updateStatus']);
    Route::post('/typing',                [UserController::class, 'typing']);
    Route::post('/update-activity',       [UserController::class, 'updateActivity']);
    Route::post('/users/{id}/mute',       [UserController::class, 'muteUser']);

    // BLOCK USERS
    Route::post('/users/block',           [UserController::class, 'blockUser']);
    Route::post('/users/unblock',         [UserController::class, 'unblockUser']);
    Route::get('/users/blocked',          [UserController::class, 'getBlockedUsers']);

    // DIRECT MESSAGES
    Route::get('/messages/{userId}',                        [MessageController::class, 'getMessages']);
    Route::post('/messages',                                [MessageController::class, 'sendMessage']);
    Route::put('/messages/{id}/delivered',                  [MessageController::class, 'markAsDelivered']);
    Route::put('/messages/{id}/seen',                       [MessageController::class, 'markAsSeen']);
    Route::put('/conversation/{userId}/seen',               [MessageController::class, 'markConversationAsSeen']);
    Route::post('/messages/{messageId}/react',              [MessageController::class, 'addReaction']);
    Route::put('/messages/{messageId}/edit',                [MessageController::class, 'editMessage']);
    Route::delete('/messages/{messageId}',                  [MessageController::class, 'deleteMessage']);
    Route::get('/messages/{messageId}/thread',              [MessageController::class, 'getThreadMessages']);
    Route::get('/messages/count',                           [MessageController::class, 'getMessagesCount']);
    Route::get('/messages/recent',                          [MessageController::class, 'getRecentMessages']);
    Route::post('/messages/{message}/report',               [MessageReportController::class, 'store']);

    // GROUPS
    Route::get('/groups',                                   [GroupController::class, 'index']);
    Route::get('/groups/{groupId}',                         [GroupController::class, 'show']);
    Route::post('/groups',                                  [GroupController::class, 'create']);
    Route::put('/groups/{groupId}',                         [GroupController::class, 'update']);
    Route::delete('/groups/{groupId}',                      [GroupController::class, 'delete']);
    Route::get('/groups/{groupId}/messages',                [MessageController::class, 'getGroupMessages']);
    Route::post('/groups/{groupId}/messages',               [MessageController::class, 'sendMessage']);
    Route::get('/groups/{groupId}/members',                 [GroupController::class, 'getMembers']);
    Route::post('/groups/{groupId}/members',                [GroupController::class, 'addMember']);
    Route::post('/groups/{groupId}/members/bulk',           [GroupController::class, 'bulkAddMembers']);
    Route::put('/groups/{groupId}/members/role',            [GroupController::class, 'updateMemberRole']);
    Route::delete('/groups/{groupId}/members',              [GroupController::class, 'removeMember']);
    Route::post('/groups/{groupId}/leave',                  [GroupController::class, 'leaveGroup']);
    Route::post('/groups/{groupId}/transfer-ownership',     [GroupController::class, 'transferOwnership']);

    // WORKSPACES
    Route::get('/workspaces',                                       [WorkspaceController::class, 'getWorkspaces']);
    Route::post('/workspaces',                                      [WorkspaceController::class, 'createWorkspace']);
    Route::post('/workspaces/{workspaceId}/switch',                 [WorkspaceController::class, 'switchWorkspace']);
    Route::get('/workspaces/{workspaceId}/members',                 [WorkspaceController::class, 'getMembers']);
    Route::post('/workspaces/{workspaceId}/invite',                 [WorkspaceController::class, 'inviteMember']);
    Route::delete('/workspaces/{workspaceId}/members/{userId}',     [WorkspaceController::class, 'removeMember']);
    Route::put('/workspaces/{workspaceId}/members/{userId}/role',   [WorkspaceController::class, 'updateMemberRole']);
    Route::put('/workspaces/{workspaceId}',                         [WorkspaceController::class, 'updateWorkspace']);
    Route::get('/workspaces/{workspaceId}/available-users',         [WorkspaceController::class, 'getAvailableUsers']);
    Route::post('/workspaces/{workspaceId}/add-members',            [WorkspaceController::class, 'addMembersDirectly']);

    // CHANNELS
    Route::get('/workspaces/{workspaceId}/channels',                [ChannelController::class, 'index']);
    Route::get('/workspaces/{workspaceId}/channels/public',         [ChannelController::class, 'getPublicChannels']);
    Route::post('/workspaces/{workspaceId}/channels',               [ChannelController::class, 'create']);
    Route::get('/channels/{channelId}',                             [ChannelController::class, 'show']);
    Route::put('/channels/{channelId}',                             [ChannelController::class, 'update']);
    Route::delete('/channels/{channelId}',                          [ChannelController::class, 'delete']);
    Route::post('/channels/{channelId}/join',                       [ChannelController::class, 'join']);
    Route::post('/channels/{channelId}/leave',                      [ChannelController::class, 'leave']);
    Route::get('/channels/{channelId}/members',                     [ChannelController::class, 'getMembers']);
    Route::post('/channels/{channelId}/members',                    [ChannelController::class, 'addMember']);
    Route::delete('/channels/{channelId}/members/{userId}',         [ChannelController::class, 'removeMember']);
    Route::get('/channels/{channelId}/messages',                    [ChannelController::class, 'getMessages']);
    Route::post('/channels/{channelId}/messages',                   [ChannelController::class, 'sendMessage']);
    Route::post('/channels/{channelId}/read',                       [ChannelController::class, 'markAsRead']);
    Route::post('/channels/{channelId}/archive',                    [ChannelController::class, 'archive']);
    Route::post('/channels/{channelId}/unarchive',                  [ChannelController::class, 'unarchive']);

    // SEARCH
    Route::get('/search/global',          [SearchController::class, 'globalSearch']);
    Route::post('/search/advanced',       [SearchController::class, 'advancedSearch']);

    // AI FEATURES
    Route::prefix('ai')->group(function () {
        Route::get('/summary',                    [AIController::class, 'getSummary']);
        Route::post('/quick-replies',             [AIController::class, 'getQuickReplies']);
        Route::get('/highlights',                 [AIController::class, 'getHighlights']);
        Route::get('/missed-discussion',          [AIController::class, 'getMissedDiscussion']);
        Route::get('/extract-tasks',              [AIController::class, 'extractTasks']);
        Route::get('/channel-recommendations',    [AIController::class, 'getChannelRecommendations']);
        Route::post('/suggest-reactions',         [AIController::class, 'suggestReactions']);
        Route::get('/conversation-starters',      [AIController::class, 'getConversationStarters']);
        Route::get('/smart-digest',               [AIController::class, 'getSmartDigest']);
        Route::post('/ask-bot',                   [AIController::class, 'askBot']);
        Route::get('/cleanup-suggestions',        [AIController::class, 'getCleanupSuggestions']);
        Route::post('/bot/reply',                 [AIController::class, 'botReply']);
    });
});

// =========================================
// ADMIN ROUTES
// =========================================
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

    // DASHBOARD & ANALYTICS
    Route::get('/dashboard',              [AdminController::class, 'dashboard']);
    Route::get('/analytics',              [AdminController::class, 'analytics']);
    Route::post('/update-activity',       [AdminController::class, 'updateActivity']);

    // USERS
    Route::get('/users',                  [AdminController::class, 'getUsers']);
    Route::get('/users/{id}',             [AdminController::class, 'getUserDetails']);
    Route::put('/users/{id}/role',        [AdminController::class, 'updateUserRole']);
    Route::put('/users/{id}/status',      [AdminController::class, 'updateUserStatus']);
    Route::post('/users/{id}/ban',        [AdminController::class, 'banUser']);
    Route::post('/users/{id}/unban',      [AdminController::class, 'unbanUser']);
    Route::post('/users/{id}/suspend',    [AdminController::class, 'suspendUser']);
    Route::post('/users/{id}/activate',   [AdminController::class, 'activateUser']);
    Route::delete('/users/{id}',          [AdminController::class, 'deleteUser']);

    // WORKSPACES
    Route::get('/workspaces/all',         [AdminController::class, 'getAllWorkspaces']);
    Route::get('/workspaces',             [AdminController::class, 'getWorkspaces']);
    Route::get('/workspaces/{id}',        [AdminController::class, 'getWorkspaceDetails']);
    Route::post('/workspaces',            [AdminController::class, 'createWorkspace']);
    Route::put('/workspaces/{id}',        [AdminController::class, 'updateWorkspace']);
    Route::delete('/workspaces/{id}',     [AdminController::class, 'deleteWorkspace']);

    // CHANNELS  ← YEH MISSING THA
    Route::get('/channels/all',           [AdminController::class, 'getAllChannels']);
    Route::delete('/channels/{id}',       [AdminController::class, 'deleteChannel']);

    // GROUPS  ← YEH BHI MISSING THA
    Route::get('/groups/all',             [AdminController::class, 'getAllGroups']);
    Route::post('/groups',                [AdminController::class, 'createGroup']);
    Route::delete('/groups/{id}',         [AdminController::class, 'deleteGroup']);

    // MESSAGES
    Route::get('/messages/reported',      [AdminController::class, 'getReportedMessages']);
    Route::get('/messages/all',           [AdminController::class, 'getAllMessages']);
    Route::delete('/messages/{id}',       [AdminController::class, 'deleteAnyMessage']);
    Route::post('/messages/{id}/moderate',[AdminController::class, 'moderateMessage']);

    // API USAGE & AUDIT LOGS
    Route::get('/api-usage',              [AdminController::class, 'getApiUsage']);
    Route::get('/audit-logs',             [AdminController::class, 'getAuditLogs']);
});