<?php
// app/Http/Controllers/Api/MessageReportController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageReportController extends Controller
{
    /**
     * Report a message
     */
    public function store(Request $request, $messageId)
    {
        $request->validate([
            'reason' => 'required|string|min:3|max:500'
        ]);

        $userId = auth()->id();
        $message = Message::findOrFail($messageId);

        // Check if already reported by this user
        $existingReport = MessageReport::where('message_id', $messageId)
            ->where('reporter_id', $userId)
            ->first();

        if ($existingReport) {
            return response()->json([
                'message' => 'You have already reported this message'
            ], 422);
        }

        $report = MessageReport::create([
            'message_id' => $messageId,
            'reporter_id' => $userId,
            'reason' => $request->reason,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message reported successfully',
            'report' => $report
        ]);
    }

    /**
     * Get all reported messages (Admin only)
     */
    public function getReported(Request $request)
    {
        $reports = MessageReport::with(['message.sender', 'reporter'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($report) {
                return [
                    'id' => $report->message_id,
                    'content' => $report->message->message,
                    'message' => $report->message->message,
                    'reporter' => $report->reporter,
                    'report_reason' => $report->reason,
                    'status' => $report->status,
                    'created_at' => $report->created_at,
                    'sender' => $report->message->sender,
                ];
            });

        return response()->json([
            'success' => true,
            'messages' => $reports
        ]);
    }

    /**
     * Moderate a reported message (Approve or Remove)
     */
    public function moderate(Request $request, $messageId)
    {
        $request->validate([
            'action' => 'required|in:approve,remove'
        ]);

        $report = MessageReport::where('message_id', $messageId)
            ->where('status', 'pending')
            ->firstOrFail();

        DB::beginTransaction();
        try {
            if ($request->action === 'remove') {
                // Delete the message
                Message::where('id', $messageId)->delete();
                $report->update(['status' => 'resolved', 'action_taken' => 'removed']);
            } else {
                // Approve - keep the message, just mark report as resolved
                $report->update(['status' => 'resolved', 'action_taken' => 'approved']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Message {$request->action}d successfully"
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to moderate message'
            ], 500);
        }
    }
}