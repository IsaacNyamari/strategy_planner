<?php

use App\Contributions\ContributionLedger;
use App\Models\Member;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public Member $member;

    #[Url]
    public int $year;

    public function mount(Member $member): void
    {
        $this->member = $member;
        $this->year ??= (int) now()->year;
    }

    public function rendering($view): void
    {
        $view->title($this->member->name.' · Contributions');
    }

    #[On('payment-recorded')]
    public function refresh(): void
    {
        unset($this->statement);
    }

    public function updatedYear(): void
    {
        unset($this->statement);
    }

    public function sendReminder(): void
    {
        $this->authorize('manage-whatsapp');

        $balance = $this->statement['totals']['balance'];
        $text = "Hello {$this->member->name}, greetings from the ".config('worship.team_name').'. '
            .($balance > 0
                ? "This is a gentle reminder that your contributions balance for {$this->year} is ".ContributionLedger::money($balance).'. Thank you and God bless!'
                : "Thank you, your contributions for {$this->year} are up to date. God bless!");

        $result = app(\App\WhatsApp\WhatsAppSender::class)->sendToMember($this->member, $text, auth()->user());

        $result->status === 'failed'
            ? \Flux\Flux::toast(variant: 'danger', text: $result->error ?? 'Not sent.')
            : \Flux\Flux::toast(variant: 'success', text: 'Sent to '.$this->member->name.'. Replies appear in WhatsApp chats.');
    }

    #[Computed]
    public function statement(): array
    {
        return app(ContributionLedger::class)->statement($this->member, $this->year);
    }
};
?>

@php
    $money = fn ($v) => \App\Contributions\ContributionLedger::money($v);
    $st = $this->statement;
    $cellClass = [
        'paid' => 'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-300',
        'partial' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
        'due' => 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        'upcoming' => 'bg-zinc-50 text-zinc-400 dark:bg-white/5 dark:text-zinc-500',
        'n/a' => 'bg-zinc-50/50 text-zinc-300 dark:bg-white/[.02] dark:text-zinc-600',
    ];
    $statusLabel = ['paid' => 'Paid', 'partial' => 'Partly paid', 'due' => 'Due', 'upcoming' => 'Upcoming', 'n/a' => 'Not applicable'];
