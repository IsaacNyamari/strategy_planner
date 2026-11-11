<?php

namespace App\Finance;

use App\Enums\PaymentType;
use App\Models\CashEntry;
use App\Models\Payment;
use App\Models\SpecialContribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where the team's money came from and where it went, for a period:
 * member contributions + other income in, expenses out, with a running balance.
 */
class BalanceSheet
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    /** A whole year, or one month of it. */
    public static function for(int $year, ?int $month = null): self
    {
        $start = CarbonImmutable::create($year, $month ?: 1, 1)->startOfDay();

        return new self($start, ($month ? $start->endOfMonth() : $start->endOfYear())->startOfDay());
    }

    public function label(): string
    {
        return $this->from->month === 1 && $this->to->month === 12 && $this->from->year === $this->to->year
            ? (string) $this->from->year
            : $this->from->format('F Y');
    }

    public function filename(): string
    {
        return strtolower(str_replace(' ', '-', $this->label()));
    }

    /** Exclusive upper bound (dates may be stored with a time part). */
    protected function end(): string
    {
        return $this->to->addDay()->toDateString();
    }

    public static function receiptNumber(Payment $payment): string
    {
        return 'R-'.$payment->paid_on->format('Y').'-'.str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT);
    }

    /** Balance carried in from before the period starts. */
    public function opening(): float
    {
        $before = $this->from->toDateString();

        return (float) Payment::where('paid_on', '<', $before)->sum('amount')
            + (float) CashEntry::where('direction', 'in')->where('entry_date', '<', $before)->sum('amount')
            - (float) CashEntry::where('direction', 'out')->where('entry_date', '<', $before)->sum('amount');
    }

    /**
     * @return array{opening: float, income: list<array{label: string, amount: float}>, expenses: array<int, array{label: string, amount: float}>, totalIn: float, totalOut: float, net: float, closing: float}
     */
    public function summary(): array
    {
        $payments = $this->payments();
        $entries = $this->cashEntries();

        $income = [
            ['label' => 'Monthly contributions', 'amount' => (float) $payments->where('type', PaymentType::MONTHLY)->sum('amount')],
            ['label' => 'Registration fees', 'amount' => (float) $payments->where('type', PaymentType::REGISTRATION)->sum('amount')],
        ];
        foreach ($payments->where('type', PaymentType::SPECIAL)->groupBy(fn (Payment $p) => $p->specialContribution->title ?? 'Arising contribution') as $title => $group) {
            $income[] = ['label' => 'Arising: '.$title, 'amount' => (float) $group->sum('amount')];
        }
        foreach ($entries->where('direction', 'in')->groupBy('category') as $category => $group) {
            $income[] = ['label' => (string) $category, 'amount' => (float) $group->sum('amount')];
        }

        $expenses = $entries->where('direction', 'out')->groupBy('category')
            ->map(fn (Collection $group, $category) => ['label' => (string) $category, 'amount' => (float) $group->sum('amount')])
            ->sortByDesc('amount')->values()->all();

        $opening = $this->opening();
        $totalIn = (float) collect($income)->sum('amount');
        $totalOut = (float) collect($expenses)->sum('amount');

        return [
            'opening' => $opening,
            'income' => array_values(array_filter($income, fn (array $l) => $l['amount'] > 0)),
            'expenses' => $expenses,
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
            'net' => $totalIn - $totalOut,
            'closing' => $opening + $totalIn - $totalOut,
        ];
    }

    /**
     * Every shilling in and out in date order, with the running balance.
     *
     * @return Collection<int, array{date: CarbonImmutable, in: bool, category: string, description: string, party: string, method: string, reference: string, amount: float, receipt: string, payment: Payment|null, entry: CashEntry|null, balance: float}>
     */
    public function entries(): Collection
    {
        $rows = $this->payments()->map(fn (Payment $p) => [
            'date' => CarbonImmutable::parse($p->paid_on),
            'in' => true,
            'category' => $p->type->label(),
            'description' => $p->forLabel(),
            'party' => $p->member->name ?? 'Member',
            'method' => $p->method->label(),
            'reference' => (string) $p->reference,
            'amount' => (float) $p->amount,
            'receipt' => self::receiptNumber($p),
            'payment' => $p,
            'entry' => null,
            'sort' => sprintf('%s-0-%08d', $p->paid_on->format('Y-m-d'), $p->id),
        ])->concat($this->cashEntries()->map(fn (CashEntry $e) => [
            'date' => CarbonImmutable::parse($e->entry_date),
            'in' => $e->isIncome(),
            'category' => $e->category,
            'description' => $e->description.($e->specialContribution ? ' ('.$e->specialContribution->title.')' : ''),
            'party' => (string) $e->party,
            'method' => $e->method->label(),
            'reference' => (string) $e->reference,
            'amount' => (float) $e->amount,
            'receipt' => $e->receipt_path ? 'Attached' : '',
            'payment' => null,
            'entry' => $e,
            'sort' => sprintf('%s-1-%08d', $e->entry_date->format('Y-m-d'), $e->id),
        ]))->sortBy('sort')->values();

        $balance = $this->opening();

        return $rows->map(function (array $row) use (&$balance) {
            $balance += $row['in'] ? $row['amount'] : -$row['amount'];
            unset($row['sort']);

            return [...$row, 'balance' => $balance];
        });
    }

    /**
     * Arising contributions as funds: everything collected for each one
     * (up to the period end) against what has been spent from it.
     *
     * @return array<int, array{title: string, collected: float, spent: float, balance: float}>
     */
    public function funds(): array
    {
        $to = $this->end();

        return SpecialContribution::query()->orderByDesc('created_at')->get()
            ->map(function (SpecialContribution $s) use ($to) {
                $collected = (float) $s->payments()->where('paid_on', '<', $to)->sum('amount')
                    + (float) CashEntry::where('special_contribution_id', $s->id)->where('direction', 'in')->where('entry_date', '<', $to)->sum('amount');
                $spent = (float) CashEntry::where('special_contribution_id', $s->id)->where('direction', 'out')->where('entry_date', '<', $to)->sum('amount');

                return ['title' => $s->title, 'collected' => $collected, 'spent' => $spent, 'balance' => $collected - $spent];
            })
            ->filter(fn (array $f) => $f['collected'] > 0 || $f['spent'] > 0)
            ->values()->all();
    }

    /** @return Collection<int, Payment> */
    public function payments(): Collection
    {
        return Payment::with(['member:id,name', 'specialContribution:id,title'])
            ->where('paid_on', '>=', $this->from->toDateString())->where('paid_on', '<', $this->end())
            ->orderBy('paid_on')->orderBy('id')->get();
    }

    /** @return Collection<int, CashEntry> */
    public function cashEntries(): Collection
    {
        return CashEntry::with('specialContribution:id,title')
            ->where('entry_date', '>=', $this->from->toDateString())->where('entry_date', '<', $this->end())
            ->orderBy('entry_date')->orderBy('id')->get();
    }
}
