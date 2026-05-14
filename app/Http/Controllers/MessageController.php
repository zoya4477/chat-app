<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function send(Request $request)
    {
        // ✅ simple validation
        $request->validate([
            'receiver_id' => 'required',
            'message' => 'required'
        ]);

        // ✅ save message
        $msg = Message::create([
            'sender_id' => 1, // demo user
            'receiver_id' => $request->receiver_id,
            'message' => $request->message
        ]);

        // ❌ broadcast REMOVE (for now stable version)
        // broadcast(new MessageSent($msg))->toOthers();

        return response()->json($msg);
    }

    public function getMessages($id)
    {
        return Message::where(function ($q) use ($id) {
                $q->where('sender_id', 1)
                  ->where('receiver_id', $id);
            })
            ->orWhere(function ($q) use ($id) {
                $q->where('sender_id', $id)
                  ->where('receiver_id', 1);
            })
            ->orderBy('id', 'asc')
            ->get();
    }
}