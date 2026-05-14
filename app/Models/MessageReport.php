<?php
// app/Models/MessageReport.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageReport extends Model
{
    protected $fillable = [
        'message_id', 'reporter_id', 'reason', 'status', 'action_taken'
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}