<?php
namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageEdited implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public $message;
    
    public function __construct(Message $message)
    {
        $this->message = $message;
    }
    
    public function broadcastOn()
    {
        if ($this->message->receiver_id) {
            return new PrivateChannel('chat.' . $this->message->receiver_id);
        }
        
        if ($this->message->group_id) {
            return new \Illuminate\Broadcasting\PresenceChannel('group.' . $this->message->group_id);
        }
        
        return new PrivateChannel('chat.' . $this->message->sender_id);
    }
    
    public function broadcastWith()
    {
        return [
            'id' => $this->message->id,
            'message' => $this->message->message,
            'is_edited' => $this->message->is_edited,
            'edited_at' => $this->message->edited_at
        ];
    }
}