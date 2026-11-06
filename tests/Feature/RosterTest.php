<?php

use App\Enums\ServiceType;
use App\Models\Member;
use App\Models\MemberUnavailability;
use App\Models\Ministration;
use App\Models\Uniform;
use App\Models\User;
use App\Roster\RosterGenerator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    config(['worship.backups_per_service' => 2]);
});

/** Pairs of overlapping ministrations sharing a member. */
function collisions(): int
{
    return DB::table('ministration_member as a')
        ->join('ministration_member as b', function ($j) {
            $j->on('a.member_id', '=', 'b.member_id')->on('a.ministration_id', '<', 'b.ministration_id');
        })
        ->join('ministrations as ma', 'ma.id', '=', 'a.ministration_id')
        ->join('ministrations as mb', 'mb.id', '=', 'b.ministration_id')
        ->whereColumn('ma.date', 'mb.date')
        ->whereColumn('ma.starts_at', '<', 'mb.ends_at')
        ->whereColumn('ma.ends_at', '>', 'mb.starts_at')
        ->count();
}

test('generating a month creates every sunday, wednesday and friday service', function () {
    Member::factory()->count(3)->leader()->create();
    Member::factory()->count(6)->backupOnly()->create();

    $result = app(RosterGenerator::class)->generateMonth(2026, 12);

    // December 2026: 4 Sundays, 5 Wednesdays, 4 Fridays.
    expect($result->created)->toBe(13)
        ->and(Ministration::where('type', ServiceType::SUNDAY)->count())->toBe(4)
        ->and(Ministration::where('type', ServiceType::WEDNESDAY)->count())->toBe(5)
        ->and(Ministration::where('type', ServiceType::FRIDAY)->count())->toBe(4);

    Ministration::with('members')->get()->each(function (Ministration $s) {
        $praise = $s->praiseLead();
        $worship = $s->worshipLead();

        // Two sessions, two different leaders, each eligible, plus 2 backups.
        expect($praise)->not->toBeNull()
            ->and($worship)->not->toBeNull()
            ->and($praise->id)->not->toBe($worship->id)
            ->and($praise->can_lead_praise)->toBeTrue()
            ->and($worship->can_lead_worship)->toBeTrue()
            ->and($s->backups())->toHaveCount(2)
            ->and($s->members)->toHaveCount(4);
    });

    // Re-running is idempotent: nothing new created or re-staffed.
    $again = app(RosterGenerator::class)->generateMonth(2026, 12);
    expect($again->created)->toBe(0)->and(Ministration::count())->toBe(13);
});

test('duties are spread fairly across the team', function () {
    $leaders = Member::factory()->count(4)->leader()->create();
    $backups = Member::factory()->count(8)->backupOnly()->create();

    app(RosterGenerator::class)->generateMonth(2026, 12);

    $loads = DB::table('ministration_member')->selectRaw('member_id, count(*) as n')->groupBy('member_id')->pluck('n', 'member_id');

    // Leaders fill 2 lead slots per service, so they serve more than backup-only
    // members; fairness is judged within each group.
    $leaderLoads = $leaders->map(fn ($m) => $loads[$m->id] ?? 0);
    $backupLoads = $backups->map(fn ($m) => $loads[$m->id] ?? 0);
    expect($leaderLoads->max() - $leaderLoads->min())->toBeLessThanOrEqual(2)
        ->and($backupLoads->max() - $backupLoads->min())->toBeLessThanOrEqual(1);

    // And lead turns are shared evenly between the leaders.
    $leadLoads = DB::table('ministration_member')->where('role', '!=', 'backup')->selectRaw('member_id, count(*) as n')->groupBy('member_id')->pluck('n');
    expect($leadLoads)->toHaveCount(4)->and($leadLoads->max() - $leadLoads->min())->toBeLessThanOrEqual(1);
});

