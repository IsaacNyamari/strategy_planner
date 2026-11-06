<?php

use App\Contributions\ContributionLedger;
use App\Enums\MemberStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\ContributionRate;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SpecialContribution;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Shared "Record payment" modal. Open it from anywhere with:
 *   $dispatch('record-payment', { memberId, type, year, month, specialId })
 * It dispatches 'payment-recorded' after saving.
 */
new class extends Component {
    public ?int $memberId = null;
    public string $type = 'monthly';
    public int $year;
    /** @var list<int|string> */
    public array $months = [];
    public ?int $specialId = null;
    public ?string $amount = null;
    public ?string $paidOn = null;
    public string $method = 'mpesa';
    public string $reference = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->paidOn = today()->toDateString();
    }

    #[On('record-payment')]
    public function open(?int $memberId = null, string $type = 'monthly', ?int $year = null, ?int $month = null, ?int $specialId = null): void
    {
        $this->resetValidation();
        $this->reset('reference', 'notes');
        $this->memberId = $memberId;
        $this->type = $type;
        $this->year = $year ?? (int) now()->year;
        $this->months = $month ? [$month] : [];
        $this->specialId = $specialId;
        $this->paidOn = today()->toDateString();
        $this->method = 'mpesa';
        $this->suggestAmount();

        Flux::modal('record-payment')->show();
    }

    public function updated($property): void
    {
        if (in_array($property, ['type', 'year', 'specialId', 'memberId'], true)) {
            $this->suggestAmount();
        }
    }

    /** Pre-fill the expected amount (the remaining balance where we know it). */
    protected function suggestAmount(): void
    {
        $rate = ContributionRate::forYear($this->year);

        $expected = match ($this->type) {
            'monthly' => (float) $rate->monthly_amount,
            'registration' => (float) $rate->registration_fee - ($this->memberId ? (float) Payment::where('member_id', $this->memberId)->where('type', 'registration')->where('year', $this->year)->sum('amount') : 0),
            'special' => $this->specialId
                ? (float) SpecialContribution::find($this->specialId)?->amount_per_member - ($this->memberId ? (float) Payment::where('member_id', $this->memberId)->where('special_contribution_id', $this->specialId)->sum('amount') : 0)
                : 0,
            default => 0,
        };

        $this->amount = $expected > 0 ? (string) (0 + round($expected, 2)) : null;
    }

    #[Computed]
    public function members()
    {
        return Member::where('status', '!=', MemberStatus::INACTIVE->value)
            ->when($this->memberId, fn ($q) => $q->orWhere('id', $this->memberId))
            ->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function specials()
    {
        return SpecialContribution::where('is_open', true)
            ->when($this->specialId, fn ($q) => $q->orWhere('id', $this->specialId))
            ->orderByDesc('created_at')->get(['id', 'title', 'amount_per_member']);
    }

    /** Months already fully paid for this member/year (shown ticked). */
    #[Computed]
    public function paidMonths(): array
    {
        if (! $this->memberId || $this->type !== 'monthly') {
            return [];
        }

        $rate = (float) ContributionRate::forYear($this->year)->monthly_amount;

        return Payment::where('member_id', $this->memberId)->where('type', 'monthly')->where('year', $this->year)
            ->selectRaw('month, sum(amount) as total')->groupBy('month')->get()
            ->filter(fn ($p) => $rate > 0 && (float) $p->total >= $rate)
            ->pluck('month')->map(fn ($m) => (int) $m)->all();
    }

    public function save(): void
    {
        $this->validate([
            'memberId' => ['required', 'exists:members,id'],
            'type' => ['required', Rule::enum(PaymentType::class)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'months' => [Rule::requiredIf($this->type === 'monthly'), 'array'],
            'months.*' => ['integer', 'between:1,12'],
            'specialId' => [Rule::requiredIf($this->type === 'special'), 'nullable', 'exists:special_contributions,id'],
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'months.required' => 'Pick at least one month.',
            'specialId.required' => 'Choose which arising contribution this is for.',
        ], [
            'memberId' => 'member', 'paidOn' => 'date paid', 'specialId' => 'contribution',
        ]);

        $base = [
            'member_id' => $this->memberId,
            'type' => $this->type,
            'year' => $this->year,
            'amount' => $this->amount,
            'paid_on' => $this->paidOn,
            'method' => $this->method,
            'reference' => $this->reference ?: null,
            'notes' => $this->notes ?: null,
            'recorded_by' => auth()->id(),
        ];

        if ($this->type === 'monthly') {
            // One record per month so each month's status is exact.
            foreach (array_unique(array_map('intval', $this->months)) as $month) {
                Payment::create([...$base, 'month' => $month]);
            }
            $count = count(array_unique($this->months));
            $total = $count * (float) $this->amount;
        } else {
            Payment::create([...$base, 'special_contribution_id' => $this->type === 'special' ? $this->specialId : null]);
            $total = (float) $this->amount;
        }

        Flux::modal('record-payment')->close();
        Flux::toast(variant: 'success', text: ContributionLedger::money($total).' recorded for '.Member::find($this->memberId)?->name.'.');
        $this->dispatch('payment-recorded');
    }
};
?>

<div>
    <flux:modal name="record-payment" class="w-full md:w-xl">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">Record payment</flux:heading>
                <flux:text class="mt-1">Monthly contribution, yearly registration fee, or an arising contribution.</flux:text>
            </div>

            <flux:select wire:model.live="memberId" label="Member" placeholder="Choose a member…">
                @foreach ($this->members as $m)
                    <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model.live="type" label="For">
                    @foreach (\App\Enums\PaymentType::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model.live="year" type="number" min="2000" max="2100" label="Year" />
            </div>

            @if ($type === 'monthly')
                <flux:field>
                    <flux:label>Months</flux:label>
                    <div class="grid grid-cols-4 gap-2 sm:grid-cols-6">
                        @foreach (range(1, 12) as $m)
                            @php $alreadyPaid = in_array($m, $this->paidMonths, true); @endphp
                            <label @class(['flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1.5 text-sm', 'border-green-300 bg-green-50 dark:border-green-500/40 dark:bg-green-500/10' => $alreadyPaid, 'border-zinc-200 dark:border-zinc-600' => ! $alreadyPaid])>
                                <input type="checkbox" wire:model="months" value="{{ $m }}" class="rounded">
                                {{ \App\Contributions\ContributionLedger::monthName($m, 'M') }}
                                @if ($alreadyPaid)<flux:icon.check variant="micro" class="text-green-600" />@endif
                            </label>
                        @endforeach
                    </div>
                    <flux:description>Green = already paid in full. Tick several months if they paid in advance or cleared arrears.</flux:description>
                    <flux:error name="months" />
                </flux:field>
            @endif

            @if ($type === 'special')
                <flux:select wire:model.live="specialId" label="Arising contribution" placeholder="Choose…">
                    @foreach ($this->specials as $s)
                        <flux:select.option :value="$s->id">{{ $s->title }}{{ $s->amount_per_member ? ' ('.\App\Contributions\ContributionLedger::money($s->amount_per_member).' each)' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="amount" type="number" step="0.01" min="1" :label="$type === 'monthly' ? 'Amount per month ('.\App\Contributions\ContributionLedger::currency().')' : 'Amount ('.\App\Contributions\ContributionLedger::currency().')'" />
                <flux:input wire:model="paidOn" type="date" label="Date paid" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="method" label="Method">
                    @foreach (\App\Enums\PaymentMethod::cases() as $pm)
                        <flux:select.option :value="$pm->value">{{ $pm->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="reference" label="Reference" placeholder="M-Pesa code, receipt no." />
            </div>

            <flux:input wire:model="notes" label="Notes (optional)" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">Record payment</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
