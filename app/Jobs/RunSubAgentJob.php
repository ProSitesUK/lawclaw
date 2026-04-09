<?php

namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;

class RunSubAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public int $messageId,
        public string $task,
        public string $workdir,
    ) {}

    public function handle(): void
    {
        $msg = Message::find($this->messageId);
        if (!$msg) return;

        $meta = $msg->meta ?? [];
        $meta['started_at'] = now()->toIso8601String();
        $msg->update(['meta' => $meta, 'status' => 'streaming']);

        $cmd = [
            'claude',
            '--print',
            '--output-format', 'json',
            '--permission-mode', 'acceptEdits',
            '--add-dir', $this->workdir,
            '--model', 'claude-sonnet-4-5',
        ];

        $prompt = "You are a sub-agent working inside {$this->workdir}. Build the following project end-to-end. Create all files, run any setup commands you need, and make it functional. When done, summarise what you built.\n\nTASK:\n{$this->task}";

        $result = Process::timeout(1700)
            ->path($this->workdir)
            ->env([
                'HOME' => '/home/server-2idet',
                'PATH' => '/home/server-2idet/.npm-global/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ])
            ->input($prompt)
            ->run($cmd);

        $output = $result->output();
        $json = json_decode($output, true);
        $content = $json['result'] ?? $json['text'] ?? trim($output);

        $meta['finished_at'] = now()->toIso8601String();
        $meta['exit_code'] = $result->exitCode();
        $meta['cost_usd'] = $json['total_cost_usd'] ?? null;

        if (!$result->successful() && !$content) {
            $msg->update([
                'content' => "⚠️ Sub-agent failed: " . ($result->errorOutput() ?: 'unknown error'),
                'status' => 'error',
                'meta' => $meta,
            ]);
            return;
        }

        // List files created
        $files = [];
        $it = @scandir($this->workdir) ?: [];
        foreach ($it as $f) {
            if ($f === '.' || $f === '..') continue;
            $files[] = $f;
        }
        $meta['files'] = $files;

        $msg->update([
            'content' => "✅ Sub-agent complete\n\n**Task:** {$this->task}\n\n**Summary:**\n{$content}\n\n**Workdir:** `{$this->workdir}`\n\n**Files:** " . (empty($files) ? '(none)' : implode(', ', array_slice($files, 0, 20))),
            'status' => 'complete',
            'meta' => $meta,
        ]);
    }
}
