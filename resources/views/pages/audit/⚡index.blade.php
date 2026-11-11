<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit trail')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $user = '';

    #[Url]
    public string $event = '';

    #[Url]
    public string $area = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public ?int $openId = null;

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        $tz = config('worship.timezone');

        return AuditLog::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', "%{$this->search}%")->orWhere('subject_label', 'like', "%{$this->search}%")->orWhere('ip', 'like', "%{$this->search}%")))
            ->when($this->user !== '', fn ($q) => $this->user === 'system' ? $q->whereNull('user_id') : $q->where('user_id', (int) $this->user))
            ->when($this->event, fn ($q) => $q->where('event', $this->event))
            ->when($this->area, fn ($q) => $q->where('subject_type', $this->area))
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', \Carbon\CarbonImmutable::parse($this->from, $tz)->startOfDay()->setTimezone((string) config('app.timezone'))))
            ->when($this->to, fn ($q) => $q->where('created_at', '<', \Carbon\CarbonImmutable::parse($this->to, $tz)->addDay()->startOfDay()->setTimezone((string) config('app.timezone'))))
            ->latest('id')
            ->paginate(40);
    }

    #[Computed]
    public function users()
    {
        return User::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function areas(): array
    {
        return AuditLog::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
            ->mapWithKeys(fn (string $t) => [$t => Str::headline(class_basename($t))])->sort()->all();
    }

    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }
}; ?>

@php
    $events = ['created' => 'Created', 'updated' => 'Changed', 'deleted' => 'Deleted', 'login' => 'Logged in', 'logout' => 'Logged out', 'login_failed' => 'Failed login', 'downloaded' => 'Downloaded', 'roles_changed' => 'Roles changed', 'permissions_changed' => 'Permissions changed', 'role_created' => 'Role created', 'role_deleted' => 'Role deleted'];
    $tz = config('worship.timezone');
@endphp

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Audit trail</flux:heading>
        <flux:subheading>Who did what, when, and how — every change, login and download. Entries cannot be edited or deleted.</flux:subheading>
    </div>

    <flux:card class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Text or IP" icon="magnifying-glass" class="lg:col-span-2" />
        <flux:select wire:model.live="user" label="Who">
            <flux:select.option value="">Everyone</flux:select.option>
            <flux:select.option value="system">System / not logged in</flux:select.option>
            @foreach ($this->users as $u)
                <flux:select.option value="{{ $u->id }}">{{ $u->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="event" label="What">
            <flux:select.option value="">Anything</flux:select.option>
            @foreach ($events as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="area" label="Record">
            <flux:select.option value="">Any</flux:select.option>
            @foreach ($this->areas as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="grid grid-cols-2 gap-2">
            <flux:input wire:model.live="from" type="date" label="From" />
            <flux:input wire:model.live="to" type="date" label="To" />
        </div>
    </flux:card>

    <flux:card class="p-0!">
        <flux:table :paginate="$this->logs" class="[&_th:first-child]:ps-5 [&_td:first-child]:ps-5 [&_th:last-child]:pe-5 [&_td:last-child]:pe-5" container:class="[&_[data-flux-pagination]]:px-5 [&_[data-flux-pagination]]:pb-3">
            <flux:table.columns>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>Who</flux:table.column>
                <flux:table.column>What</flux:table.column>
                <flux:table.column>How</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->logs as $log)
                    <flux:table.row wire:key="log-{{ $log->id }}">
                        <flux:table.cell class="whitespace-nowrap align-top">
                            <div>{{ $log->created_at->timezone($tz)->format('j M Y') }}</div>
                            <div class="text-xs text-zinc-500">{{ $log->created_at->timezone($tz)->format('H:i:s') }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="align-top">{{ $log->user_name ?? 'System' }}</flux:table.cell>
                        <flux:table.cell class="align-top">
                            <div class="flex items-start gap-2">
                                <flux:badge size="sm" :color="$log->color()">{{ $events[$log->event] ?? Str::headline($log->event) }}</flux:badge>
                                <div class="min-w-0">
                                    <div class="text-sm text-zinc-800 dark:text-white">{{ $log->description }}</div>
                                    @if ($log->changes)
                                        <button type="button" wire:click="toggle({{ $log->id }})" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ $openId === $log->id ? 'Hide details' : count($log->changes).' '.Str::plural('field', count($log->changes)) }}</button>
                                        @if ($openId === $log->id)
                                            <table class="mt-2 text-xs">
                                                @foreach ($log->changes as $field => $change)
                                                    <tr class="align-top">
                                                        <td class="pe-3 font-medium text-zinc-600 dark:text-zinc-300">{{ Str::headline($field) }}</td>
                                                        <td class="pe-2 text-red-600 line-through">{{ is_scalar($change['old'] ?? null) ? Str::limit((string) $change['old'], 120) : '' }}</td>
                                                        <td class="text-green-700 dark:text-green-400">{{ is_scalar($change['new'] ?? null) ? Str::limit((string) $change['new'], 120) : '' }}</td>
                                                    </tr>
                                                @endforeach
                                            </table>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="align-top text-xs text-zinc-500">
                            <div>{{ $log->device() }}</div>
                            <div>{{ $log->ip ?? ucfirst($log->via) }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4" class="text-center text-zinc-500">Nothing recorded yet.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
