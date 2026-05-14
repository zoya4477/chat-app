<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $receiver_id;
    public $is_typing;

    public function __construct(User $user, $receiver_id, $is_typing = true)
    {
        $this->user = $user;
        $this->receiver_id = $receiver_id;
        $this->is_typing = $is_typing;
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->receiver_id);
    }

    public function broadcastWith()
    {
        return [
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'is_typing' => $this->is_typing
        ];
    }
}