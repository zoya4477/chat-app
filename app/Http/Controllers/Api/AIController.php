<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Message;
use App\Models\Channel;
use App\Models\Group;
use App\Models\User;
use App\Models\Workspace;
use App\Models\ChannelMember;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AIController extends Controller
{
    /**
     * Get conversation summary
     */
    public function getSummary(Request $request)
    {
        $request->validate([
            'channel_id'    => 'nullable|exists:channels,id',
            'group_id'      => 'nullable|exists:groups,id',
            'user_id'       => 'nullable|exists:users,id',
            'message_count' => 'nullable|integer|min:10|max:200',
        ]);

        $messageCount = $request->message_count ?? 50;
        $messages     = $this->fetchMessages($request, $messageCount);

        if ($messages->isEmpty()) {
            return response()->json([
                'success'            => true,
                'summary'            => 'No messages to summarize yet. Start a conversation!',
                'key_points'         => [],
                'important_messages' => [],
                'total_messages'     => 0,
            ]);
        }

        return response()->json([
            'success'            => true,
            'summary'            => $this->generateSummary($messages),
            'key_points'         => $this->extractKeyPoints($messages),
            'important_messages' => $this->findImportantMessages($messages),
            'total_messages'     => $messages->count(),
            'time_range'         => [
                'from' => $messages->first()->created_at,
                'to'   => $messages->last()->created_at,
            ],
        ]);
    }

    /**
     * Get quick reply suggestions
     */
    public function getQuickReplies(Request $request)
    {
        $request->validate([
            'context'    => 'required|string|max:500',
            'channel_id' => 'nullable|exists:channels,id',
            'group_id'   => 'nullable|exists:groups,id',
        ]);

        $suggestions = $this->generateReplySuggestions($request->context);

        return response()->json([
            'success'     => true,
            'suggestions' => array_slice($suggestions, 0, 8),
            'context'     => $request->context,
        ]);
    }

    /**
     * Get message highlights
     */
    public function getHighlights(Request $request)
    {
        $request->validate([
            'channel_id' => 'nullable|exists:channels,id',
            'group_id'   => 'nullable|exists:groups,id',
            'hours'      => 'nullable|integer|min:1|max:168',
        ]);

        $hours    = $request->hours ?? 24;
        $since    = Carbon::now()->subHours($hours);
        $messages = $this->fetchMessages($request, 100, $since);

        return response()->json([
            'success'    => true,
            'highlights' => $this->generateHighlights($messages),
            'period'     => "Last {$hours} hours",
        ]);
    }

    /**
     * Get missed discussion summary
     */
    public function getMissedDiscussion(Request $request)
    {
        $request->validate([
            'channel_id' => 'required|exists:channels,id',
            'minutes'    => 'nullable|integer|min:5|max:1440',
        ]);

        $minutes  = $request->minutes ?? 30;
        $since    = Carbon::now()->subMinutes($minutes);

        $messages = Message::where('channel_id', $request->channel_id)
            ->where('created_at', '>=', $since)
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return response()->json([
                'success'        => true,
                'missed_summary' => [],
                'total_messages' => 0,
                'time_period'    => $minutes . ' minutes',
                'participants'   => [],
            ]);
        }

        return response()->json([
            'success'        => true,
            'missed_summary' => $this->generateMissedSummary($messages),
            'total_messages' => $messages->count(),
            'time_period'    => $minutes . ' minutes',
            'participants'   => $messages->pluck('sender.name')->unique()->values(),
        ]);
    }

    /**
     * Extract tasks from conversations
     */
    public function extractTasks(Request $request)
    {
        $request->validate([
            'channel_id' => 'nullable|exists:channels,id',
            'group_id'   => 'nullable|exists:groups,id',
            'hours'      => 'nullable|integer|min:1|max:168',
        ]);

        $hours    = $request->hours ?? 48;
        $since    = Carbon::now()->subHours($hours);
        $messages = $this->fetchMessages($request, 100, $since);

        $tasks = [];
        foreach ($messages as $message) {
            if (!$message->message) continue;
            $extracted = $this->extractTasksFromMessage($message);
            if ($extracted) {
                $tasks[] = $extracted;
            }
        }

        if (empty($tasks) && $messages->count() > 0) {
            $tasks = $this->generateContextualTasks($messages);
        }

        return response()->json([
            'success'     => true,
            'tasks'       => $tasks,
            'total_tasks' => count($tasks),
            'period'      => "Last {$hours} hours",
        ]);
    }

    /**
     * Get channel recommendations
     */
    public function getChannelRecommendations(Request $request)
    {
        $userId      = auth()->id();
        $workspaceId = $request->workspace_id;

        if (!$workspaceId) {
            $workspace = Workspace::whereHas('members', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->first();

            if (!$workspace) {
                return response()->json(['success' => true, 'recommendations' => []]);
            }

            $workspaceId = $workspace->id;
        }

        $availableChannels = Channel::where('workspace_id', $workspaceId)
            ->where('is_archived', false)
            ->whereNotIn('id', function ($q) use ($userId) {
                $q->select('channel_id')
                  ->from('channel_members')
                  ->where('user_id', $userId);
            })
            ->limit(10)
            ->get();

        if ($availableChannels->isEmpty()) {
            $availableChannels = Channel::where('workspace_id', $workspaceId)
                ->where('is_archived', false)
                ->withCount('messages')
                ->orderBy('messages_count', 'desc')
                ->limit(5)
                ->get();
        }

        $recommendations = [];
        foreach ($availableChannels as $channel) {
            $memberCount  = ChannelMember::where('channel_id', $channel->id)->count();
            $messageCount = Message::where('channel_id', $channel->id)->count();

            $recommendations[] = [
                'channel_id'      => $channel->id,
                'name'            => $channel->name,
                'description'     => $channel->description ?? 'No description',
                'relevance_score' => round(min($messageCount / 100, 1), 2),
                'reason'          => $this->getChannelReason($channel, $messageCount, $memberCount),
                'member_count'    => $memberCount,
                'message_count'   => $messageCount,
            ];
        }

        return response()->json(['success' => true, 'recommendations' => $recommendations]);
    }

    /**
     * AI Chat Bot — powered by Anthropic Claude API
     * Answers any question: app-related or general knowledge
     */
    public function chatBot(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'history' => 'nullable|array',
        ]);

        $systemPrompt = "You are a smart AI assistant embedded inside a team chat application (similar to Slack). You help users with two types of questions:

