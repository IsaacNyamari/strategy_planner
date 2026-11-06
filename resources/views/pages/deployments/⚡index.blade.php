<?php

use App\Deployment\Deployer;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Deployments')] class extends Component {
    #[Computed]
    public function versions(): ?array
    {
        return app(Deployer::class)->versions();
    }

    #[Computed]
    public function state(): array
    {
        return app(Deployer::class)->state();
    }

    public function refreshVersions(): void
    {
        app(Deployer::class)->versions(fresh: true);
        unset($this->versions);
    }

    public function deploy(): void
    {
        $this->authorize('deploy');

        // The page needs a recent password confirmation; Livewire actions don't
        // re-run route middleware, so check it here too.
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);
        if (time() - $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            $this->redirectRoute('password.confirm');

            return;
        }

        $result = app(Deployer::class)->start(auth()->user());

        if (! $result['ok']) {
            Flux::toast(variant: 'danger', text: $result['message']);

            return;
        }

        unset($this->state);
        Flux::toast(variant: 'success', text: 'Deployment started. Follow the log below.');
        $this->dispatch('deploy-started');
    }
};
?>

@php
    $versions = $this->versions;
    $state = $this->state;
    $upToDate = $versions && $versions['latest'] && str_starts_with($versions['current']['hash'], substr($versions['latest'], 0, 7));
    $repo = rtrim((string) config('worship.deploy.repository'), '/');
@endphp

<div class="flex flex-col gap-6"
    x-data="{
        status: @js($state['status']),
        log: '',
        finishedCommit: @js($state['commit']),
        timer: null,
        async poll() {
            try {
                const res = await fetch(@js(route('deployments.status')), { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                if (! res.ok) return;
                const data = await res.json();
                this.status = data.state.status;
                this.finishedCommit = data.state.commit;
                this.log = data.log;
                this.$nextTick(() => { const el = this.$refs.log; if (el) el.scrollTop = el.scrollHeight; });
                if (this.status !== 'running') this.stop();
            } catch (e) { /* the site restarts during a deploy; keep trying */ }
        },
        start() { this.status = 'running'; this.stop(); this.poll(); this.timer = setInterval(() => this.poll(), 2000); },
        stop() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },
    }"
    x-init="poll(); if (status === 'running') start()"
    x-on:deploy-started.window="start()">

    <div>
        <flux:heading size="xl" level="1">Deployments</flux:heading>
        <flux:subheading>Update the live site with the latest code from GitHub (<code class="text-xs">main</code>).</flux:subheading>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>Live version</flux:text>
                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="refreshVersions" aria-label="Refresh versions" />
            </div>
            @if ($versions)
                <div class="flex items-center gap-2">
                    <a href="{{ $repo }}/commit/{{ $versions['current']['hash'] }}" target="_blank" class="font-mono text-lg font-semibold hover:underline">{{ $versions['current']['hash'] }}</a>
                    @if ($upToDate)
                        <flux:badge size="sm" color="green" icon="check">Up to date</flux:badge>
                    @elseif ($versions['latest'])
                        <flux:badge size="sm" color="amber" icon="arrow-up-circle">Update available</flux:badge>
                    @endif
                </div>
                <flux:text size="sm" class="line-clamp-2">{{ $versions['current']['message'] }}</flux:text>
                @if ($versions['current']['date'])
                    <flux:text size="sm">{{ \Illuminate\Support\Carbon::parse($versions['current']['date'])->diffForHumans() }}</flux:text>
                @endif
                @if (! $upToDate && $versions['latest'])
                    <flux:text size="sm">Latest on GitHub: <a href="{{ $repo }}/commit/{{ $versions['latest'] }}" target="_blank" class="font-mono hover:underline">{{ $versions['latest'] }}</a>
                        · <a href="{{ $repo }}/compare/{{ $versions['current']['hash'] }}...{{ $versions['latest'] }}" target="_blank" class="hover:underline">see changes</a></flux:text>
                @endif
            @else
                <flux:text size="sm">Version information unavailable (the deploy helper may not be installed on this server).</flux:text>
            @endif
        </flux:card>

        <flux:card class="flex flex-col justify-between gap-4">
            <div class="space-y-1">
                <flux:text>Last deployment</flux:text>
                <div class="flex items-center gap-2">
                    <template x-if="status === 'running'"><flux:badge color="blue" icon="arrow-path" class="[&_svg]:animate-spin">Running…</flux:badge></template>
                    <template x-if="status === 'success'"><flux:badge color="green" icon="check-circle">Succeeded</flux:badge></template>
                    <template x-if="status === 'failed'"><flux:badge color="red" icon="x-circle">Failed (site kept online)</flux:badge></template>
                    <template x-if="status === 'idle'"><flux:badge>None yet</flux:badge></template>
                </div>
                @if ($state['started_at'])
                    <flux:text size="sm">
                        {{ \Illuminate\Support\Carbon::parse($state['started_at'])->diffForHumans() }}
                        @if ($state['requested_by']) by {{ $state['requested_by'] }} @endif
                    </flux:text>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:button variant="primary" icon="rocket-launch" wire:click="deploy" x-bind:disabled="status === 'running'"
                    wire:confirm="Deploy the latest code from GitHub to the live site now? The site shows a short maintenance page (about a minute) while it updates.">
                    <span x-text="status === 'running' ? 'Deploying…' : 'Deploy now'"></span>
                </flux:button>
                <template x-if="status === 'success' || status === 'failed'">
                    <flux:button variant="ghost" icon="arrow-path" x-on:click="window.location.reload()">Reload page</flux:button>
                </template>
            </div>
        </flux:card>
    </div>

    <flux:card class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading>Deployment log</flux:heading>
            <flux:text size="sm" x-show="status === 'running'">updating every 2 seconds</flux:text>
        </div>
        <pre x-ref="log" x-text="log || 'No deployment has run yet.'"
            class="max-h-[28rem] overflow-auto whitespace-pre-wrap rounded-lg bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-100"></pre>
    </flux:card>

    <flux:text size="sm">
        Each deploy: maintenance mode → pull from GitHub → install dependencies → build styles → database migrations → caches → back online.
        If a step fails, the site is taken out of maintenance mode and the error is shown in the log, so fix it on GitHub and deploy again.
    </flux:text>
</div>
