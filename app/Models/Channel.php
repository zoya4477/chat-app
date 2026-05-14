<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'topic',
        'type',
        'workspace_id',
        'created_by',
        'is_archived',
        'archived_at',
        'archived_by'
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'channel_members')
                    ->withPivot('role', 'last_read_at', 'unread_count')
                    ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function isMember($userId)
    {
        return $this->members()
                    ->where('user_id', $userId)
                    ->exists();
    }

    public function isAdmin($userId)
    {
        $member = $this->members()
                       ->where('user_id', $userId)
                       ->first();

        return $member && $member->pivot->role === 'admin';
    }

    public function isArchived()
    {
        return $this->is_archived;
    }

    public function archive($userId)
    {
        $this->update([
            'is_archived' => true,
            'archived_at' => now(),
            'archived_by' => $userId
        ]);
    }

    public function unarchive()
    {
        $this->update([
            'is_archived' => false,
            'archived_at' => null,
            'archived_by' => null
        ]);
    }

    public function getUnreadCount($userId)
    {
        $member = $this->members()
                       ->where('user_id', $userId)
                       ->first();

        return $member ? $member->pivot->unread_count : 0;
    }

    public function markAsRead($userId)
    {
        $this->members()->updateExistingPivot($userId, [
            'last_read_at' => now(),
            'unread_count' => 0
        ]);
    }
}