1. APP-RELATED questions — give clear step-by-step instructions for:
   - Creating channels, groups, and workspaces
   - Inviting and managing members
   - Sending, editing, and deleting messages
   - Uploading files and attachments
   - Managing notifications and muting
   - Using AI features like summaries, tasks, and highlights

2. GENERAL KNOWLEDGE questions — answer confidently and informatively about:
   - Technology, science, history, programming, business, and any other topic
   - Writing assistance (emails, messages, documents)
   - Explanations of concepts (AI, machine learning, etc.)
   - Advice and recommendations

Be concise, friendly, and helpful. Use bullet points for step-by-step instructions. Keep responses focused.";

        // Build conversation history for context
        $messages = [];
        if ($request->history) {
            foreach ($request->history as $msg) {
                $messages[] = [
                    'role'    => $msg['role'] === 'bot' ? 'assistant' : 'user',
                    'content' => $msg['content'],
                ];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $request->message];

        try {
            $response = Http::withHeaders([
                'x-api-key'         => env('ANTHROPIC_API_KEY'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 1000,
                'system'     => $systemPrompt,
                'messages'   => $messages,
            ]);

            if ($response->failed()) {
                \Log::error('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json([
                    'success'  => false,
                    'response' => 'AI service is temporarily unavailable. Please try again shortly.',
                    'type'     => 'error',
                ], 500);
            }

            $data  = $response->json();
            $reply = $data['content'][0]['text'] ?? 'Sorry, I could not generate a response.';

            return response()->json([
                'success'  => true,
                'response' => $reply,
                'type'     => 'ai',
            ]);

        } catch (\Exception $e) {
            \Log::error('ChatBot exception: ' . $e->getMessage());
            return response()->json([
                'success'  => false,
                'response' => 'Sorry, something went wrong. Please try again.',
                'type'     => 'error',
            ], 500);
        }
    }

    /**
     * Get conversation starters
     */
    public function getConversationStarters(Request $request)
    {
        $starters = [
            ['text' => 'Hey everyone! How\'s it going?',              'type' => 'greeting',     'emoji' => '👋'],
            ['text' => 'I have an idea I\'d like to share...',        'type' => 'idea',         'emoji' => '💡'],
            ['text' => 'What does everyone think about this?',        'type' => 'question',     'emoji' => '🤔'],
            ['text' => 'Quick check-in: How\'s everyone doing?',      'type' => 'check_in',     'emoji' => '📢'],
            ['text' => 'I was thinking about what we discussed...',   'type' => 'discussion',   'emoji' => '💭'],
            ['text' => 'Let\'s focus on our priorities this week.',   'type' => 'planning',     'emoji' => '🎯'],
            ['text' => 'Quick idea: What if we tried...',             'type' => 'idea',         'emoji' => '✨'],
            ['text' => 'Can someone share a progress update?',        'type' => 'update',       'emoji' => '📊'],
            ['text' => 'Great work team! Let\'s keep it up!',         'type' => 'motivation',   'emoji' => '🎉'],
            ['text' => 'Does anyone need help with anything?',        'type' => 'help',         'emoji' => '🙋'],
            ['text' => 'Let\'s schedule a quick sync.',               'type' => 'meeting',      'emoji' => '📅'],
            ['text' => 'Just wanted to confirm that...',              'type' => 'confirmation', 'emoji' => '✅'],
        ];

        return response()->json(['success' => true, 'suggestions' => $starters]);
    }

    /**
     * Get smart digest
     */
    public function getSmartDigest(Request $request)
    {
        $userId = auth()->id();
        $hours  = $request->hours ?? 12;
        $since  = Carbon::now()->subHours($hours);

        $messages = Message::where('created_at', '>=', $since)
            ->with('sender')
            ->limit(50)
            ->get();

        $directMentions = 0;
        $urgentMessages = 0;

        foreach ($messages as $msg) {
            if (str_contains(strtolower($msg->message ?? ''), "@{$userId}")) $directMentions++;
            if (str_contains(strtolower($msg->message ?? ''), 'urgent'))     $urgentMessages++;
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'direct_mentions' => $directMentions,
                'urgent_messages' => $urgentMessages,
                'total_important' => $messages->count(),
                'time_period'     => "Last {$hours} hours",
            ],
            'recent_mentions' => $messages->take(5)->map(fn($m) => [
                'sender'  => $m->sender->name ?? 'Unknown',
                'message' => substr($m->message ?? '', 0, 100),
                'time'    => $m->created_at->diffForHumans(),
            ]),
        ]);
    }

    // ========== PRIVATE HELPER METHODS ==========

    private function fetchMessages($request, $limit, $since = null)
    {
        $query = Message::with('sender');

        if ($request->channel_id) {
            $query->where('channel_id', $request->channel_id);
        } elseif ($request->group_id) {
            $query->where('group_id', $request->group_id);
        } elseif ($request->user_id) {
            $query->where(function ($q) use ($request) {
                $q->where('sender_id', $request->user_id)
                  ->orWhere('receiver_id', $request->user_id);
            });
        } else {
            $query->where('sender_id', auth()->id());
        }

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->whereNotNull('message')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse();
    }

    private function generateSummary($messages)
    {
        $totalMessages = $messages->count();
        $participants  = $messages->pluck('sender.name')->filter()->unique()->count();
        $timeSpan      = $this->getTimeSpan($messages);
        $topics        = $this->extractTopics($messages);
        $sentiment     = $this->analyzeSentiment($messages);

        $activeParticipants = $messages->groupBy('sender.name')
            ->map(fn($msgs) => $msgs->count())
            ->sortDesc()
            ->take(3)
            ->keys()
            ->toArray();

        $summary  = "Conversation Summary\n\n";
        $summary .= "- {$totalMessages} messages from {$participants} participants over {$timeSpan}\n";

        if (!empty($activeParticipants)) {
            $summary .= "- Most active: " . implode(', ', $activeParticipants) . "\n";
        }

        $summary .= "- Overall sentiment: {$sentiment}\n";

        if (!empty($topics)) {
            $summary .= "- Key topics: " . implode(', ', array_slice($topics, 0, 5)) . "\n";
        }

        return $summary;
    }

    private function generateReplySuggestions($context)
    {
        $suggestions = [
            ['text' => 'Got it, thanks!',                'type' => 'acknowledgment', 'emoji' => '👍'],
            ['text' => 'I agree with that!',             'type' => 'agreement',      'emoji' => '✅'],
            ['text' => 'Let me think about that.',       'type' => 'thinking',       'emoji' => '💭'],
            ['text' => 'Can you provide more details?',  'type' => 'question',       'emoji' => '❓'],
            ['text' => 'That sounds great!',             'type' => 'positive',       'emoji' => '🎉'],
            ['text' => 'I\'ll look into it.',            'type' => 'action',         'emoji' => '🔍'],
            ['text' => 'Thanks for sharing!',            'type' => 'appreciation',   'emoji' => '🙏'],
            ['text' => 'Let\'s discuss this in a call?', 'type' => 'suggestion',     'emoji' => '📞'],
        ];

        $lower = strtolower($context);

        if (str_contains($lower, '?')) {
            array_unshift($suggestions, ['text' => 'Good question! Let me explain...', 'type' => 'explanation', 'emoji' => '📝']);
        }
        if (str_contains($lower, 'thanks') || str_contains($lower, 'thank')) {
            array_unshift($suggestions, ['text' => 'You\'re welcome!', 'type' => 'polite', 'emoji' => '🙏']);
        }
        if (str_contains($lower, 'hello') || str_contains($lower, 'hi')) {
            array_unshift($suggestions, ['text' => 'Hello! How can I help you?', 'type' => 'greeting', 'emoji' => '👋']);
        }
        if (str_contains($lower, 'meeting')) {
            $suggestions[] = ['text' => 'What time works for everyone?', 'type' => 'meeting', 'emoji' => '📅'];
        }
        if (str_contains($lower, 'deadline')) {
            $suggestions[] = ['text' => 'When is the deadline exactly?', 'type' => 'deadline', 'emoji' => '⏰'];
        }

        return $suggestions;
    }

    private function extractKeyPoints($messages)
    {
        $keyPoints = [];

        foreach ($messages as $message) {
            if (!$message->message) continue;

            if (preg_match('/(?:will|can|could|should|need to|have to)\s+([^.!?]+)/i', $message->message, $matches)) {
                $keyPoints[] = "Action: " . trim($matches[0]);
            }
            if (str_contains($message->message, '?')) {
                $keyPoints[] = "Question from {$message->sender->name}: " . substr($message->message, 0, 80);
            }
            if (preg_match('/(?:decided|agree|conclude|finalize)/i', $message->message)) {
                $keyPoints[] = "Decision: " . substr($message->message, 0, 80);
            }
        }

        return array_slice(array_unique($keyPoints), 0, 8);
    }

    private function findImportantMessages($messages)
    {
        $important = [];

        foreach ($messages as $message) {
            if (!$message->message) continue;

            $importance = 0;
            $reason     = '';

            foreach (['urgent', 'important', 'asap', 'deadline', 'critical'] as $keyword) {
                if (stripos($message->message, $keyword) !== false) {
                    $importance += 2;
                    $reason      = 'Contains urgent keyword';
                }
            }
            if (str_contains($message->message, '?')) {
                $importance += 1;
                $reason      = 'Contains a question';
            }
            if (strlen($message->message) > 200) {
                $importance += 1;
                $reason      = 'Detailed message';
            }

            if ($importance >= 2) {
                $important[] = [
                    'sender'  => $message->sender->name ?? 'Unknown',
                    'message' => substr($message->message, 0, 120),
                    'reason'  => $reason,
                ];
            }
        }

        return array_slice($important, 0, 8);
    }

    private function generateHighlights($messages)
    {
        $highlights = [];

        $grouped = $messages->groupBy(function ($msg) {
            return $msg->created_at->format('Y-m-d H:00');
        });

        foreach ($grouped as $hour => $msgs) {
            $highlights[] = [
                'time'          => Carbon::parse($hour)->format('g:i A'),
                'message_count' => $msgs->count(),
                'participants'  => $msgs->pluck('sender.name')->filter()->unique()->values(),
            ];
        }

        return $highlights;
    }

    private function generateMissedSummary($messages)
    {
        $summary = [];

        $grouped = $messages->groupBy(function ($msg) {
            return $msg->created_at->format('Y-m-d H:i');
        });

        foreach ($grouped as $time => $msgs) {
            $summary[] = [
                'time'     => Carbon::parse($time)->format('g:i A'),
                'messages' => $msgs->map(fn($msg) => [
                    'sender'  => $msg->sender->name ?? 'Unknown',
                    'message' => $msg->message,
                ]),
            ];
        }

        return $summary;
    }

    private function extractTasksFromMessage($message)
    {
        if (!$message->message) return null;

        $patterns = [
            '/(?:need to|have to|must|should|will)\s+([^.!?]+)/i',
            '/(?:todo|to do|task):\s*([^.!?]+)/i',
            '/(?:please|could you|can you)\s+([^.!?]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message->message, $matches)) {
                return [
                    'task'        => trim($matches[1]),
                    'priority'    => $this->detectPriority($message->message),
                    'assigned_to' => $this->extractMentionedUser($message->message),
                    'created_by'  => $message->sender->name ?? 'Unknown',
                    'created_at'  => $message->created_at->toISOString(),
                    'channel'     => $message->channel_id ? 'Channel' : ($message->group_id ? 'Group' : 'DM'),
                ];
            }
        }

        return null;
    }

    private function generateContextualTasks($messages)
    {
        $tasks  = [];
        $topics = $this->extractTopics($messages, 3);

        foreach ($topics as $topic) {
            $tasks[] = [
                'task'        => "Follow up on discussion about '{$topic}'",
                'priority'    => 'medium',
                'assigned_to' => null,
                'created_by'  => 'AI Assistant',
                'created_at'  => now()->toISOString(),
                'channel'     => 'Current Conversation',
            ];
        }

        if ($messages->count() > 20) {
            $tasks[] = [
                'task'        => "Review the active conversation ({$messages->count()} messages)",
                'priority'    => 'low',
                'assigned_to' => auth()->user()->name,
                'created_by'  => 'AI Assistant',
                'created_at'  => now()->toISOString(),
                'channel'     => 'Current Conversation',
            ];
        }

        return $tasks;
    }

    private function extractMentionedUser($message)
    {
        if (preg_match('/@(\w+)/', $message, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function detectPriority($message)
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'urgent') || str_contains($lower, 'asap'))  return 'high';
        if (str_contains($lower, 'soon')   || str_contains($lower, 'today')) return 'medium';
        return 'low';
    }

    private function getChannelReason($channel, $messageCount, $memberCount)
    {
        if ($messageCount > 500) return "Very active channel with {$messageCount} messages";
        if ($memberCount  > 20)  return "Popular channel with {$memberCount} members";
        if ($messageCount > 100) return "Active discussions happening here";
        return "Recommended based on workspace activity";
    }

    private function getTimeSpan($messages)
    {
        $first = $messages->first();
        $last  = $messages->last();

        if (!$first || !$last) return 'a few moments';

        $diff = $last->created_at->diff($first->created_at);

        if ($diff->days > 0) return $diff->days . ' days';
        if ($diff->h    > 0) return $diff->h    . ' hours';
        if ($diff->i    > 0) return $diff->i    . ' minutes';
        return 'a few moments';
    }

    private function extractTopics($messages, $limit = 10)
    {
        $topics      = [];
        $commonWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'is', 'are', 'was', 'were'];

        foreach ($messages as $message) {
            if (!$message->message) continue;

            $words = explode(' ', strtolower($message->message));
            foreach ($words as $word) {
                $word = trim($word, '.,!?;:');
                if (strlen($word) > 3 && !in_array($word, $commonWords)) {
                    $topics[$word] = ($topics[$word] ?? 0) + 1;
                }
            }
        }

        arsort($topics);
        return array_keys(array_slice($topics, 0, $limit));
    }

    private function analyzeSentiment($messages)
    {
        $positive = ['good', 'great', 'awesome', 'excellent', 'amazing', 'perfect', 'love', 'happy'];
        $negative = ['bad', 'terrible', 'hate', 'dislike', 'sad', 'angry', 'frustrated'];
        $score    = 0;

        foreach ($messages as $message) {
            if (!$message->message) continue;
            $text = strtolower($message->message);
            foreach ($positive as $word) { if (str_contains($text, $word)) $score++; }
            foreach ($negative as $word) { if (str_contains($text, $word)) $score--; }
        }

        if ($score > 2)  return 'Positive';
        if ($score < -2) return 'Negative';
        return 'Neutral';
    }
}