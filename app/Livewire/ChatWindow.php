<?php

namespace App\Livewire;

use App\Jobs\ProcessChatMessage;
use App\Jobs\RunSubAgentJob;
use App\Models\ChatSession;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ChatWindow extends Component
{
    public ?int $sessionId = null;
    public string $input = '';

    public function mount(?int $sessionId = null): void
    {
        if ($sessionId) {
            $this->sessionId = $sessionId;
        } else {
            $session = Auth::user()->chatSessions()->latest()->first() ?? $this->newSession();
            $this->sessionId = $session->id;
        }
    }

    protected function newSession(): ChatSession
    {
        return Auth::user()->chatSessions()->create([
            'title' => 'New chat',
            'model' => 'claude-sonnet-4-5',
        ]);
    }

    public function selectSession(int $id): void
    {
        $session = Auth::user()->chatSessions()->findOrFail($id);
        $this->sessionId = $session->id;
        $this->input = '';
    }

    public function createSession(): void
    {
        $session = $this->newSession();
        $this->sessionId = $session->id;
        $this->input = '';
    }

    public function deleteSession(int $id): void
    {
        $session = Auth::user()->chatSessions()->findOrFail($id);
        $session->delete();
        if ($this->sessionId === $id) {
            $this->sessionId = Auth::user()->chatSessions()->latest()->first()?->id ?? $this->newSession()->id;
        }
    }

    protected function kickWorker(): void
    {
        $base = base_path();
        $php = "/usr/bin/php8.5";
        $cmd = "cd {$base} && nohup {$php} artisan queue:work --once --stop-when-empty --tries=1 --timeout=1800 > storage/logs/worker.log 2>&1 &";
        @exec($cmd);
    }

    public function send(): void
    {
        $text = trim($this->input);
        if (!$text || !$this->sessionId) return;

        $session = Auth::user()->chatSessions()->findOrFail($this->sessionId);

        $session->messages()->create([
            'role' => 'user',
            'content' => $text,
            'status' => 'complete',
        ]);

        $this->input = '';

        // /agent slash-command → spawn a sub-agent
        if (preg_match('/^\/agent\s+(.+)/is', $text, $m)) {
            $task = trim($m[1]);
            $slug = 'agent-' . now()->format('YmdHis') . '-' . substr(md5($task), 0, 6);
            $workdir = storage_path('app/lawclaw/projects/' . $slug);
            if (!is_dir($workdir)) @mkdir($workdir, 0755, true);

            $agentMsg = $session->messages()->create([
                'role' => 'agent',
                'content' => "🤖 Sub-agent spawned\n\nTask: {$task}\n\nWorking…",
                'status' => 'pending',
                'meta' => [
                    'task' => $task,
                    'workdir' => $workdir,
                    'slug' => $slug,
                ],
            ]);

            RunSubAgentJob::dispatch($agentMsg->id, $task, $workdir);
            $this->kickWorker();
            return;
        }

        // Normal chat
        $assistantMsg = $session->messages()->create([
            'role' => 'assistant',
            'content' => '',
            'status' => 'pending',
        ]);

        ProcessChatMessage::dispatch($session->id, $assistantMsg->id, $text);
        $this->kickWorker();
    }

    public function publishAgent(int $messageId): void
    {
        $msg = Message::whereHas('chatSession', fn($q) => $q->where('user_id', Auth::id()))
            ->where('role', 'agent')
            ->findOrFail($messageId);

        $meta = $msg->meta ?? [];
        $workdir = $meta['workdir'] ?? null;
        if (!$workdir || !is_dir($workdir)) return;

        \App\Jobs\PublishAgentJob::dispatch($msg->id);
        $meta['publishing'] = true;
        $msg->update(['meta' => $meta]);
        $this->kickWorker();
    }

    public function render()
    {
        $user = Auth::user();
        $sessions = $user->chatSessions()->latest()->get();
        $session = $this->sessionId ? $user->chatSessions()->with('messages')->find($this->sessionId) : null;
        $messages = $session ? $session->messages : collect();
        $hasPending = $messages->whereIn('status', ['pending', 'streaming'])->count() > 0;

        return view('livewire.chat-window', [
            'sessions' => $sessions,
            'session' => $session,
            'messages' => $messages,
            'hasPending' => $hasPending,
        ]);
    }
}
