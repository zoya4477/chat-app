<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'device_token', 'last_seen', 'status', 'role'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
    ];

    // =========================================
    // STATUS HELPERS
    // =========================================

    public function isOnline()
    {
        return $this->status === 'online';
    }

    // =========================================
    // MESSAGE RELATIONSHIPS
    // =========================================

    // ✅ Required by AdminController: withCount('messages')
    public function messages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    // =========================================
    // WORKSPACE RELATIONSHIPS
    // =========================================

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
                    ->withPivot('role', 'permissions', 'is_active')
                    ->withTimestamps();
    }

    public function ownedWorkspaces()
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    public function currentWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    // =========================================
    // CHANNEL RELATIONSHIPS
    // =========================================

    public function channels()
    {
        return $this->belongsToMany(Channel::class, 'channel_members')
                    ->withPivot('role', 'last_read_at', 'unread_count')
                    ->withTimestamps();
    }

    // =========================================
    // GROUP RELATIONSHIPS
    // =========================================

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_members')
                    ->withTimestamps();
    }
}