@endphp

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('contributions', ['year' => $year])" wire:navigate>Contributions</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $member->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex items-center gap-3">
                <flux:avatar size="lg" :name="$member->name" :initials="$member->initials()" color="auto" />
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl" level="1">{{ $member->name }}</flux:heading>
                        <flux:badge size="sm" :color="$member->status->color()">{{ $member->status->label() }}</flux:badge>
                    </div>
                    <flux:text>{{ $member->part ?: 'Member' }} · joined {{ $member->joined_at?->format('j M Y') ?? '—' }}
                        · @if ($member->phone)<a href="{{ $member->telUrl() }}" class="hover:underline">{{ $member->phoneDisplay() }}</a>@else<span class="text-red-600">no phone number</span>@endif
                    </flux:text>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="$set('year', {{ $year - 1 }})" aria-label="Previous year" />
                    <flux:heading size="lg" class="w-14 text-center">{{ $year }}</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="$set('year', {{ $year + 1 }})" aria-label="Next year" />
                </div>
                @if ($member->phone && auth()->user()->can('contributions.view'))
                    @php
                        $reminder = "Hello {$member->name}, greetings from the ".config('worship.team_name').". "
                            .($st['totals']['balance'] > 0
                                ? "This is a gentle reminder that your contributions balance for {$year} is ".$money($st['totals']['balance']).". Thank you and God bless!"
                                : "Thank you, your contributions for {$year} are up to date. God bless!");
                    @endphp
                    <flux:dropdown>
                        <flux:button icon="chat-bubble-left-ellipsis" icon:trailing="chevron-down">{{ $st['totals']['balance'] > 0 ? 'WhatsApp reminder' : 'WhatsApp thank-you' }}</flux:button>
                        <flux:menu>
                            @can('manage-whatsapp')
                                <flux:menu.item icon="paper-airplane" wire:click="sendReminder" wire:confirm="Send this message to {{ $member->name }} from the team WhatsApp number?">Send from team number</flux:menu.item>
                            @endcan
                            <flux:menu.item icon="arrow-top-right-on-square" :href="$member->whatsappUrl($reminder)" target="_blank">Open in my WhatsApp</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
                <flux:button icon="arrow-down-tray" :href="route('exports.statement', ['member' => $member, 'year' => $year])">Statement PDF</flux:button>
                @can('contributions.manage')
                    <flux:button variant="primary" icon="plus" x-on:click="$dispatch('record-payment', { memberId: {{ $member->id }}, year: {{ $year }} })">Record payment</flux:button>
                @endcan
            </div>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="space-y-1">
            <flux:text>Expected in {{ $year }} (to date)</flux:text>
            <flux:heading size="xl">{{ $money($st['totals']['expected']) }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Paid in {{ $year }}</flux:text>
            <flux:heading size="xl" class="text-green-700! dark:text-green-400!">{{ $money($st['totals']['paid']) }}</flux:heading>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Outstanding</flux:text>
            <flux:heading size="xl" @class(['text-red-600! dark:text-red-400!' => $st['totals']['balance'] > 0])>{{ $money($st['totals']['balance']) }}</flux:heading>
            <flux:text size="sm">{{ $st['totals']['balance'] > 0 ? 'arrears + unpaid fees' : 'all up to date' }}</flux:text>
        </flux:card>
    </div>

    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <flux:heading size="lg">Monthly contributions</flux:heading>
                <flux:text size="sm">{{ $money($st['rate']->monthly_amount) }} per month · {{ $money($st['monthly']['paid']) }} paid · {{ $money($st['monthly']['arrears']) }} in arrears</flux:text>
            </div>
            <button type="button" x-on:click="$dispatch('record-payment', { memberId: {{ $member->id }}, type: 'registration', year: {{ $year }} })"
                class="rounded-lg px-3 py-2 text-start text-sm {{ $cellClass[$st['registration']['status']] }}">
                <div class="text-xs opacity-80">Registration fee {{ $year }}</div>
                <div class="font-medium">{{ $statusLabel[$st['registration']['status']] }} · {{ $money($st['registration']['paid']) }}{{ $st['registration']['fee'] ? ' of '.$money($st['registration']['fee']) : '' }}</div>
            </button>
        </div>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-6 lg:grid-cols-12">
            @foreach ($st['months'] as $m => $cell)
                <button type="button" x-on:click="$dispatch('record-payment', { memberId: {{ $member->id }}, type: 'monthly', year: {{ $year }}, month: {{ $m }} })"
                    class="rounded-lg p-2 text-center {{ $cellClass[$cell['status']] }}">
                    <div class="text-xs font-medium">{{ \App\Contributions\ContributionLedger::monthName($m, 'M') }}</div>
                    <div class="mt-0.5 text-sm tabular-nums">{{ $cell['paid'] > 0 ? number_format($cell['paid']) : ($cell['status'] === 'due' ? 'Due' : '—') }}</div>
                </button>
            @endforeach
        </div>
    </flux:card>

    <flux:card class="space-y-3">
        <flux:heading size="lg">Arising contributions</flux:heading>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @forelse ($st['specials'] as $row)
                <div class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <div>
                        <div class="font-medium">{{ $row['contribution']->title }}</div>
                        <flux:text size="sm">{{ $row['expected'] > 0 ? $money($row['expected']).' expected' : 'Any amount' }}{{ $row['contribution']->due_date ? ' · due '.$row['contribution']->due_date->format('j M') : '' }}</flux:text>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm tabular-nums">{{ $money($row['paid']) }}</span>
                        <span class="rounded px-2 py-0.5 text-xs {{ $cellClass[$row['status']] }}">{{ $statusLabel[$row['status']] }}</span>
                        @if ($row['status'] !== 'paid' && $row['contribution']->is_open)
                            <flux:button size="xs" icon="plus" x-on:click="$dispatch('record-payment', { memberId: {{ $member->id }}, type: 'special', specialId: {{ $row['contribution']->id }}, year: {{ $year }} })">Record</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <flux:text size="sm">No arising contributions in {{ $year }}.</flux:text>
            @endforelse
        </div>
    </flux:card>

    <flux:card class="space-y-3">
        <flux:heading size="lg">Payment history {{ $year }}</flux:heading>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @forelse ($st['payments'] as $p)
                <div class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <div class="flex items-center gap-3">
                        <span class="w-20 text-sm text-zinc-500">{{ $p->paid_on->format('j M') }}</span>
                        <flux:badge size="sm" :color="$p->type->color()">{{ $p->forLabel() }}</flux:badge>
                        <span class="text-xs text-zinc-500">{{ $p->method->label() }}{{ $p->reference ? ' · '.$p->reference : '' }}</span>
                    </div>
                    <span class="font-medium tabular-nums">{{ $money($p->amount) }}</span>
                </div>
            @empty
                <flux:text size="sm">No payments recorded in {{ $year }}.</flux:text>
            @endforelse
        </div>
    </flux:card>

    @can('contributions.manage')
        <livewire:payment-form />
    @endcan
</div>
