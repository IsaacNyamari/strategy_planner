<?php

namespace App\Contributions;

use App\Enums\MemberStatus;
use App\Enums\PaymentType;
use App\Models\ContributionRate;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SpecialContribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out what each member owes and has paid.
 *
 *  - Monthly: the year's monthly amount is due for every month from the month
 *    the member joined. Past/current months unpaid = "due" (arrears);
 *    future months = "upcoming". Partial and advance payments are allowed.
 *  - Registration: the year's fee, once per member per year.
 *  - Arising: amount_per_member (if set) from every member.
 */
class ContributionLedger
{
    public const PAID = 'paid';

    public const PARTIAL = 'partial';

    public const DUE = 'due';

    public const UPCOMING = 'upcoming';

    public const NOT_APPLICABLE = 'n/a';

    public static function currency(): string
    {
        return (string) config('worship.currency', 'KES');
    }

    public static function money(float|string|null $amount): string
    {
        return static::currency().' '.number_format((float) $amount, 0);
    }

    /** 1 => "Jan" (or "January" with $format = 'F'). */
    public static function monthName(int $month, string $format = 'M'): string
    {
        return CarbonImmutable::create(2000, max(1, min(12, $month)), 1)->format($format);
    }

    /**
     * The members/year grid: one row per member, a cell per month.
     *
     * @param  Collection<int, Member>|null  $members
     * @return array{rate: ContributionRate, rows: list<array<string, mixed>>, totals: array<string, float>, monthTotals: array<int, float>}
     */
    public function monthlyGrid(int $year, ?Collection $members = null): array
    {
        $rate = ContributionRate::forYear($year);
        $members ??= Member::query()->where('status', '!=', MemberStatus::INACTIVE->value)->orderBy('name')->get();

        $payments = Payment::query()
            ->whereIn('member_id', $members->pluck('id'))
            ->where('year', $year)
            ->whereIn('type', [PaymentType::MONTHLY->value, PaymentType::REGISTRATION->value])
            ->get(['member_id', 'type', 'month', 'amount']);

        $rows = [];
        $monthTotals = array_fill(1, 12, 0.0);
        $totals = ['expected' => 0.0, 'paid' => 0.0, 'arrears' => 0.0, 'registration_paid' => 0.0, 'registration_due' => 0.0];

        foreach ($members as $member) {
            $mine = $payments->where('member_id', $member->id);
            $months = [];
            $paid = 0.0;
            $expected = 0.0;
            $arrears = 0.0;

            for ($m = 1; $m <= 12; $m++) {
                $amount = (float) $mine->where('type', PaymentType::MONTHLY)->where('month', $m)->sum('amount');
                $status = $this->monthStatus($member, $year, $m, $amount, (float) $rate->monthly_amount);
                $months[$m] = ['paid' => $amount, 'status' => $status];
                $paid += $amount;
                $monthTotals[$m] += $amount;

                if (in_array($status, [self::PAID, self::PARTIAL, self::DUE], true)) {
                    $expected += (float) $rate->monthly_amount;
                    $arrears += max(0, (float) $rate->monthly_amount - $amount);
                }
            }

            $regPaid = (float) $mine->where('type', PaymentType::REGISTRATION)->sum('amount');
            $regStatus = $this->amountStatus($regPaid, (float) $rate->registration_fee, $this->appliesInYear($member, $year));

            $rows[] = [
                'member' => $member,
                'months' => $months,
                'paid' => $paid,
                'expected' => $expected,
                'arrears' => $arrears,
                'registration_paid' => $regPaid,
                'registration_status' => $regStatus,
            ];

            $totals['expected'] += $expected;
            $totals['paid'] += $paid;
            $totals['arrears'] += $arrears;
            $totals['registration_paid'] += $regPaid;
            $totals['registration_due'] += $regStatus === self::NOT_APPLICABLE ? 0 : max(0, (float) $rate->registration_fee - $regPaid);
        }

        return ['rate' => $rate, 'rows' => $rows, 'totals' => $totals, 'monthTotals' => $monthTotals];
    }