test('members are never booked on their unavailable dates', function () {
    $leaders = Member::factory()->count(3)->leader()->create();
    Member::factory()->count(3)->backupOnly()->create();
    MemberUnavailability::create(['member_id' => $leaders[0]->id, 'date' => '2026-12-06']); // a Sunday

    app(RosterGenerator::class)->generateMonth(2026, 12);

    $sunday = Ministration::whereDate('date', '2026-12-06')->with('members')->first();
    expect($sunday->members->pluck('id'))->not->toContain($leaders[0]->id)
        ->and([$sunday->praiseLead()?->id, $sunday->worshipLead()?->id])->each->not->toBeNull()
        ->and(collect([$sunday->praiseLead()->id, $sunday->worshipLead()->id])->sort()->values()->all())
        ->toBe([$leaders[1]->id, $leaders[2]->id]);
});

test('an abrupt meeting never takes members from an overlapping service', function () {
    Member::factory()->count(3)->leader()->create();
    Member::factory()->count(5)->backupOnly()->create();

    $generator = app(RosterGenerator::class);
    $generator->generateMonth(2026, 12);

    $sunday = Ministration::whereDate('date', '2026-12-06')->with('members')->first();
    $special = Ministration::factory()->special('Emergency prayer')->create([
        'date' => '2026-12-06', 'starts_at' => '11:00', 'ends_at' => '13:00',
    ]);

    $generator->staff($special, 2);

    $special->load('members');
    expect($special->members)->not->toBeEmpty()
        ->and($special->members->pluck('id')->intersect($sunday->members->pluck('id')))->toBeEmpty()
        ->and(collisions())->toBe(0);
});

test('back-to-back meetings are not treated as a clash', function () {
    $member = Member::factory()->leader()->create();
    $morning = Ministration::factory()->create(['date' => '2026-12-06', 'starts_at' => '09:00', 'ends_at' => '12:00']);
    $morning->members()->attach($member->id, ['role' => 'praise_lead']);
    $afternoon = Ministration::factory()->special()->create(['date' => '2026-12-06', 'starts_at' => '12:00', 'ends_at' => '14:00']);

    expect(app(RosterGenerator::class)->conflictFor($member, $afternoon))->toBeNull();

    $overlap = Ministration::factory()->special()->create(['date' => '2026-12-06', 'starts_at' => '11:30', 'ends_at' => '13:00']);
    expect(app(RosterGenerator::class)->conflictFor($member, $overlap))->toContain('already ministering');
});

test('uniforms rotate across services', function () {
    Member::factory()->count(2)->leader()->create();
    Member::factory()->count(4)->backupOnly()->create();
    Uniform::factory()->count(3)->create();

    app(RosterGenerator::class)->generateMonth(2026, 12);

    $ids = Ministration::orderBy('date')->orderBy('starts_at')->pluck('uniform_id');
    expect($ids->filter()->count())->toBe($ids->count());

    // Never the same uniform twice in a row.
    $ids->sliding(2)->each(fn ($pair) => expect($pair->first())->not->toBe($pair->last()));
});

test('the roster page refuses to double-book a member', function () {
    $this->actingAs(User::factory()->create());
    $member = Member::factory()->leader()->create();
    $a = Ministration::factory()->create(['date' => '2026-12-06', 'starts_at' => '09:00', 'ends_at' => '12:00']);
    $a->members()->attach($member->id, ['role' => 'praise_lead']);
    $b = Ministration::factory()->special()->create(['date' => '2026-12-06', 'starts_at' => '10:00', 'ends_at' => '11:00']);

    Livewire::test('pages::roster.index', ['month' => '2026-12'])
        ->call('manage', $b->id)
        ->set('addMemberId', $member->id)
        ->call('addMember')
        ->assertHasErrors('addMemberId');

    expect($b->members()->count())->toBe(0);
});

test('a special meeting can be created and auto-staffed from the roster page', function () {
    $this->actingAs(User::factory()->create());
    Member::factory()->count(2)->leader()->create();
    Member::factory()->count(4)->backupOnly()->create();

    Livewire::test('pages::roster.index', ['month' => '2026-12'])
        ->call('newMeeting', '2026-12-09')
        ->set('meetingTitle', 'Overnight prayers')
        ->set('meetingStart', '21:00')
        ->set('meetingEnd', '23:30')
        ->call('saveMeeting')
        ->assertHasNoErrors();

    $meeting = Ministration::where('title', 'Overnight prayers')->with('members')->firstOrFail();
    expect($meeting->type)->toBe(ServiceType::SPECIAL)
        ->and($meeting->praiseLead())->not->toBeNull()
        ->and($meeting->worshipLead())->not->toBeNull();
});

