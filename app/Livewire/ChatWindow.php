<?php

namespace App\Livewire;

use App\Jobs\ProcessChatMessage;
use App\Models\ChatSession;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class ChatWindow extends Component
{
    public ?int $sessionId = null;
    public string $input = '';
    public bool $pending = false;

    public function mount(?int $sessionId = null): void
    {
        if ($sessionId) {
            $this->sessionId = $sessionId;
        } else {
            // auto-pick most recent or create one
            $session = Auth::user()->chatSessions()->latest()->first()
                ?? $this->newSession();
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

    public function send(): void
    {
        $text = trim($this->input);
        if (!$text || !$this->sessionId) return;

        $session = Auth::user()->chatSessions()->findOrFail($this->sessionId);

        // Save user message
        $session->messages()->create([
            'role' => 'user',
            'content' => $text,
            'status' => 'complete',
        ]);

        // Pre-create assistant placeholder
        $assistantMsg = $session->messages()->create([
            'role' => 'assistant',
            'content' => '',
            'status' => 'pending',
        ]);

        $this->input = '';
        $this->pending = true;

        // Queue the job (database driver) so the UI returns instantly
        ProcessChatMessage::dispatch($session->id, $assistantMsg->id, $text);

        // Fire-and-forget a background worker to process this single job
        $base = base_path();
        $php = PHP_BINARY;
        $cmd = "cd {$base} && nohup {$php} artisan queue:work --once --stop-when-empty --tries=1 > storage/logs/worker.log 2>&1 &";
        @exec($cmd);
    }

    public function render()
    {
        $user = Auth::user();
        $sessions = $user->chatSessions()->latest()->get();
        $session = $this->sessionId ? $user->chatSessions()->with('messages')->find($this->sessionId) : null;
        $messages = $session ? $session->messages : collect();
        $hasPending = $messages->where('status', 'pending')->count() > 0 || $messages->where('status', 'streaming')->count() > 0;

        return view('livewire.chat-window', [
            'sessions' => $sessions,
            'session' => $session,
            'messages' => $messages,
            'hasPending' => $hasPending,
        ]);
    }
}
