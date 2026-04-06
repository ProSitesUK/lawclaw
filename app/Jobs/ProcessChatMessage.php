<?php

namespace App\Jobs;

use App\Models\ChatSession;
use App\Models\Message;
use App\Services\ClaudeCodeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessChatMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 360;

    public function __construct(
        public int $chatSessionId,
        public int $assistantMessageId,
        public string $userContent,
    ) {}

    public function handle(ClaudeCodeRunner $runner): void
    {
        $session = ChatSession::with('user')->find($this->chatSessionId);
        $msg = Message::find($this->assistantMessageId);
        if (!$session || !$msg) return;

        $result = $runner->send($session, $this->userContent);

        if ($result['success']) {
            $msg->update([
                'content' => $result['content'],
                'meta' => $result['meta'] ?? null,
                'status' => 'complete',
            ]);

            // Auto-title if this is the first exchange
            if ($session->messages()->count() <= 2 && $session->title === 'New chat') {
                $session->update([
                    'title' => \Illuminate\Support\Str::limit($this->userContent, 50, '…'),
                ]);
            }
        } else {
            $msg->update([
                'content' => "⚠️ Error: " . ($result['error'] ?? 'Unknown error'),
                'status' => 'error',
            ]);
        }
    }
}
