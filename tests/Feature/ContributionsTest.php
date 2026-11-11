<?php

use App\Contributions\ContributionLedger;
use App\Enums\MemberStatus;
use App\Models\ContributionRate;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SpecialContribution;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-06-15');
    ContributionRate::create(['year' => 2026, 'monthly_amount' => 500, 'registration_fee' => 1000]);
});

afterEach(fn () => Carbon::setTestNow());

function pay(Member $m, string $type, float $amount, ?int $month = null, ?SpecialContribution $special = null): Payment
{
    return Payment::create([
        'member_id' => $m->id, 'type' => $type, 'year' => 2026, 'month' => $month,
        'special_contribution_id' => $special?->id, 'amount' => $amount, 'paid_on' => '2026-06-01', 'method' => 'mpesa',
    ]);
}

test('the monthly grid shows paid, partial, due and upcoming months and arrears', function () {
    $member = Member::factory()->create(['joined_at' => '2025-01-01']);
    pay($member, 'monthly', 500, 1);
    pay($member, 'monthly', 500, 2);
    pay($member, 'monthly', 200, 3);
    pay($member, 'registration', 1000);

    $row = app(ContributionLedger::class)->monthlyGrid(2026)['rows'][0];

    expect($row['months'][1]['status'])->toBe('paid')
        ->and($row['months'][3]['status'])->toBe('partial')
        ->and($row['months'][4]['status'])->toBe('due')     // April, unpaid, in the past
        ->and($row['months'][6]['status'])->toBe('due')     // June = current month
        ->and($row['months'][7]['status'])->toBe('upcoming')
        ->and($row['paid'])->toBe(1200.0)
        ->and($row['expected'])->toBe(3000.0)              // Jan-Jun
        ->and($row['arrears'])->toBe(1800.0)               // 300 (Mar) + 4 x 500
        ->and($row['registration_status'])->toBe('paid');
});

test('months before a member joined are not charged', function () {
    $member = Member::factory()->create(['joined_at' => '2026-04-10']);

    $row = app(ContributionLedger::class)->monthlyGrid(2026)['rows'][0];

    expect($row['months'][3]['status'])->toBe('n/a')
        ->and($row['months'][4]['status'])->toBe('due')
        ->and($row['expected'])->toBe(1500.0); // Apr, May, Jun
});

test('inactive members are left out of the yearly grid', function () {
    Member::factory()->create(['name' => 'Active One']);
    Member::factory()->create(['name' => 'Gone', 'status' => MemberStatus::INACTIVE]);

    $names = collect(app(ContributionLedger::class)->monthlyGrid(2026)['rows'])->pluck('member.name');

    expect($names->all())->toBe(['Active One']);
});

test('a member statement totals monthly, registration and arising contributions', function () {
    $member = Member::factory()->create(['joined_at' => '2025-01-01']);
    $fund = SpecialContribution::create(['title' => 'Uniform fund', 'amount_per_member' => 2000, 'due_date' => '2026-07-31']);
    foreach (range(1, 6) as $m) {
        pay($member, 'monthly', 500, $m);
    }
    pay($member, 'special', 500, special: $fund);

    $st = app(ContributionLedger::class)->statement($member, 2026);

    expect($st['monthly']['arrears'])->toBe(0.0)
        ->and($st['registration']['status'])->toBe('due')
        ->and($st['specials']->first()['status'])->toBe('partial')
        ->and($st['totals']['paid'])->toBe(3500.0)
        ->and($st['totals']['expected'])->toBe(6000.0)  // 3000 monthly + 1000 reg + 2000 fund
        ->and($st['totals']['balance'])->toBe(2500.0);  // 1000 reg + 1500 fund
});

