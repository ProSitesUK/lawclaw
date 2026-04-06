<div class="flex h-[calc(100vh-4rem)] bg-[#0a0a0a] text-gray-100" wire:poll.2s="$refresh">
    <!-- Sidebar -->
    <aside class="w-72 bg-[#111] border-r border-white/5 flex flex-col">
        <div class="p-4 border-b border-white/5">
            <button wire:click="createSession" class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New chat
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-2">
            @forelse ($sessions as $s)
                <div wire:key="session-{{ $s->id }}" class="group relative">
                    <button wire:click="selectSession({{ $s->id }})"
                        class="w-full text-left px-3 py-2.5 rounded-lg text-sm transition mb-1 truncate pr-8
                               {{ $s->id === $sessionId ? 'bg-white/10 text-white' : 'text-gray-400 hover:bg-white/5 hover:text-gray-200' }}">
                        {{ $s->title }}
                    </button>
                    <button wire:click="deleteSession({{ $s->id }})" wire:confirm="Delete this chat?"
                        class="absolute right-2 top-2.5 opacity-0 group-hover:opacity-100 text-gray-500 hover:text-red-400 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
            @empty
                <p class="px-3 py-4 text-xs text-gray-600 text-center">No chats yet</p>
            @endforelse
        </div>
        <div class="p-4 border-t border-white/5 text-xs text-gray-500">
            <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                <span>{{ auth()->user()->name }}</span>
            </div>
        </div>
    </aside>

    <!-- Chat area -->
    <main class="flex-1 flex flex-col">
        <header class="px-6 py-4 border-b border-white/5 flex items-center justify-between">
            <div>
                <h1 class="font-semibold text-lg">{{ $session?->title ?? 'LawClaw' }}</h1>
                <p class="text-xs text-gray-500">{{ $session?->model ?? 'claude-sonnet-4-5' }}</p>
            </div>
            <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="text-xs text-gray-500 hover:text-gray-300">Log out</a>
            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">@csrf</form>
        </header>

        <div class="flex-1 overflow-y-auto px-6 py-8" id="message-list">
            <div class="max-w-3xl mx-auto space-y-6">
                @forelse ($messages as $msg)
                    <div wire:key="msg-{{ $msg->id }}" class="flex gap-4 {{ $msg->role === 'user' ? 'flex-row-reverse' : '' }}">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 text-sm font-bold
                            {{ $msg->role === 'user' ? 'bg-indigo-600' : 'bg-gradient-to-br from-purple-500 to-pink-500' }}">
                            {{ $msg->role === 'user' ? strtoupper(substr(auth()->user()->name, 0, 1)) : '🦾' }}
                        </div>
                        <div class="flex-1 max-w-2xl">
                            <div class="text-xs text-gray-500 mb-1 {{ $msg->role === 'user' ? 'text-right' : '' }}">
                                {{ $msg->role === 'user' ? auth()->user()->name : 'LawClaw' }}
                                <span class="ml-2">{{ $msg->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="prose prose-invert prose-sm max-w-none rounded-xl px-4 py-3 {{ $msg->role === 'user' ? 'bg-indigo-600/20 border border-indigo-500/30' : 'bg-white/5 border border-white/10' }}">
                                @if ($msg->status === 'pending')
                                    <div class="flex items-center gap-2 text-gray-400 text-sm">
                                        <div class="flex gap-1">
                                            <div class="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style="animation-delay:0ms"></div>
                                            <div class="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style="animation-delay:150ms"></div>
                                            <div class="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style="animation-delay:300ms"></div>
                                        </div>
                                        Thinking…
                                    </div>
                                @elseif ($msg->status === 'error')
                                    <p class="text-red-400 text-sm whitespace-pre-wrap">{{ $msg->content }}</p>
                                @else
                                    <div class="whitespace-pre-wrap text-gray-100 text-sm leading-relaxed">{{ $msg->content }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-20">
                        <div class="inline-flex w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-500 to-pink-500 items-center justify-center text-3xl mb-4">🦾</div>
                        <h2 class="text-2xl font-bold">LawClaw</h2>
                        <p class="text-gray-500 mt-2">Your self-hosted Claude assistant. Say hi.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Input -->
        <footer class="p-4 border-t border-white/5">
            <form wire:submit="send" class="max-w-3xl mx-auto">
                <div class="flex gap-3 items-end bg-white/5 border border-white/10 rounded-2xl px-4 py-3 focus-within:border-indigo-500/50 transition">
                    <textarea wire:model="input" rows="1"
                        placeholder="Message LawClaw…"
                        @keydown.enter.prevent="if(!$event.shiftKey){ $wire.send() }"
                        class="flex-1 bg-transparent text-sm text-white placeholder-gray-500 focus:outline-none resize-none"
                        style="max-height:200px;"></textarea>
                    <button type="submit"
                        @disabled($hasPending)
                        class="w-9 h-9 flex items-center justify-center rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:bg-white/10 disabled:cursor-not-allowed transition">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 19V5m0 0l-7 7m7-7l7 7"/></svg>
                    </button>
                </div>
                <p class="text-[10px] text-gray-600 text-center mt-2">LawClaw runs your Claude Max subscription via Claude Code CLI on this server.</p>
            </form>
        </footer>
    </main>
</div>
