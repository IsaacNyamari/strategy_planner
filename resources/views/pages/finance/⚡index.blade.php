<?php

use App\Contributions\ContributionLedger;
use App\Enums\PaymentMethod;
use App\Finance\BalanceSheet;
use App\Models\CashEntry;
use App\Models\SpecialContribution;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Balance sheet')] class extends Component {
    use WithFileUploads;

    #[Url]
    public int $year;

    #[Url]
    public int $month = 0; // 0 = whole year

    // Money in / out form
    public ?int $editingId = null;
    public string $direction = 'out';
    public string $category = '';
    public string $description = '';
    public string $party = '';
    public ?string $amount = null;
    public string $entryDate = '';
    public string $method = 'mpesa';
    public string $reference = '';
    public ?int $fundId = null;
    public $receipt = null;
    public bool $removeReceipt = false;

    public function mount(): void
    {
        $this->year ??= (int) now()->year;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['year', 'month'], true)) {
            $this->month = max(0, min(12, $this->month));
            unset($this->sheet);
        }
    }

    #[Computed]
    public function sheet(): BalanceSheet
    {
        return BalanceSheet::for($this->year, $this->month ?: null);
    }

    #[Computed]
    public function funds()
    {
        return SpecialContribution::query()->orderByDesc('created_at')->get(['id', 'title']);
    }

    public function shiftMonth(int $by): void
    {
        $d = now()->setDate($this->year, $this->month ?: 1, 1)->addMonths($by);
        [$this->year, $this->month] = [(int) $d->year, (int) $d->month];
        unset($this->sheet);
    }

    public function newEntry(string $direction): void
    {
        $this->resetErrorBag();
        $this->reset(['editingId', 'category', 'description', 'party', 'amount', 'reference', 'fundId', 'receipt', 'removeReceipt']);
        $this->direction = $direction === 'in' ? 'in' : 'out';
        $this->method = PaymentMethod::MPESA->value;
        $this->entryDate = now()->timezone(config('worship.timezone'))->toDateString();
        Flux::modal('cash-entry')->show();
    }

    public function edit(int $id): void
    {
        $e = CashEntry::findOrFail($id);
        $this->resetErrorBag();
        $this->reset(['receipt', 'removeReceipt']);
        $this->editingId = $e->id;
        $this->direction = $e->direction;
        $this->category = $e->category;
        $this->description = $e->description;
        $this->party = (string) $e->party;
        $this->amount = (string) (float) $e->amount;
        $this->entryDate = $e->entry_date->toDateString();
        $this->method = $e->method->value;
        $this->reference = (string) $e->reference;
        $this->fundId = $e->special_contribution_id;
        Flux::modal('cash-entry')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'category' => ['required', 'string', 'max:40'],
            'description' => ['required', 'string', 'max:255'],
            'party' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'entryDate' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'fundId' => ['nullable', 'exists:special_contributions,id'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
        ], [], ['entryDate' => 'date', 'fundId' => 'fund']);

        $entry = $this->editingId ? CashEntry::findOrFail($this->editingId) : new CashEntry(['user_id' => auth()->id()]);
        $entry->fill([
            'direction' => $data['direction'],
            'category' => $data['category'],
            'description' => $data['description'],
            'party' => $data['party'] ?: null,
            'amount' => $data['amount'],
            'entry_date' => $data['entryDate'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?: null,
            'special_contribution_id' => $data['fundId'],
        ]);

        if (($this->receipt || $this->removeReceipt) && $entry->receipt_path) {
            Storage::disk('local')->delete($entry->receipt_path);
            $entry->receipt_path = $entry->receipt_name = null;
        }
        if ($this->receipt) {
            $entry->receipt_path = $this->receipt->store('cash-receipts', 'local');
            $entry->receipt_name = $this->receipt->getClientOriginalName();
        }

        $entry->save();

        Flux::modal('cash-entry')->close();
        Flux::toast(variant: 'success', text: ($entry->isIncome() ? 'Money in' : 'Expense').' saved.');
        unset($this->sheet);
    }

    public function delete(int $id): void
    {
        CashEntry::findOrFail($id)->delete();
        Flux::toast(text: 'Entry deleted.');
        unset($this->sheet);
    }
}; ?>

