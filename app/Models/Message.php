<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'group_id',
        'channel_id',           // Add channel_id
        'workspace_id',         // Add workspace_id
        'message',
        'file_url',
        'file_name',
        'file_size',
        'message_type',
        'is_read',
        'status',
        'is_edited',
        'edited_at',
        'original_message',
        'reactions',
        'reply_to',             // Add reply_to for threading
        'is_reported',          // Add for moderation
        'moderated_at',         // Add for moderation
        'moderated_by',         // Add for moderation
    ];

    protected $casts = [
        'is_read'      => 'boolean',
        'is_edited'    => 'boolean',
        'is_reported'  => 'boolean',
        'edited_at'    => 'datetime',
        'moderated_at' => 'datetime',
        'reactions'    => 'array',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // ========== RELATIONSHIPS ==========
    
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    // ✅ ADD THIS - Missing channel relationship
    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    // ✅ ADD THIS - Workspace relationship
    public function workspace()
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    // Thread/Reply relationships
    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to');
    }

    public function replies()
    {
        return $this->hasMany(Message::class, 'reply_to')->orderBy('created_at', 'asc');
    }

    // Message status methods
    public function markAsDelivered()
    {
        if ($this->status === 'sent') {
            $this->update(['status' => 'delivered']);
            broadcast(new \App\Events\MessageDelivered($this))->toOthers();
        }
    }

    public function markAsSeen()
    {
        if ($this->status !== 'seen') {
            $this->update(['status' => 'seen', 'is_read' => true]);
            broadcast(new \App\Events\MessageSeen($this))->toOthers();
        }
    }

    // Helper methods
    public function isImage()
    {
        return $this->message_type === 'image';
    }

    public function isFile()
    {
        return $this->message_type === 'file';
    }

    public function isText()
    {
        return $this->message_type === 'text' || !$this->message_type;
    }

    public function isEdited()
    {
        return (bool) $this->is_edited;
    }

    public function isReported()
    {
        return (bool) $this->is_reported;
    }
}