    /**
     * A single member's statement for a year.
     *
     * @return array<string, mixed>
     */
    public function statement(Member $member, int $year): array
    {
        $grid = $this->monthlyGrid($year, collect([$member]));
        $row = $grid['rows'][0];
        $rate = $grid['rate'];

        $specials = SpecialContribution::query()
            ->where(fn ($q) => $q->whereYear('created_at', $year)->orWhereYear('due_date', $year)
                ->orWhereHas('payments', fn ($p) => $p->where('member_id', $member->id)->where('year', $year)))
            ->orderBy('due_date')
            ->get()
            ->map(function (SpecialContribution $s) use ($member) {
                $paid = (float) $s->payments()->where('member_id', $member->id)->sum('amount');
                $expected = (float) $s->amount_per_member;

                return [
                    'contribution' => $s,
                    'expected' => $expected,
                    'paid' => $paid,
                    'balance' => max(0, $expected - $paid),
                    'status' => $expected > 0 ? $this->amountStatus($paid, $expected, true) : ($paid > 0 ? self::PAID : self::NOT_APPLICABLE),
                ];
            });

        $payments = $member->payments()
            ->with('specialContribution:id,title')
            ->where('year', $year)
            ->orderBy('paid_on')->orderBy('id')
            ->get();

        $regDue = $row['registration_status'] === self::NOT_APPLICABLE ? 0.0 : (float) $rate->registration_fee;
        $expected = $row['expected'] + $regDue + $specials->sum('expected');
        $paid = (float) $payments->sum('amount');

        return [
            'member' => $member,
            'year' => $year,
            'rate' => $rate,
            'months' => $row['months'],
            'monthly' => ['expected' => $row['expected'], 'paid' => $row['paid'], 'arrears' => $row['arrears']],
            'registration' => ['fee' => $regDue, 'paid' => $row['registration_paid'], 'status' => $row['registration_status']],
            'specials' => $specials,
            'payments' => $payments,
            'totals' => [
                'expected' => $expected,
                'paid' => $paid,
                'balance' => max(0, $row['arrears'] + max(0, $regDue - $row['registration_paid']) + $specials->sum('balance')),
            ],
        ];
    }

    /**
     * Who has paid an arising contribution, and how much.
     *
     * @return array{contribution: SpecialContribution, rows: array<int, array<string, mixed>>, collected: float, expected: float, paid_count: int}
     */
    public function specialStatus(SpecialContribution $special): array
    {
        $paidBy = $special->payments()->selectRaw('member_id, sum(amount) as total')->groupBy('member_id')->pluck('total', 'member_id');
        $expectedEach = (float) $special->amount_per_member;

        $members = Member::query()
            ->where(fn ($q) => $q->where('status', '!=', MemberStatus::INACTIVE->value)->orWhereIn('id', $paidBy->keys()))
            ->orderBy('name')->get();

        $rows = $members->map(fn (Member $m) => [
            'member' => $m,
            'paid' => (float) ($paidBy[$m->id] ?? 0),
            'expected' => $expectedEach,
            'balance' => max(0, $expectedEach - (float) ($paidBy[$m->id] ?? 0)),
            'status' => $expectedEach > 0
                ? $this->amountStatus((float) ($paidBy[$m->id] ?? 0), $expectedEach, true)
                : (($paidBy[$m->id] ?? 0) > 0 ? self::PAID : self::DUE),
        ])->values()->all();

        return [
            'contribution' => $special,
            'rows' => $rows,
            'collected' => (float) $paidBy->sum(),
            'expected' => $expectedEach > 0 ? $expectedEach * $members->count() : (float) $special->target_amount,
            'paid_count' => collect($rows)->where('status', self::PAID)->count(),
        ];
    }

    /**
     * Totals received in a year, by type and by month.
     *
     * @return array{byType: array<string, float>, byMonth: array<int, float>, total: float}
     */
    public function yearTotals(int $year): array
    {
        $payments = Payment::query()->whereYear('paid_on', $year)->get(['type', 'amount', 'paid_on']);

        $byMonth = array_fill(1, 12, 0.0);
        foreach ($payments as $p) {
            $byMonth[(int) $p->paid_on->format('n')] += (float) $p->amount;
        }

        return [
            'byType' => collect(PaymentType::cases())->mapWithKeys(fn (PaymentType $t) => [$t->value => (float) $payments->where('type', $t)->sum('amount')])->all(),
            'byMonth' => $byMonth,
            'total' => (float) $payments->sum('amount'),
        ];
    }

    /** Is the member expected to contribute at all in this year? */
    public function appliesInYear(Member $member, int $year): bool
    {
        $joined = $member->joined_at ?? $member->created_at;

        return ! $joined || $joined->year <= $year;
    }

    protected function monthStatus(Member $member, int $year, int $month, float $paid, float $rate): string
    {
        $joined = $member->joined_at ?? $member->created_at;
        $monthStart = CarbonImmutable::create($year, $month, 1);

        if ($joined && $monthStart->endOfMonth()->lt($joined->copy()->startOfMonth())) {
            return $paid > 0 ? self::PAID : self::NOT_APPLICABLE; // before they joined
        }

        if ($rate <= 0) {
            return $paid > 0 ? self::PAID : self::NOT_APPLICABLE;
        }

        if ($paid >= $rate) {
            return self::PAID;
        }

        if ($paid > 0) {
            return self::PARTIAL;
        }

        return $monthStart->startOfMonth()->gt(today()) ? self::UPCOMING : self::DUE;
    }

    protected function amountStatus(float $paid, float $expected, bool $applies): string
    {
        return match (true) {
            ! $applies || $expected <= 0 => $paid > 0 ? self::PAID : self::NOT_APPLICABLE,
            $paid >= $expected => self::PAID,
            $paid > 0 => self::PARTIAL,
            default => self::DUE,
        };
    }
}
