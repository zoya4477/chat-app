<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Message;
use App\Models\Channel;
use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class AdminController extends Controller
{
    private function columnExists($table, $column)
    {
        return Schema::hasColumn($table, $column);
    }

    private function tableExists($table)
    {
        return Schema::hasTable($table);
    }

    private function logAdminAction($action, $resourceType, $resourceId = null, $details = null)
    {
        try {
            if ($this->tableExists('admin_logs')) {
                DB::table('admin_logs')->insert([
                    'admin_id'      => auth()->id(),
                    'action'        => $action,
                    'resource_type' => $resourceType,
                    'resource_id'   => $resourceId,
                    'details'       => json_encode($details),
                    'ip_address'    => request()->ip(),
                    'user_agent'    => request()->userAgent(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to log admin action: ' . $e->getMessage());
        }
    }

    public function updateActivity(Request $request)
    {
        try {
            $user = auth()->user();
            $user->last_seen = Carbon::now();
            $user->status = 'online';
            $user->save();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false], 200);
        }
    }

    public function dashboard()
    {
        try {
            $totalUsers = User::count();
            $totalMessages = Message::count();
            $messagesToday = Message::whereDate('created_at', Carbon::today())->count();
            $totalWorkspaces = Workspace::count();
            $totalChannels = Channel::count();
            $totalGroups = Group::count();
            $activeUsers = User::where('status', 'online')->count();
            $dailyActiveUsers = User::where(function ($q) {
                $q->where('status', 'online')->orWhere('last_seen', '>=', Carbon::now()->subDay());
            })->count();
            $reportedMessages = 0;
            if ($this->columnExists('messages', 'is_reported')) {
                $reportedMessages = Message::where('is_reported', true)->whereNull('moderated_at')->count();
            }
            $messagesPerDay = Message::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get();
            $userGrowth = User::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get();

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_users' => $totalUsers,
                    'active_users' => $activeUsers,
                    'daily_active_users' => $dailyActiveUsers,
                    'total_messages' => $totalMessages,
                    'messages_today' => $messagesToday,
                    'total_workspaces' => $totalWorkspaces,
                    'total_channels' => $totalChannels,
                    'total_groups' => $totalGroups,
                    'reported_messages' => $reportedMessages,
                    'messages_per_day' => $messagesPerDay,
                    'user_growth' => $userGrowth,
                ],
                'activities' => [
                    'recent_users' => User::latest()->limit(5)->get(['id', 'name', 'email', 'status', 'created_at']),
                    'recent_messages' => Message::with('sender')->latest()->limit(5)->get(),
                    'recent_workspaces' => Workspace::latest()->limit(5)->get(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function analytics()
    {
        try {
            $activeUsers = User::select('id', 'name', 'email', 'status', 'last_seen', 'updated_at')
                ->withCount(['messages as messages_count' => function ($q) {
                    $q->where('created_at', '>=', Carbon::now()->subDays(30));
                }])
                ->orderBy('messages_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($u) {
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'messages_count' => $u->messages_count,
                        'last_active' => $u->last_seen ?? $u->updated_at,
                        'last_seen' => $u->last_seen,
                        'status' => $u->status ?? 'offline',
                    ];
                });

            $popularChannels = Channel::select('id', 'name', 'description')
                ->withCount('messages')
                ->withCount('members')
                ->orderBy('messages_count', 'desc')
                ->limit(10)
                ->get();

            $messagesPerDay = Message::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get();

            $userGrowth = User::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get();

            $dailyActive = User::where(function ($q) {
                $q->where('status', 'online')->orWhere('last_seen', '>=', Carbon::now()->subDay());
            })->count();

            $messagesToday = Message::whereDate('created_at', Carbon::today())->count();

            return response()->json([
                'success' => true,
                'active_users' => $activeUsers,
                'popular_channels' => $popularChannels,
                'messages_per_day' => $messagesPerDay,
                'user_growth' => $userGrowth,
                'daily_active' => $dailyActive,
                'messages_today' => $messagesToday,
            ]);
        } catch (\Exception $e) {
            Log::error('Analytics Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getUsers(Request $request)
    {
        try {
            $query = User::select('id', 'name', 'email', 'role', 'status', 'last_seen', 'created_at', 'updated_at');
            if ($this->columnExists('users', 'is_banned')) $query->addSelect('is_banned');
            if ($this->columnExists('users', 'ban_reason')) $query->addSelect('ban_reason');
            if ($this->columnExists('users', 'is_active')) $query->addSelect('is_active');

            if ($request->search) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'LIKE', "%{$request->search}%")->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            }
            if ($request->role) $query->where('role', $request->role);
            if ($request->status === 'banned' && $this->columnExists('users', 'is_banned')) $query->where('is_banned', true);
            elseif ($request->status === 'active') $query->where('status', 'online');

            $users = $query->latest()->get()->map(function ($user) {
                $user->is_online = $user->status === 'online';
                $user->joined = $user->created_at ? $user->created_at->format('d M Y') : 'N/A';
                return $user;
            });

            return response()->json(['success' => true, 'users' => $users]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getUserDetails($id)
    {
        try {
            $user = User::select('id', 'name', 'email', 'role', 'status', 'last_seen', 'created_at', 'updated_at')->findOrFail($id);
            $user->messages_sent = Message::where('sender_id', $id)->count();
            $user->messages_received = Message::where('receiver_id', $id)->count();
            return response()->json(['success' => true, 'user' => $user]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function updateUserRole(Request $request, $id)
    {
        try {
            $request->validate(['role' => 'required|in:user,moderator,admin']);
            $user = User::findOrFail($id);
            $user->role = $request->role;
            $user->save();
            $this->logAdminAction('UPDATE_ROLE', 'user', $id, ['user_name' => $user->name, 'new_role' => $request->role]);
            return response()->json(['success' => true, 'message' => 'Role updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function banUser(Request $request, $id)
    {
        try {
            $request->validate(['reason' => 'required|string']);
            $user = User::findOrFail($id);
            if ($this->columnExists('users', 'is_banned')) $user->is_banned = true;
            if ($this->columnExists('users', 'ban_reason')) $user->ban_reason = $request->reason;
            if ($this->columnExists('users', 'banned_at')) $user->banned_at = Carbon::now();
            if ($this->columnExists('users', 'is_active')) $user->is_active = false;
            $user->save();
            $this->logAdminAction('BAN_USER', 'user', $id, ['user_name' => $user->name, 'reason' => $request->reason]);
            return response()->json(['success' => true, 'message' => 'User banned successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function unbanUser($id)
    {
        try {
            $user = User::findOrFail($id);
            if ($this->columnExists('users', 'is_banned')) $user->is_banned = false;
            if ($this->columnExists('users', 'ban_reason')) $user->ban_reason = null;
            if ($this->columnExists('users', 'banned_at')) $user->banned_at = null;
            if ($this->columnExists('users', 'is_active')) $user->is_active = true;
            $user->save();
            $this->logAdminAction('UNBAN_USER', 'user', $id, ['user_name' => $user->name]);
            return response()->json(['success' => true, 'message' => 'User unbanned successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getAllWorkspaces(Request $request)
    {
        try {
            $query = Workspace::with('owner')->withCount(['members', 'channels']);
            if ($request->search) $query->where('name', 'LIKE', "%{$request->search}%");
            $workspaces = $query->latest()->paginate(20);
            return response()->json(['success' => true, 'workspaces' => $workspaces]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getAllMessages(Request $request)
    {
        try {
            $query = Message::with(['sender', 'channel', 'group']);
            if ($request->search) $query->where('message', 'LIKE', "%{$request->search}%");
            if ($request->workspace_id) $query->where('workspace_id', $request->workspace_id);
            $messages = $query->latest()->paginate(20);
            return response()->json(['success' => true, 'messages' => $messages]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getReportedMessages(Request $request)
    {
        try {
            if (!$this->columnExists('messages', 'is_reported')) {
                return response()->json(['success' => true, 'messages' => []]);
            }
            $messages = Message::with(['sender', 'channel', 'group'])->where('is_reported', true)->latest()->paginate(20);
            return response()->json(['success' => true, 'messages' => $messages]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function moderateMessage(Request $request, $id)
    {
        try {
            $request->validate(['action' => 'required|in:approve,remove,warn']);
            $message = Message::findOrFail($id);
            if ($request->action === 'remove') {
                $message->delete();
            } elseif ($request->action === 'approve') {
                if ($this->columnExists('messages', 'is_reported')) $message->is_reported = false;
                if ($this->columnExists('messages', 'moderated_at')) $message->moderated_at = now();
                if ($this->columnExists('messages', 'moderated_by')) $message->moderated_by = auth()->id();
                $message->save();
            }
            $this->logAdminAction('MODERATE_MESSAGE', 'message', $id, ['action' => $request->action]);
            return response()->json(['success' => true, 'message' => "Message {$request->action}d successfully"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteAnyMessage($id)
    {
        try {
            $message = Message::findOrFail($id);
            $this->logAdminAction('DELETE_MESSAGE', 'message', $id, ['content' => substr($message->message ?? '', 0, 100)]);
            $message->delete();
            return response()->json(['success' => true, 'message' => 'Message deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getApiUsage(Request $request)
    {
        try {
            $topUsers = User::select('id', 'name', 'email')
                ->withCount('messages as calls')
                                ->orderBy('calls', 'desc')
                ->limit(10)
                ->get()
                ->map(fn($u) => ['name' => $u->name, 'email' => $u->email, 'calls' => $u->calls]);

            $topEndpoints = [];
            if ($this->tableExists('api_usage_logs')) {
                $topEndpoints = DB::table('api_usage_logs')
                    ->select('endpoint', 'method', DB::raw('COUNT(*) as calls'), DB::raw('AVG(response_time) as avg_response'))
                    ->groupBy('endpoint', 'method')
                    ->orderBy('calls', 'desc')
                    ->limit(10)
                    ->get();
            }

            return response()->json([
                'success' => true,
                'top_users' => $topUsers,
                'top_endpoints' => $topEndpoints,
                'total_calls' => Message::count(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getAuditLogs(Request $request)
    {
        try {
            if ($this->tableExists('admin_logs')) {
                $logs = DB::table('admin_logs')
                    ->join('users', 'admin_logs.admin_id', '=', 'users.id')
                    ->select('admin_logs.*', 'users.name as admin_name', 'users.email as admin_email')
                    ->orderBy('admin_logs.created_at', 'desc')
                    ->paginate(50);
                return response()->json(['success' => true, 'logs' => $logs]);
            }

            $logs = User::where('role', 'admin')->orWhere('role', 'moderator')
                ->latest('updated_at')->limit(50)->get(['id', 'name', 'email', 'role', 'updated_at'])
                ->map(fn($u) => [
                    'id' => $u->id,
                    'admin_name' => $u->name,
                    'action' => 'Last active',
                    'resource' => 'session',
                    'details' => $u->email,
                    'created_at' => $u->updated_at,
                ]);

            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // Additional methods for channels and groups
    public function getAllChannels(Request $request)
    {
        try {
            $query = Channel::with('workspace')->withCount(['members', 'messages']);
            if ($request->search) $query->where('name', 'LIKE', "%{$request->search}%");
            $channels = $query->latest()->paginate(20);
            return response()->json(['success' => true, 'channels' => $channels]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getAllGroups(Request $request)
    {
        try {
            $query = Group::with('creator')->withCount(['members', 'messages']);
            if ($request->search) $query->where('name', 'LIKE', "%{$request->search}%");
            $groups = $query->latest()->paginate(20);
            return response()->json(['success' => true, 'groups' => $groups]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function createGroup(Request $request)
    {
        try {
            $request->validate(['name' => 'required|string|max:255']);
            $group = Group::create([
                'name' => $request->name,
                'description' => $request->description,
                'created_by' => auth()->id(),
            ]);
            $this->logAdminAction('CREATE_GROUP', 'group', $group->id, ['name' => $group->name]);
            return response()->json(['success' => true, 'group' => $group]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteGroup($id)
    {
        try {
            $group = Group::findOrFail($id);
            $this->logAdminAction('DELETE_GROUP', 'group', $id, ['name' => $group->name]);
            $group->delete();
            return response()->json(['success' => true, 'message' => 'Group deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteChannel($id)
    {
        try {
            $channel = Channel::findOrFail($id);
            $this->logAdminAction('DELETE_CHANNEL', 'channel', $id, ['name' => $channel->name]);
            $channel->delete();
            return response()->json(['success' => true, 'message' => 'Channel deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}