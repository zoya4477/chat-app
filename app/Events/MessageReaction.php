<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReaction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $reactions;

    public function __construct(Message $message, $reactions)
    {
        $this->message = $message;
        $this->reactions = $reactions;
    }

    public function broadcastOn()
    {
        return new PresenceChannel('chat.' . $this->message->receiver_id);
    }

    public function broadcastWith()
    {
        return [
            'message_id' => $this->message->id,
            'reactions' => $this->reactions
        ];
    }
}