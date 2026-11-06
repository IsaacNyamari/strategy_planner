<?php

use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Support\Phone;
use App\WhatsApp\Waha;
use App\WhatsApp\WahaException;
use App\WhatsApp\WhatsAppSender;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('WhatsApp')] class extends Component {
    public ?array $session = null;
    public ?string $error = null;
    public ?string $qr = null;
    public ?string $pairingCode = null;

    /** @var list<array{id: string, name: string, type: string}> */
    public array $available = [];
    public bool $availableLoaded = false;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->error = null;

        try {
            $this->session = app(Waha::class)->session();

            if (($this->session['status'] ?? null) === 'WORKING') {
                $this->qr = null;
                $this->pairingCode = null;
            }
        } catch (WahaException $e) {
            $this->session = null;
            $this->error = $e->getMessage();
        }
    }

    /** Polled while waiting for a scan, so the QR refreshes before it expires. */
    public function poll(): void
    {
        $this->refreshStatus();

        if (($this->session['status'] ?? null) === 'SCAN_QR_CODE' && $this->qr) {
            $this->showQr();
        }
    }

    public function start(): void
    {
        $this->authorize('manage-whatsapp');

        try {
            app(Waha::class)->ensureStarted(route('webhooks.waha'));
            sleep(2); // give WAHA a moment to reach SCAN_QR_CODE
        } catch (WahaException $e) {
            $this->error = $e->getMessage();
        }

        $this->refreshStatus();
    }

    public function showQr(): void
    {
        $this->authorize('manage-whatsapp');
        $this->pairingCode = null;

        try {
            $this->qr = app(Waha::class)->qr();
        } catch (WahaException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function requestCode(): void
    {
        $this->authorize('manage-whatsapp');
        $this->qr = null;

        try {
            $this->pairingCode = app(Waha::class)->requestPairingCode();
        } catch (WahaException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function unlink(): void
    {
        $this->authorize('manage-whatsapp');

        try {
            app(Waha::class)->logout();
            Flux::toast(text: 'WhatsApp unlinked from this system.');
        } catch (WahaException $e) {
            $this->error = $e->getMessage();
        }

        $this->refreshStatus();
    }

    // --- Targets ------------------------------------------------------------------

    public function loadAvailable(): void
    {
        $this->authorize('manage-whatsapp');
        $this->error = null;

        try {
            $waha = app(Waha::class);
            $groups = array_map(fn ($g) => [...$g, 'type' => 'group'], $waha->groups());
            $channels = array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'type' => 'channel'], $waha->channels());
            $this->available = [...$groups, ...$channels];
            $this->availableLoaded = true;
        } catch (WahaException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function addTarget(string $chatId): void
    {
        $this->authorize('manage-whatsapp');
        $found = collect($this->available)->firstWhere('id', $chatId);

        if (! $found) {
            return;
        }

        WhatsappTarget::updateOrCreate(['chat_id' => $found['id']], [
            'name' => $found['name'],
            'type' => $found['type'],
            'is_default' => ! WhatsappTarget::exists(), // the first one is pre-ticked
        ]);

        unset($this->targets);
        Flux::toast(variant: 'success', text: $found['name'].' added.');
    }

    public function toggleDefault(int $id): void
    {
        $this->authorize('manage-whatsapp');
        $target = WhatsappTarget::findOrFail($id);
        $target->update(['is_default' => ! $target->is_default]);
        unset($this->targets);
    }

    public function renameTarget(int $id, string $name): void
    {
        $this->authorize('manage-whatsapp');
        $name = trim($name);

        if ($name !== '') {
            WhatsappTarget::findOrFail($id)->update(['name' => mb_substr($name, 0, 100)]);
        }

        unset($this->targets);
    }

    public function removeTarget(int $id): void
    {
        $this->authorize('manage-whatsapp');
        WhatsappTarget::findOrFail($id)->delete();
        unset($this->targets);
    }

    public function sendTest(int $id): void
    {
        $this->authorize('manage-whatsapp');

        $result = app(WhatsAppSender::class)->send(
            [$id],
            '✅ Test message from '.config('worship.team_name').' Strategy Planner. WhatsApp is connected.',
            'test',
            null,
            auth()->user(),
        )->first();

        $result?->status === 'failed'
            ? Flux::toast(variant: 'danger', text: 'Test failed: '.$result->error)
            : Flux::toast(variant: 'success', text: 'Test message sent to '.$result?->target_name.'.');

        unset($this->messages);
    }

    #[Computed]
    public function targets()
    {
        return WhatsappTarget::orderByDesc('is_default')->orderBy('name')->get();
    }

    #[Computed]
    public function messages()
    {
        return WhatsappMessage::with('sender:id,name')->latest()->limit(30)->get();
    }

    public function number(): string
    {
        return Phone::format(Phone::normalize((string) config('worship.whatsapp.number'))) ?: 'not set';
    }
};
?>

@php
    $status = $session['status'] ?? null;
    $statusColor = match ($status) { 'WORKING' => 'green', 'SCAN_QR_CODE', 'STARTING' => 'amber', 'FAILED' => 'red', default => 'zinc' };
    $statusLabel = match ($status) {
        'WORKING' => 'Connected', 'SCAN_QR_CODE' => 'Waiting for you to link', 'STARTING' => 'Starting…',
        'STOPPED' => 'Stopped', 'FAILED' => 'Failed (start again)', null => 'Not set up', default => $status,
    };
    $savedIds = $this->targets->pluck('chat_id')->all();
@endphp

<div class="flex flex-col gap-6" @if (in_array($status, ['SCAN_QR_CODE', 'STARTING'], true)) wire:poll.10s="poll" @endif>
    <div>
        <flux:heading size="xl" level="1">WhatsApp</flux:heading>
        <flux:subheading>Send roster, meeting, uniform and contribution announcements to your WhatsApp groups and channels.</flux:subheading>
    </div>

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$error" />
    @endif

    {{-- Connection --}}
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">Connection</flux:heading>
                <flux:text size="sm">Team number: <span class="font-medium">{{ $this->number() }}</span></flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:badge :color="$statusColor">{{ $statusLabel }}</flux:badge>
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshStatus" aria-label="Refresh status" />
            </div>
        </div>

        @if ($status === 'WORKING')
            @php $meId = $session['me']['id'] ?? null; @endphp
            <flux:text>Linked{{ ($session['me']['pushName'] ?? null) ? ' as '.$session['me']['pushName'] : '' }}{{ $meId ? ' ('.Str::before($meId, '@').')' : '' }}.</flux:text>
            <flux:button size="sm" variant="danger" icon="link-slash" wire:click="unlink" wire:confirm="Unlink WhatsApp? Sending will stop until it's linked again.">Unlink</flux:button>
        @elseif (in_array($status, [null, 'STOPPED', 'FAILED'], true))
            <flux:text>Start the connection, then link the team number from the phone that has it.</flux:text>
            <flux:button variant="primary" icon="play" wire:click="start">Start connection</flux:button>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading>Option 1 · Link with phone number</flux:heading>
                    <flux:text size="sm">Easiest when the team phone is in your hand. Get a code, then on that phone open <b>WhatsApp → Linked devices → Link a device → Link with phone number instead</b> and type it.</flux:text>
                    @if ($pairingCode)
                        <div class="rounded-lg bg-zinc-100 py-3 text-center font-mono text-3xl tracking-widest dark:bg-white/10">{{ $pairingCode }}</div>
                    @endif
                    <flux:button size="sm" icon="key" wire:click="requestCode">{{ $pairingCode ? 'New code' : 'Get pairing code' }}</flux:button>
                </div>
                <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading>Option 2 · Scan a QR code</flux:heading>
                    <flux:text size="sm">On the team phone: <b>WhatsApp → Linked devices → Link a device</b>, then scan. The code refreshes automatically.</flux:text>
                    @if ($qr)
                        <img src="{{ $qr }}" alt="WhatsApp QR code" class="mx-auto size-56 rounded-lg bg-white p-2">
                    @endif
                    <flux:button size="sm" icon="qr-code" wire:click="showQr">{{ $qr ? 'Refresh QR' : 'Show QR code' }}</flux:button>
                </div>
            </div>
        @endif
    </flux:card>

    {{-- Targets --}}
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">Groups &amp; channels</flux:heading>
                <flux:text size="sm">Where announcements can be sent. Ticked ones are pre-selected when sharing. Channels must be owned or administered by the team number.</flux:text>
            </div>
            <flux:button size="sm" icon="arrow-down-tray" wire:click="loadAvailable" :disabled="$status !== 'WORKING'">Load from WhatsApp</flux:button>
        </div>

        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @forelse ($this->targets as $t)
                <div class="flex flex-wrap items-center justify-between gap-2 py-2.5" wire:key="target-{{ $t->id }}">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:checkbox :checked="$t->is_default" wire:click="toggleDefault({{ $t->id }})" aria-label="Pre-select when sharing" />
                        <input type="text" value="{{ $t->name }}" x-on:change="$wire.renameTarget({{ $t->id }}, $event.target.value)"
                            class="min-w-0 rounded border border-transparent bg-transparent px-1 text-sm font-medium hover:border-zinc-200 focus:border-zinc-300 dark:hover:border-zinc-600">
                        <flux:badge size="sm" :color="$t->isChannel() ? 'violet' : 'green'">{{ $t->isChannel() ? 'Channel' : 'Group' }}</flux:badge>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="xs" icon="paper-airplane" wire:click="sendTest({{ $t->id }})" :disabled="$status !== 'WORKING'">Test</flux:button>
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeTarget({{ $t->id }})" wire:confirm="Remove {{ $t->name }}?" aria-label="Remove" />
                    </div>
                </div>
            @empty
                <flux:text size="sm">No groups or channels yet. Link WhatsApp, then “Load from WhatsApp”.</flux:text>
            @endforelse
        </div>

        @if ($availableLoaded)
            <div class="space-y-2 rounded-lg bg-zinc-50 p-3 dark:bg-white/5">
                <flux:text size="sm" class="font-medium">Available on {{ $this->number() }} ({{ count($available) }})</flux:text>
                <div class="max-h-72 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-700">
                    @forelse ($available as $a)
                        <div class="flex items-center justify-between gap-2 py-1.5" wire:key="avail-{{ md5($a['id']) }}">
                            <span class="truncate text-sm">{{ $a['name'] }} <span class="text-xs text-zinc-400">· {{ $a['type'] }}</span></span>
                            @if (in_array($a['id'], $savedIds, true))
                                <flux:badge size="sm" color="green" icon="check">Added</flux:badge>
                            @else
                                <flux:button size="xs" icon="plus" wire:click="addTarget('{{ $a['id'] }}')">Add</flux:button>
                            @endif
                        </div>
                    @empty
                        <flux:text size="sm">No groups, and no channels this number owns or administers.</flux:text>
                    @endforelse
                </div>
            </div>
        @endif
    </flux:card>

    {{-- Log --}}
    <flux:card class="space-y-3">
        <flux:heading size="lg">Recent messages</flux:heading>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @forelse ($this->messages as $m)
                <div class="flex flex-wrap items-start justify-between gap-2 py-2.5" wire:key="msg-{{ $m->id }}">
                    <div class="min-w-0">
                        <div class="text-sm"><span class="font-medium">{{ $m->target_name }}</span> <span class="text-xs text-zinc-500">· {{ $m->kind }}{{ $m->attachment ? ' · 📎 '.$m->attachment : '' }}</span></div>
                        <div class="truncate text-xs text-zinc-500">{{ Str::limit(str_replace("\n", ' · ', $m->body), 120) }}</div>
                        @if ($m->error)<div class="text-xs text-red-600 dark:text-red-400">{{ $m->error }}</div>@endif
                    </div>
                    <div class="text-end">
                        <flux:badge size="sm" :color="$m->color()">{{ ucfirst($m->status) }}</flux:badge>
                        <div class="mt-0.5 text-xs text-zinc-400">{{ $m->created_at->diffForHumans() }}{{ $m->sender ? ' · '.$m->sender->name : '' }}</div>
                    </div>
                </div>
            @empty
                <flux:text size="sm">Nothing sent yet.</flux:text>
            @endforelse
        </div>
    </flux:card>
</div>
