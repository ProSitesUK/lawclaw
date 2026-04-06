<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ClaudeCodeRunner
{
    /**
     * Send a prompt to Claude Code CLI and get a response.
     * Uses --print mode (one-shot, non-interactive).
     */
    public function send(ChatSession $session, string $userMessage): array
    {
        // Build prompt with history
        $history = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->get()
            ->map(fn($m) => strtoupper($m->role) . ": " . $m->content)
            ->implode("\n\n");

        $systemPrompt = $session->system_prompt ?: $this->defaultSystemPrompt($session);
        $workspace = $this->workspacePath($session);

        $fullPrompt = $userMessage;

        // Build command
        $cmd = [
            'claude',
            '--print',
            '--output-format', 'json',
            '--model', $session->model ?: 'claude-sonnet-4-5',
        ];

        if ($systemPrompt) {
            $cmd[] = '--append-system-prompt';
            $cmd[] = $systemPrompt;
        }

        // Add history as part of the prompt
        if ($history) {
            $fullPrompt = "Previous conversation:\n\n{$history}\n\nNew message:\n\n{$userMessage}";
        }

        $result = Process::timeout(300)
            ->path($workspace)
            ->env([
                'HOME' => env('HOME', '/home/server-2idet'),
                'PATH' => '/home/server-2idet/.npm-global/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'CLAUDE_CODE_OAUTH_TOKEN' => env('CLAUDE_CODE_OAUTH_TOKEN', ''),
            ])
            ->input($fullPrompt)
            ->run($cmd);

        if (!$result->successful()) {
            return [
                'success' => false,
                'error' => $result->errorOutput() ?: 'Claude CLI failed',
                'exit_code' => $result->exitCode(),
            ];
        }

        $output = $result->output();
        $json = json_decode($output, true);

        if (!$json) {
            // Not JSON — treat raw output as the answer
            return [
                'success' => true,
                'content' => trim($output),
                'raw' => $output,
            ];
        }

        return [
            'success' => true,
            'content' => $json['result'] ?? $json['text'] ?? trim($output),
            'meta' => [
                'session_id' => $json['session_id'] ?? null,
                'usage' => $json['usage'] ?? null,
                'cost_usd' => $json['total_cost_usd'] ?? null,
                'duration_ms' => $json['duration_ms'] ?? null,
            ],
        ];
    }

    public function workspacePath(ChatSession $session): string
    {
        $path = storage_path("app/workspaces/user-{$session->user_id}");
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        return $path;
    }

    protected function defaultSystemPrompt(ChatSession $session): string
    {
        return "You are LawClaw, a helpful personal AI assistant for {$session->user->name}. You're running inside a self-hosted Laravel application. Be concise, helpful, and genuine. Not a corporate drone — just good.";
    }
}