@php
    $money = fn ($v) => ContributionLedger::money($v);
    $sheet = $this->sheet;
    $summary = $sheet->summary();
    $entries = $sheet->entries()->reverse();
    $funds = $sheet->funds();
    $params = ['year' => $year, 'month' => $month ?: null];
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Balance sheet</flux:heading>
            <flux:subheading>All money received and spent, with what is left in hand.</flux:subheading>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1">
                <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="{{ $month ? 'shiftMonth(-1)' : '$set(\'year\', '.($year - 1).')' }}" aria-label="Previous" />
                <flux:select size="sm" wire:model.live="month" class="w-32">
                    <flux:select.option value="0">Whole year</flux:select.option>
                    @foreach (range(1, 12) as $m)
                        <flux:select.option value="{{ $m }}">{{ ContributionLedger::monthName($m, 'F') }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:heading size="lg" class="w-14 text-center">{{ $year }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="{{ $month ? 'shiftMonth(1)' : '$set(\'year\', '.($year + 1).')' }}" aria-label="Next" />
            </div>
            <flux:dropdown>
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down">Download</flux:button>
                <flux:menu>
                    <flux:menu.heading>{{ $sheet->label() }}</flux:menu.heading>
                    <flux:menu.item icon="document-text" :href="route('exports.balance', ['format' => 'pdf', ...$params])">Balance sheet (PDF)</flux:menu.item>
                    <flux:menu.item icon="table-cells" :href="route('exports.balance', ['format' => 'xlsx', ...$params])">Cash book (Excel)</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item icon="archive-box-arrow-down" :href="route('exports.receipts', $params)">All receipts (ZIP)</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
            <flux:button icon="plus" wire:click="newEntry('in')">Money in</flux:button>
            <flux:button variant="primary" icon="minus" wire:click="newEntry('out')">Expense</flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text>Brought forward</flux:text>
            <flux:heading size="xl">{{ $money($summary['opening']) }}</flux:heading>
            <flux:text size="sm">before {{ $sheet->from->format('j M Y') }}</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Money in</flux:text>
            <flux:heading size="xl" class="text-green-700! dark:text-green-400!">+ {{ $money($summary['totalIn']) }}</flux:heading>
            <flux:text size="sm">contributions & other income</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Money out</flux:text>
            <flux:heading size="xl" class="text-red-700! dark:text-red-400!">− {{ $money($summary['totalOut']) }}</flux:heading>
            <flux:text size="sm">expenses</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Balance in hand</flux:text>
            <flux:heading size="xl">{{ $money($summary['closing']) }}</flux:heading>
            <flux:text size="sm">at {{ $sheet->to->format('j M Y') }}</flux:text>
        </flux:card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading size="lg">Where the money came from</flux:heading>
            @forelse ($summary['income'] as $line)
                <div class="flex justify-between text-sm"><span>{{ $line['label'] }}</span><span class="font-medium tabular-nums text-green-700 dark:text-green-400">{{ $money($line['amount']) }}</span></div>
            @empty
                <flux:text>Nothing received in {{ $sheet->label() }}.</flux:text>
            @endforelse
            <flux:separator />
            <div class="flex justify-between font-semibold"><span>Total in</span><span class="tabular-nums">{{ $money($summary['totalIn']) }}</span></div>
        </flux:card>
        <flux:card class="space-y-3">
            <flux:heading size="lg">Where the money went</flux:heading>
            @forelse ($summary['expenses'] as $line)
                <div class="flex justify-between text-sm"><span>{{ $line['label'] }}</span><span class="font-medium tabular-nums text-red-700 dark:text-red-400">{{ $money($line['amount']) }}</span></div>
            @empty
                <flux:text>Nothing spent in {{ $sheet->label() }}.</flux:text>
            @endforelse
            <flux:separator />
            <div class="flex justify-between font-semibold"><span>Total out</span><span class="tabular-nums">{{ $money($summary['totalOut']) }}</span></div>
        </flux:card>
    </div>

    @if ($funds)
        <flux:card class="space-y-3">
            <div>
                <flux:heading size="lg">Arising contribution funds</flux:heading>
                <flux:text size="sm">What was collected for each arising contribution and what has been spent from it.</flux:text>
            </div>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Fund</flux:table.column>
                    <flux:table.column align="end">Collected</flux:table.column>
                    <flux:table.column align="end">Spent</flux:table.column>
                    <flux:table.column align="end">Left</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($funds as $f)
                        <flux:table.row>
                            <flux:table.cell variant="strong">{{ $f['title'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $money($f['collected']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $money($f['spent']) }}</flux:table.cell>
                            <flux:table.cell align="end" @class(['tabular-nums font-semibold', 'text-red-600' => $f['balance'] < 0])>{{ $money($f['balance']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <flux:card class="space-y-3 p-0!">
        <div class="px-5 pt-5">
            <flux:heading size="lg">Cash book</flux:heading>
            <flux:text size="sm">Every entry in {{ $sheet->label() }}, newest first, with the balance after it. Member payments come from Contributions.</flux:text>
        </div>
        @if ($entries->isEmpty())
            <flux:text class="px-5 pb-5">No money in or out yet for {{ $sheet->label() }}.</flux:text>
        @else
            <flux:table class="[&_th:first-child]:ps-5 [&_td:first-child]:ps-5 [&_th:last-child]:pe-5 [&_td:last-child]:pe-5">
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Details</flux:table.column>
                    <flux:table.column align="end">In</flux:table.column>
                    <flux:table.column align="end">Out</flux:table.column>
                    <flux:table.column align="end">Balance</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($entries as $e)
                        <flux:table.row wire:key="{{ $e['payment'] ? 'p'.$e['payment']->id : 'e'.$e['entry']->id }}">
                            <flux:table.cell class="whitespace-nowrap">{{ $e['date']->format('j M Y') }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $e['description'] }}</div>
                                <div class="text-xs text-zinc-500">{{ $e['category'] }}{{ $e['party'] ? ' · '.$e['party'] : '' }} · {{ $e['method'] }}{{ $e['reference'] ? ' '.$e['reference'] : '' }}</div>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums text-green-700 dark:text-green-400">{{ $e['in'] ? number_format($e['amount']) : '' }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums text-red-700 dark:text-red-400">{{ $e['in'] ? '' : number_format($e['amount']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums font-medium">{{ number_format($e['balance']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap">
                                @if ($e['payment'])
                                    <flux:button size="xs" variant="ghost" icon="receipt-percent" inset="top bottom" :href="route('exports.receipt', $e['payment'])" title="Download receipt {{ $e['receipt'] }}" aria-label="Download receipt" />
                                @else
                                    @if ($e['entry']->receipt_path)
                                        <flux:button size="xs" variant="ghost" icon="paper-clip" inset="top bottom" :href="route('exports.attachment', $e['entry'])" title="Download the attached receipt" aria-label="Download attached receipt" />
                                    @endif
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="edit({{ $e['entry']->id }})" aria-label="Edit" />
                                    <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="delete({{ $e['entry']->id }})" wire:confirm="Delete this entry of {{ $money($e['amount']) }}?" aria-label="Delete" />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <flux:modal name="cash-entry" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit' : 'Record' }} {{ $direction === 'in' ? 'money in' : 'an expense' }}</flux:heading>
            <flux:text size="sm">{{ $direction === 'in' ? 'Offerings, donations, fundraising… (member contributions are recorded under Contributions).' : 'Anything paid out of the team\'s money. Attach the receipt so every shilling can be traced.' }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="category" label="Category" placeholder="Choose…">
                    @foreach ($direction === 'in' ? CashEntry::IN_CATEGORIES : CashEntry::OUT_CATEGORIES as $c)
                        <flux:select.option>{{ $c }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="amount" type="number" step="0.01" min="1" label="Amount ({{ ContributionLedger::currency() }})" />
            </div>
            <flux:input wire:model="description" label="What for" placeholder="{{ $direction === 'in' ? 'e.g. Sunday offering for the team' : 'e.g. 12 navy ties for the men' }}" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="party" :label="$direction === 'in' ? 'Received from' : 'Paid to'" />
                <flux:input wire:model="entryDate" type="date" label="Date" />
                <flux:select wire:model="method" label="Method">
                    @foreach (PaymentMethod::cases() as $m)
                        <flux:select.option value="{{ $m->value }}">{{ $m->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="reference" label="Reference" placeholder="M-Pesa code, cheque no…" />
            </div>
            @if ($this->funds->isNotEmpty())
                <flux:select wire:model="fundId" label="Arising contribution fund (optional)">
                    <flux:select.option value="">General team funds</flux:select.option>
                    @foreach ($this->funds as $f)
                        <flux:select.option value="{{ $f->id }}">{{ $f->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:input type="file" wire:model="receipt" label="Receipt (photo or PDF)" accept="image/*,application/pdf" />
            @if ($editingId && CashEntry::find($editingId)?->receipt_path && ! $receipt)
                <flux:checkbox wire:model="removeReceipt" label="Remove the attached receipt" />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
