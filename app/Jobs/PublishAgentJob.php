<?php

namespace App\Jobs;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class PublishAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $msg = Message::find($this->messageId);
        if (!$msg) return;
        $meta = $msg->meta ?? [];
        $workdir = $meta['workdir'] ?? null;
        $slug = $meta['slug'] ?? ('agent-' . $msg->id);
        if (!$workdir || !is_dir($workdir)) return;

        $pat = env('GITHUB_PAT');
        $org = env('GITHUB_ORG', 'ProSitesUK');
        if (!$pat) {
            $meta['publish_error'] = 'GITHUB_PAT not set in .env';
            $msg->update(['meta' => $meta]);
            return;
        }

        $env = [
            'HOME' => '/home/server-2idet',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
        ];
        $run = fn($c) => Process::path($workdir)->env($env)->run($c);

        // Init repo if needed
        if (!is_dir($workdir . '/.git')) {
            $run('git init -b main');
            $run('git config user.email "lawclaw@elliot.house"');
            $run('git config user.name "LawClaw Agent"');
        }
        $run('git add -A');
        $run('git commit -m "LawClaw sub-agent build" --allow-empty');

        // Create repo via GitHub API
        $resp = Http::withToken($pat)
            ->acceptJson()
            ->post("https://api.github.com/orgs/{$org}/repos", [
                'name' => $slug,
                'private' => true,
                'description' => 'Built by LawClaw sub-agent',
                'auto_init' => false,
            ]);

        if (!$resp->successful() && $resp->status() !== 422) {
            $meta['publish_error'] = 'GitHub API: ' . $resp->status() . ' ' . $resp->body();
            unset($meta['publishing']);
            $msg->update(['meta' => $meta]);
            return;
        }

        $repoUrl = "https://github.com/{$org}/{$slug}";
        $pushUrl = "https://{$pat}@github.com/{$org}/{$slug}.git";

        $run("git remote remove origin 2>/dev/null; git remote add origin {$pushUrl}");
        $push = $run('git push -u origin main --force');

        unset($meta['publishing']);
        if ($push->successful()) {
            $meta['github_url'] = $repoUrl;
            $meta['published_at'] = now()->toIso8601String();
            $msg->update([
                'content' => $msg->content . "\n\n🚀 **Published:** {$repoUrl}\n\nDeploy on Ploi: add a new site pointing at this repo.",
                'meta' => $meta,
            ]);
        } else {
            $meta['publish_error'] = 'git push failed: ' . $push->errorOutput();
            $msg->update(['meta' => $meta]);
        }
    }
}