test('arising contribution status shows who has paid', function () {
    $a = Member::factory()->create(['name' => 'A']);
    $b = Member::factory()->create(['name' => 'B']);
    $fund = SpecialContribution::create(['title' => 'Bereavement', 'amount_per_member' => 1000]);
    pay($a, 'special', 1000, special: $fund);

    $st = app(ContributionLedger::class)->specialStatus($fund);

    expect($st['collected'])->toBe(1000.0)
        ->and($st['expected'])->toBe(2000.0)
        ->and($st['paid_count'])->toBe(1)
        ->and(collect($st['rows'])->firstWhere('member.id', $b->id)['status'])->toBe('due');
});

test('several months can be recorded in one payment', function () {
    $this->actingAs(chairman());
    $member = Member::factory()->create();

    Livewire::test('payment-form')
        ->call('open', $member->id, 'monthly', 2026)
        ->assertSet('amount', '500')
        ->set('months', [1, 2, 3])
        ->set('reference', 'QAB12CD34')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('payment-recorded');

    expect(Payment::where('member_id', $member->id)->where('type', 'monthly')->orderBy('month')->pluck('month')->all())->toBe([1, 2, 3])
        ->and(Payment::sum('amount'))->toEqual(1500);
});

test('recording a payment validates the essentials', function () {
    $this->actingAs(chairman());

    Livewire::test('payment-form')
        ->call('open', null, 'monthly', 2026)
        ->set('amount', '')
        ->call('save')
        ->assertHasErrors(['memberId', 'months', 'amount']);

    Livewire::test('payment-form')
        ->call('open', Member::factory()->create()->id, 'special', 2026)
        ->set('amount', '100')
        ->call('save')
        ->assertHasErrors('specialId');
});

test('rates and arising contributions are managed from the contributions page', function () {
    $this->actingAs(chairman());

    Livewire::test('pages::contributions.index')
        ->call('editRate', 2027)
        ->set('monthlyAmount', '600')
        ->set('registrationFee', '1200')
        ->call('saveRate')
        ->assertHasNoErrors()
        ->call('newSpecial')
        ->set('specialTitle', 'Choir trip')
        ->set('specialAmountEach', '1500')
        ->call('saveSpecial')
        ->assertHasNoErrors();

    expect((float) ContributionRate::forYear(2027)->monthly_amount)->toBe(600.0)
        ->and(SpecialContribution::where('title', 'Choir trip')->exists())->toBeTrue();
});

test('contributions pages render', function () {
    $this->actingAs(chairman());
    $member = Member::factory()->create(['name' => 'Grace Wanjiru', 'phone' => '0712345678']);
    pay($member, 'monthly', 500, 1);
    SpecialContribution::create(['title' => 'Uniform fund', 'amount_per_member' => 2000]);

    foreach (['monthly', 'arising', 'payments', 'rates'] as $tab) {
        $this->get(route('contributions', ['tab' => $tab, 'year' => 2026]))->assertOk();
    }

    $this->get(route('contributions.member', [$member, 'year' => 2026]))
        ->assertOk()
        ->assertSee('Grace Wanjiru')
        ->assertSee('wa.me/254712345678', false);
});

test('contribution exports download', function () {
    $this->actingAs(chairman());
    $member = Member::factory()->create();
    $fund = SpecialContribution::create(['title' => 'Uniform fund', 'amount_per_member' => 2000]);
    pay($member, 'monthly', 500, 1);
    pay($member, 'special', 2000, special: $fund);

    $this->get(route('exports.contributions', ['format' => 'pdf', 'year' => 2026]))->assertOk();
    $this->get(route('exports.contributions', ['format' => 'xlsx', 'year' => 2026]))->assertOk();
    $this->get(route('exports.payments', ['format' => 'xlsx', 'year' => 2026]))->assertOk();
    $this->get(route('exports.special', ['special' => $fund, 'format' => 'pdf']))->assertOk();
    $this->get(route('exports.special', ['special' => $fund, 'format' => 'xlsx']))->assertOk();
    $this->get(route('exports.statement', ['member' => $member, 'year' => 2026]))->assertOk();
});

test('guests cannot see contributions', function () {
    $this->get(route('contributions'))->assertRedirect(route('login'));
});
