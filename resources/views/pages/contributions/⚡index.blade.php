<?php

use App\Contributions\ContributionLedger;
use App\Enums\PaymentType;
use App\Models\ContributionRate;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SpecialContribution;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Contributions')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'monthly';

    #[Url]
    public int $year;

    // Payments ledger filters
    #[Url(as: 'ptype')]
    public string $paymentType = '';

    #[Url(as: 'member')]
    public string $paymentMember = '';

    // Rate form
    public ?int $rateYear = null;
    public ?string $monthlyAmount = null;
    public ?string $registrationFee = null;

    // Arising contribution form
    public ?int $specialEditingId = null;
    public string $specialTitle = '';
    public string $specialDescription = '';
    public ?string $specialAmountEach = null;
    public ?string $specialTarget = null;
    public ?string $specialDue = null;

    public ?int $viewingSpecialId = null;

    public function mount(): void
    {
        $this->year ??= (int) now()->year;
    }

    #[On('payment-recorded')]
    public function refreshData(): void
    {
        unset($this->grid, $this->totals, $this->specials, $this->payments, $this->specialStatus);
    }

    public function updated($property): void
    {
        if (in_array($property, ['paymentType', 'paymentMember', 'year'], true)) {
            $this->resetPage();
            $this->refreshData();
        }
    }

    #[Computed]
    public function grid(): array
    {
        return app(ContributionLedger::class)->monthlyGrid($this->year);
    }

    #[Computed]
    public function totals(): array
    {
        return app(ContributionLedger::class)->yearTotals($this->year);
    }

    #[Computed]
    public function specials()
    {
        $ledger = app(ContributionLedger::class);

        return SpecialContribution::orderByDesc('is_open')->orderByDesc('created_at')->get()
            ->map(fn (SpecialContribution $s) => $ledger->specialStatus($s));
    }

    #[Computed]
    public function specialStatus(): ?array
    {
        $special = $this->viewingSpecialId ? SpecialContribution::find($this->viewingSpecialId) : null;

        return $special ? app(ContributionLedger::class)->specialStatus($special) : null;
    }

    #[Computed]
    public function payments()
    {
        return Payment::query()
            ->with(['member:id,name', 'specialContribution:id,title', 'recorder:id,name'])
            ->where('year', $this->year)
            ->when($this->paymentType, fn ($q) => $q->where('type', $this->paymentType))
            ->when($this->paymentMember, fn ($q) => $q->where('member_id', $this->paymentMember))
            ->latest('paid_on')->latest('id')
            ->paginate(25);
    }

    #[Computed]
    public function rates()
    {
        return ContributionRate::orderByDesc('year')->get();
    }

    #[Computed]
    public function allMembers()
    {
        return Member::orderBy('name')->get(['id', 'name']);
    }

    // --- Rates ------------------------------------------------------------

    public function editRate(?int $year = null): void
    {
        $rate = ContributionRate::forYear($year ?? $this->year);
        $this->rateYear = $rate->year;
        $this->monthlyAmount = $rate->exists ? (string) (0 + $rate->monthly_amount) : null;
        $this->registrationFee = $rate->exists ? (string) (0 + $rate->registration_fee) : null;
        $this->resetValidation();
        Flux::modal('rate-form')->show();
    }

    public function saveRate(): void
    {
        $this->validate([
            'rateYear' => ['required', 'integer', 'min:2000', 'max:2100'],
            'monthlyAmount' => ['required', 'numeric', 'min:0'],
            'registrationFee' => ['required', 'numeric', 'min:0'],
        ], attributes: ['rateYear' => 'year', 'monthlyAmount' => 'monthly amount', 'registrationFee' => 'registration fee']);

        ContributionRate::updateOrCreate(['year' => $this->rateYear], [
            'monthly_amount' => $this->monthlyAmount,
            'registration_fee' => $this->registrationFee,
        ]);

        Flux::modal('rate-form')->close();
        Flux::toast(variant: 'success', text: "Rates for {$this->rateYear} saved.");
        unset($this->rates);
        $this->refreshData();
    }

    // --- Arising contributions ---------------------------------------------

    public function newSpecial(): void
    {
        $this->reset('specialEditingId', 'specialTitle', 'specialDescription', 'specialAmountEach', 'specialTarget', 'specialDue');
        $this->resetValidation();
        Flux::modal('special-form')->show();
    }

    public function editSpecial(int $id): void
    {
        $s = SpecialContribution::findOrFail($id);
        $this->specialEditingId = $s->id;
        $this->specialTitle = $s->title;
        $this->specialDescription = (string) $s->description;
        $this->specialAmountEach = $s->amount_per_member !== null ? (string) (0 + $s->amount_per_member) : null;
        $this->specialTarget = $s->target_amount !== null ? (string) (0 + $s->target_amount) : null;
        $this->specialDue = $s->due_date?->toDateString();
        $this->resetValidation();
        Flux::modal('special-form')->show();
    }

    public function saveSpecial(): void
    {
        $this->validate([
            'specialTitle' => ['required', 'string', 'max:255'],
            'specialDescription' => ['nullable', 'string', 'max:2000'],
            'specialAmountEach' => ['nullable', 'numeric', 'min:0'],
            'specialTarget' => ['nullable', 'numeric', 'min:0'],
            'specialDue' => ['nullable', 'date'],
        ], attributes: ['specialTitle' => 'title', 'specialAmountEach' => 'amount per member', 'specialTarget' => 'target', 'specialDue' => 'due date']);

        SpecialContribution::updateOrCreate(['id' => $this->specialEditingId], [
            'title' => $this->specialTitle,
            'description' => $this->specialDescription ?: null,
            'amount_per_member' => $this->specialAmountEach ?: null,
            'target_amount' => $this->specialTarget ?: null,
            'due_date' => $this->specialDue ?: null,
        ]);

        Flux::modal('special-form')->close();
        Flux::toast(variant: 'success', text: 'Arising contribution saved.');
        $this->refreshData();
    }

    public function toggleSpecial(int $id): void
    {
        $s = SpecialContribution::findOrFail($id);
        $s->update(['is_open' => ! $s->is_open]);
        $this->refreshData();
    }

    public function deleteSpecial(int $id): void
    {
        SpecialContribution::findOrFail($id)->delete();
        Flux::toast(text: 'Arising contribution and its payments deleted.');
        $this->refreshData();
    }

    public function viewSpecial(int $id): void
    {
        $this->viewingSpecialId = $id;
        unset($this->specialStatus);
        Flux::modal('special-status')->show();
    }

    // --- Payments -----------------------------------------------------------

    public function deletePayment(int $id): void
    {
        Payment::findOrFail($id)->delete();
        Flux::toast(text: 'Payment deleted.');
        $this->refreshData();
    }
};
?>

