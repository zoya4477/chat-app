<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): array
{
    $channels = [];

    // Direct message
    if ($this->message->receiver_id) {
        $channels[] = new PrivateChannel('chat.' . $this->message->receiver_id);
        $channels[] = new PrivateChannel('chat.' . $this->message->sender_id);
    }

    // Group message
    if ($this->message->group_id) {
        $channels[] = new PresenceChannel('group.' . $this->message->group_id);
    }

    // ✅ Channel message - YEH ADD KARO
    if ($this->message->channel_id) {
        $channels[] = new PresenceChannel('channel.' . $this->message->channel_id);
    }

    // ✅ Fallback
    if (empty($channels)) {
        $channels[] = new PrivateChannel('chat.' . $this->message->sender_id);
    }

    return $channels;
}

    public function broadcastWith(): array
    {
        return ['message' => $this->message->load('sender')];
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }
}