test('roster, members, uniforms and songs pages render', function () {
    $this->actingAs(User::factory()->create());
    config(['worship.gemini.api_key' => null]);
    Member::factory()->leader()->create(['name' => 'Grace Wanjiru']);

    $this->get(route('roster'))->assertOk()->assertSee('Ministering roster');
    $this->get(route('members'))->assertOk()->assertSee('Grace Wanjiru');
    $this->get(route('uniforms'))->assertOk()->assertSee('AI suggestions');
    $this->get(route('songs'))->assertOk()->assertSee('Songs');
});

test('praise-only and worship-only members only lead their own session', function () {
    $praiseOnly = Member::factory()->count(3)->praiseLeader()->create();
    $worshipOnly = Member::factory()->count(3)->worshipLeader()->create();
    Member::factory()->count(6)->backupOnly()->create();

    app(RosterGenerator::class)->generateMonth(2026, 12);

    Ministration::with('members')->get()->each(function (Ministration $s) use ($praiseOnly, $worshipOnly) {
        expect($praiseOnly->pluck('id'))->toContain($s->praiseLead()->id)
            ->and($worshipOnly->pluck('id'))->toContain($s->worshipLead()->id);
    });

    // Each lead role is shared around rather than given to one person.
    $praiseCounts = DB::table('ministration_member')->where('role', 'praise_lead')->selectRaw('member_id, count(*) n')->groupBy('member_id')->pluck('n');
    expect($praiseCounts)->toHaveCount(3)->and($praiseCounts->max() - $praiseCounts->min())->toBeLessThanOrEqual(1);
});

test('a warning is raised when nobody can lead a session', function () {
    Member::factory()->count(2)->praiseLeader()->create();
    Member::factory()->count(4)->backupOnly()->create();

    $result = app(RosterGenerator::class)->generateMonth(2026, 12);

    expect(collect($result->warnings)->filter(fn ($w) => str_contains($w, 'lead Worship')))->not->toBeEmpty()
        ->and(Ministration::first()->load('members')->praiseLead())->not->toBeNull();
});

test('giving someone a lead role on the roster page demotes the previous holder', function () {
    $this->actingAs(User::factory()->create());
    $first = Member::factory()->leader()->create();
    $second = Member::factory()->leader()->create();
    $service = Ministration::factory()->create(['date' => '2026-12-06']);
    $service->members()->attach($first->id, ['role' => 'worship_lead']);
    $service->members()->attach($second->id, ['role' => 'backup']);

    Livewire::test('pages::roster.index', ['month' => '2026-12'])
        ->call('manage', $service->id)
        ->call('setRole', $second->id, 'worship_lead');

    $service->load('members');
    expect($service->worshipLead()->id)->toBe($second->id)
        ->and($service->backups()->pluck('id')->all())->toBe([$first->id]);
});

test('members who cannot lead a session are refused that lead role', function () {
    $this->actingAs(User::factory()->create());
    $member = Member::factory()->praiseLeader()->create();
    $service = Ministration::factory()->create(['date' => '2026-12-06']);

    Livewire::test('pages::roster.index', ['month' => '2026-12'])
        ->call('manage', $service->id)
        ->set('addMemberId', $member->id)
        ->set('addRole', 'worship_lead')
        ->call('addMember')
        ->assertHasErrors('addMemberId');

    expect($service->members()->count())->toBe(0);
});

test('lead eligibility for each session is saved from the members page', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::members.index')
        ->call('newMember')
        ->set('name', 'Faith Achieng')
        ->set('gender', 'female')
        ->set('phone', '0712 345 678')
        ->set('status', 'active')
        ->set('can_lead_worship', true)
        ->call('save')
        ->assertHasNoErrors();

    $member = Member::where('name', 'Faith Achieng')->firstOrFail();
    expect($member->can_lead_praise)->toBeFalse()->and($member->can_lead_worship)->toBeTrue();
});