@php
    $money = fn ($v) => \App\Contributions\ContributionLedger::money($v);
    $cellClass = [
        'paid' => 'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-300',
        'partial' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
        'due' => 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        'upcoming' => 'bg-zinc-50 text-zinc-400 dark:bg-white/5 dark:text-zinc-500',
        'n/a' => 'text-zinc-300 dark:text-zinc-600',
    ];
    $rate = $this->grid['rate'];
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Contributions</flux:heading>
            <flux:subheading>Monthly contributions, the yearly registration fee, and arising contributions.</flux:subheading>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1">
                <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="$set('year', {{ $year - 1 }})" aria-label="Previous year" />
                <flux:heading size="lg" class="w-14 text-center">{{ $year }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="$set('year', {{ $year + 1 }})" aria-label="Next year" />
            </div>
            <flux:dropdown>
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down">Download</flux:button>
                <flux:menu>
                    <flux:menu.heading>{{ $year }} monthly contributions</flux:menu.heading>
                    <flux:menu.item icon="document-text" :href="route('exports.contributions', ['format' => 'pdf', 'year' => $year])">PDF</flux:menu.item>
                    <flux:menu.item icon="table-cells" :href="route('exports.contributions', ['format' => 'xlsx', 'year' => $year])">Excel</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.heading>{{ $year }} payments ledger</flux:menu.heading>
                    <flux:menu.item icon="table-cells" :href="route('exports.payments', ['format' => 'xlsx', 'year' => $year])">Excel</flux:menu.item>
                    @can('send-whatsapp')
                        <flux:menu.separator />
                        <flux:menu.item icon="chat-bubble-left-right" x-on:click="$dispatch('whatsapp-share', { type: 'contributions', year: {{ $year }} })">Send totals to WhatsApp</flux:menu.item>
                    @endcan
                </flux:menu>
            </flux:dropdown>
            <flux:button variant="primary" icon="plus" x-on:click="$dispatch('record-payment', { year: {{ $year }} })">Record payment</flux:button>
        </div>
    </div>

    @if (! $rate->exists)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>No rates set for {{ $year }}</flux:callout.heading>
            <flux:callout.text>Set the monthly contribution and registration fee for {{ $year }} so balances can be worked out.</flux:callout.text>
            <x-slot name="actions"><flux:button size="sm" wire:click="editRate({{ $year }})">Set {{ $year }} rates</flux:button></x-slot>
        </flux:callout>
    @endif

    {{-- Summary tiles --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text>Received in {{ $year }}</flux:text>
            <flux:heading size="xl">{{ $money($this->totals['total']) }}</flux:heading>
            <flux:text size="sm">all contributions</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Monthly contributions</flux:text>
            <flux:heading size="xl">{{ $money($this->grid['totals']['paid']) }}</flux:heading>
            <flux:text size="sm" @class(['text-red-600! dark:text-red-400!' => $this->grid['totals']['arrears'] > 0])>{{ $money($this->grid['totals']['arrears']) }} in arrears</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Registration fees</flux:text>
            <flux:heading size="xl">{{ $money($this->grid['totals']['registration_paid']) }}</flux:heading>
            <flux:text size="sm">{{ $money($this->grid['totals']['registration_due']) }} outstanding</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Arising contributions</flux:text>
            <flux:heading size="xl">{{ $money($this->totals['byType']['special'] ?? 0) }}</flux:heading>
            <flux:text size="sm">{{ $this->specials->where('contribution.is_open', true)->count() }} open</flux:text>
        </flux:card>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 overflow-x-auto rounded-lg bg-zinc-100 p-1 dark:bg-white/5">
        @foreach (['monthly' => 'Monthly '.$year, 'arising' => 'Arising contributions', 'payments' => 'Payments', 'rates' => 'Rates'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['flex-1 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium', 'bg-white shadow-sm text-zinc-900 dark:bg-zinc-700 dark:text-white' => $tab === $key, 'text-zinc-500' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'monthly')
        <flux:card class="overflow-hidden p-0!">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-700">
                <flux:text size="sm">
                    {{ $money($rate->monthly_amount) }} per month · registration {{ $money($rate->registration_fee) }} per year
                    <flux:link class="ms-1 text-sm" wire:click="editRate({{ $year }})">change</flux:link>
                </flux:text>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="rounded px-1.5 py-0.5 {{ $cellClass['paid'] }}">Paid</span>
                    <span class="rounded px-1.5 py-0.5 {{ $cellClass['partial'] }}">Partial</span>
                    <span class="rounded px-1.5 py-0.5 {{ $cellClass['due'] }}">Due</span>
                    <span class="rounded px-1.5 py-0.5 {{ $cellClass['upcoming'] }}">Upcoming</span>
                    <span class="text-zinc-400">Click a cell to record a payment</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 text-xs text-zinc-500 dark:border-zinc-700">
                            <th class="sticky left-0 bg-white px-4 py-2 text-start font-medium dark:bg-zinc-900">Member</th>
                            <th class="px-2 py-2 font-medium">Reg.</th>
                            @foreach (range(1, 12) as $m)
                                <th class="px-1 py-2 font-medium">{{ \App\Contributions\ContributionLedger::monthName($m, 'M') }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-end font-medium">Paid</th>
                            <th class="px-4 py-2 text-end font-medium">Arrears</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->grid['rows'] as $row)
                            @php $member = $row['member']; @endphp
                            <tr class="border-b border-zinc-50 dark:border-zinc-800" wire:key="grid-{{ $member->id }}">
                                <td class="sticky left-0 bg-white px-4 py-1.5 dark:bg-zinc-900">
                                    <a href="{{ route('contributions.member', [$member, 'year' => $year]) }}" wire:navigate class="font-medium text-zinc-800 hover:underline dark:text-white">{{ $member->name }}</a>
                                    @if ($member->onProbation())<flux:badge size="sm" color="amber" class="ms-1">probation</flux:badge>@endif
                                </td>
                                <td class="px-1 py-1.5 text-center">
                                    <button type="button" x-on:click="$dispatch('record-payment', { memberId: {{ $member->id }}, type: 'registration', year: {{ $year }} })"
                                        class="w-full rounded px-1.5 py-1 text-xs {{ $cellClass[$row['registration_status']] }}" title="Registration fee: {{ $money($row['registration_paid']) }} paid">
                                        {{ match ($row['registration_status']) { 'paid' => '✓', 'partial' => (int) $row['registration_paid'], 'due' => 'Due', default => '–' } }}
                                    </button>
                                </td>
                                @foreach ($row['months'] as $m => $cell)
                                    <td class="px-0.5 py-1.5 text-center">
                                        <button type="button" x-on:click="$dispatch('record-payment', { memberId: {{ $member->id }}, type: 'monthly', year: {{ $year }}, month: {{ $m }} })"
                                            class="w-full min-w-9 rounded px-1 py-1 text-xs {{ $cellClass[$cell['status']] }}" title="{{ \App\Contributions\ContributionLedger::monthName($m, 'F') }}: {{ $money($cell['paid']) }} paid">
                                            {{ match ($cell['status']) { 'paid' => '✓', 'partial' => (int) $cell['paid'], 'due' => '•', 'upcoming' => '', default => '–' } }}
                                        </button>
                                    </td>
                                @endforeach
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ number_format($row['paid']) }}</td>
                                <td @class(['px-4 py-1.5 text-end tabular-nums font-medium', 'text-red-600 dark:text-red-400' => $row['arrears'] > 0, 'text-zinc-400' => $row['arrears'] <= 0])>{{ number_format($row['arrears']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="16" class="px-4 py-8 text-center text-zinc-500">No current members.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($this->grid['rows'])
                        <tfoot>
                            <tr class="bg-zinc-50 text-xs font-medium dark:bg-white/5">
                                <td class="sticky left-0 bg-zinc-50 px-4 py-2 dark:bg-zinc-800">Total ({{ \App\Contributions\ContributionLedger::currency() }})</td>
                                <td class="px-1 py-2 text-center tabular-nums">{{ number_format($this->grid['totals']['registration_paid']) }}</td>
                                @foreach ($this->grid['monthTotals'] as $total)
                                    <td class="px-0.5 py-2 text-center tabular-nums">{{ $total ? number_format($total) : '' }}</td>
                                @endforeach
                                <td class="px-3 py-2 text-end tabular-nums">{{ number_format($this->grid['totals']['paid']) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-red-600 dark:text-red-400">{{ number_format($this->grid['totals']['arrears']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </flux:card>
    @elseif ($tab === 'arising')
        <div class="flex justify-end">
            <flux:button icon="plus" wire:click="newSpecial">New arising contribution</flux:button>
        </div>
        @forelse ($this->specials as $st)
            @php $s = $st['contribution']; $pct = $st['expected'] > 0 ? min(100, round($st['collected'] / $st['expected'] * 100)) : 0; @endphp
            <flux:card wire:key="special-{{ $s->id }}" @class(['space-y-3', 'opacity-70' => ! $s->is_open])>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">{{ $s->title }}</flux:heading>
                            <flux:badge size="sm" :color="$s->is_open ? 'green' : 'zinc'">{{ $s->is_open ? 'Open' : 'Closed' }}</flux:badge>
                            @if ($s->due_date)
                                <flux:badge size="sm" :color="$s->is_open && $s->due_date->isPast() ? 'red' : 'zinc'" icon="calendar">due {{ $s->due_date->format('j M Y') }}</flux:badge>
                            @endif
                        </div>
                        @if ($s->description)<flux:text size="sm" class="mt-1">{{ $s->description }}</flux:text>@endif
                        <flux:text size="sm" class="mt-1">
                            {{ $s->amount_per_member ? $money($s->amount_per_member).' per member' : 'Any amount' }}
                            @if ($s->target_amount) · target {{ $money($s->target_amount) }} @endif
                        </flux:text>
                    </div>
                    <div class="flex items-center gap-1">
                        <flux:button size="sm" icon="users" wire:click="viewSpecial({{ $s->id }})">Who paid</flux:button>
                        <flux:button size="sm" icon="plus" x-on:click="$dispatch('record-payment', { type: 'special', specialId: {{ $s->id }}, year: {{ $year }} })">Payment</flux:button>
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="Actions" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="editSpecial({{ $s->id }})">Edit</flux:menu.item>
                                <flux:menu.item icon="document-text" :href="route('exports.special', ['special' => $s, 'format' => 'pdf'])">Download PDF</flux:menu.item>
                                <flux:menu.item icon="table-cells" :href="route('exports.special', ['special' => $s, 'format' => 'xlsx'])">Download Excel</flux:menu.item>
                                @can('send-whatsapp')
                                    <flux:menu.item icon="chat-bubble-left-right" x-on:click="$dispatch('whatsapp-share', { type: 'arising', id: {{ $s->id }} })">Announce on WhatsApp</flux:menu.item>
                                @endcan
                                <flux:menu.item :icon="$s->is_open ? 'lock-closed' : 'lock-open'" wire:click="toggleSpecial({{ $s->id }})">{{ $s->is_open ? 'Close' : 'Reopen' }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteSpecial({{ $s->id }})" wire:confirm="Delete {{ $s->title }} and all its payments?">Delete</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
                <div>
                    <div class="mb-1 flex justify-between text-sm">
                        <span><span class="font-medium">{{ $money($st['collected']) }}</span> collected{{ $st['expected'] > 0 ? ' of '.$money($st['expected']) : '' }}</span>
                        <span class="text-zinc-500">{{ $st['paid_count'] }} of {{ count($st['rows']) }} members paid</span>
                    </div>
                    <flux:progress :value="$pct" :color="$pct >= 100 ? 'green' : 'blue'" />
                </div>
            </flux:card>
        @empty
            <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
                <flux:icon.banknotes class="size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>No arising contributions</flux:heading>
                <flux:text>Create one for a uniform fund, a member's bereavement, a trip or any special need.</flux:text>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="newSpecial">New arising contribution</flux:button>
            </flux:card>
        @endforelse
    @elseif ($tab === 'payments')
        <div class="flex flex-wrap gap-3">
            <flux:select wire:model.live="paymentType" class="w-full sm:w-52">
                <flux:select.option value="">All types</flux:select.option>
                @foreach (\App\Enums\PaymentType::cases() as $t)
                    <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="paymentMember" class="w-full sm:w-56">
                <flux:select.option value="">All members</flux:select.option>
                @foreach ($this->allMembers as $m)
                    <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        @if ($this->payments->isEmpty())
            <flux:card class="py-10 text-center"><flux:text>No payments recorded for {{ $year }} yet.</flux:text></flux:card>
        @else
            <flux:card class="overflow-hidden p-0!">
                <flux:table :paginate="$this->payments" class="[&_th:first-child]:ps-5 [&_td:first-child]:ps-5 [&_th:last-child]:pe-5 [&_td:last-child]:pe-5" container:class="[&_[data-flux-pagination]]:px-5 [&_[data-flux-pagination]]:pb-3">
                    <flux:table.columns>
                        <flux:table.column>Date</flux:table.column>
                        <flux:table.column>Member</flux:table.column>
                        <flux:table.column>For</flux:table.column>
                        <flux:table.column align="end">Amount</flux:table.column>
                        <flux:table.column>Method</flux:table.column>
                        <flux:table.column>Reference</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->payments as $p)
                            <flux:table.row wire:key="payment-{{ $p->id }}">
                                <flux:table.cell>{{ $p->paid_on->format('j M Y') }}</flux:table.cell>
                                <flux:table.cell variant="strong">
                                    @if ($p->member)<a href="{{ route('contributions.member', [$p->member, 'year' => $year]) }}" wire:navigate class="hover:underline">{{ $p->member->name }}</a>@endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$p->type->color()" inset="top bottom">{{ $p->forLabel() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums font-medium">{{ $money($p->amount) }}</flux:table.cell>
                                <flux:table.cell>{{ $p->method->label() }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ $p->reference ?: '—' }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="xs" variant="ghost" icon="receipt-percent" inset="top bottom" :href="route('exports.receipt', $p)" aria-label="Download receipt" title="Download receipt" />
                                    <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="deletePayment({{ $p->id }})" wire:confirm="Delete this payment of {{ $money($p->amount) }}?" aria-label="Delete payment" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    @else
        <flux:card class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Contribution rates</flux:heading>
                    <flux:text size="sm">The monthly contribution and the registration fee (paid once a year) for each year.</flux:text>
                </div>
                <flux:button size="sm" icon="plus" wire:click="editRate({{ $this->rates->max('year') ? $this->rates->max('year') + 1 : $year }})">Add year</flux:button>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->rates as $r)
                    <div class="flex items-center justify-between py-3" wire:key="rate-{{ $r->year }}">
                        <div>
                            <div class="font-medium">{{ $r->year }}</div>
                            <flux:text size="sm">{{ $money($r->monthly_amount) }} per month ({{ $money($r->monthly_amount * 12) }} a year) · registration {{ $money($r->registration_fee) }}</flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRate({{ $r->year }})">Edit</flux:button>
                    </div>
                @empty
                    <flux:text>No rates yet. Add the current year to get started.</flux:text>
                @endforelse
            </div>
        </flux:card>
    @endif

    {{-- Rate form --}}
    <flux:modal name="rate-form" class="md:w-md">
        <form wire:submit="saveRate" class="space-y-5">
            <flux:heading size="lg">Rates for {{ $rateYear }}</flux:heading>
            <flux:input wire:model="rateYear" type="number" min="2000" max="2100" label="Year" />
            <flux:input wire:model="monthlyAmount" type="number" step="0.01" min="0" :label="'Monthly contribution ('.\App\Contributions\ContributionLedger::currency().')'" />
            <flux:input wire:model="registrationFee" type="number" step="0.01" min="0" :label="'Registration fee, once a year ('.\App\Contributions\ContributionLedger::currency().')'" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Arising contribution form --}}
    <flux:modal name="special-form" class="md:w-lg">
        <form wire:submit="saveSpecial" class="space-y-5">
            <flux:heading size="lg">{{ $specialEditingId ? 'Edit arising contribution' : 'New arising contribution' }}</flux:heading>
            <flux:input wire:model="specialTitle" label="Title" placeholder="e.g. New uniforms fund, Bereavement support" />
            <flux:textarea wire:model="specialDescription" label="Description" rows="2" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="specialAmountEach" type="number" step="0.01" min="0" :label="'Amount per member ('.\App\Contributions\ContributionLedger::currency().')'" description="Leave empty if any amount is welcome." />
                <flux:input wire:model="specialTarget" type="number" step="0.01" min="0" :label="'Overall target ('.\App\Contributions\ContributionLedger::currency().')'" />
            </div>
            <flux:input wire:model="specialDue" type="date" label="Due date" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Who paid an arising contribution --}}
    <flux:modal name="special-status" class="w-full md:w-2xl">
        @if ($st = $this->specialStatus)
            <div class="space-y-4">
                <div class="pe-8">
                    <flux:heading size="lg">{{ $st['contribution']->title }}</flux:heading>
                    <flux:text>{{ $money($st['collected']) }} collected · {{ $st['paid_count'] }} of {{ count($st['rows']) }} paid in full</flux:text>
                </div>
                <div class="max-h-[60vh] divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-700">
                    @foreach ($st['rows'] as $row)
                        <div class="flex items-center justify-between gap-3 py-2" wire:key="sp-{{ $row['member']->id }}">
                            <span class="text-sm font-medium">{{ $row['member']->name }}</span>
                            <div class="flex items-center gap-2">
                                <span class="text-sm tabular-nums">{{ $money($row['paid']) }}</span>
                                <flux:badge size="sm" :color="match ($row['status']) { 'paid' => 'green', 'partial' => 'amber', default => 'red' }">
                                    {{ match ($row['status']) { 'paid' => 'Paid', 'partial' => 'Partial', default => 'Not paid' } }}
                                </flux:badge>
                                @if ($row['status'] !== 'paid')
                                    <flux:button size="xs" icon="plus" x-on:click="$flux.modal('special-status').close(); $dispatch('record-payment', { memberId: {{ $row['member']->id }}, type: 'special', specialId: {{ $st['contribution']->id }}, year: {{ $year }} })">Record</flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </flux:modal>

    <livewire:payment-form />
</div>
