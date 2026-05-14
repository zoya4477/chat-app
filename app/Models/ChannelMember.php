<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ChannelMember extends Pivot
{
    use HasFactory;

    protected $table = 'channel_members';

    protected $fillable = [
        'channel_id',
        'user_id',
        'role',
        'last_read_at',
        'unread_count'
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'unread_count' => 'integer'
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isMember()
    {
        return $this->role === 'member';
    }
}