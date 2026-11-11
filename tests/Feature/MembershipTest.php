<?php

use App\Enums\MemberStatus;
use App\Enums\MinistryRole;
use App\Models\Member;
use App\Models\Ministration;
use App\Models\Setting;
use App\Roster\RosterGenerator;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(fn () => Cache::forget('settings'));

test('phone numbers are normalised to international format', function (string $input, ?string $expected) {
    expect(Phone::normalize($input))->toBe($expected);
})->with([
    ['0712 345 678', '+254712345678'],
    ['0112-345-678', '+254112345678'],
    ['712345678', '+254712345678'],
    ['254712345678', '+254712345678'],
    ['+254 712 345 678', '+254712345678'],
    ['+44 7700 900123', '+447700900123'],
    ['12345', null],
    ['', null],
]);

test('member phone is stored normalised with call, sms and whatsapp links', function () {
    $member = Member::factory()->create(['phone' => '0712 345 678']);

    expect($member->fresh()->phone)->toBe('+254712345678')
        ->and($member->phoneDisplay())->toBe('+254 712 345 678')
        ->and($member->telUrl())->toBe('tel:+254712345678')
        ->and($member->whatsappUrl('Hi there'))->toBe('https://wa.me/254712345678?text=Hi%20there');
});

test('the members page requires a valid, unique phone number', function () {
    $this->actingAs(chairman());
    Member::factory()->create(['phone' => '+254700111222']);

    $form = fn () => Livewire::test('pages::members.index')
        ->call('newMember')
        ->set('name', 'Joy Chebet')
        ->set('gender', 'female');

    $form()->call('save')->assertHasErrors(['phone' => 'required']);
    $form()->set('phone', '123')->call('save')->assertHasErrors('phone');
    $form()->set('phone', '0700 111 222')->call('save')->assertHasErrors('phone'); // same number, local format

    $form()->set('phone', '0722 333 444')->call('save')->assertHasNoErrors();
    expect(Member::where('name', 'Joy Chebet')->value('phone'))->toBe('+254722333444');
});

test('new members start on probation for the team default length', function () {
    $this->actingAs(chairman());
    Setting::set('probation_weeks', '8');

    Livewire::test('pages::members.index')
        ->call('newMember')
        ->assertSet('status', 'probation')
        ->set('name', 'Kevin Omondi')
        ->set('gender', 'male')
        ->set('phone', '0733 000 111')
        ->set('joined_at', '2026-11-01')
        ->call('save')
        ->assertHasNoErrors();

    $member = Member::where('name', 'Kevin Omondi')->firstOrFail();
    expect($member->status)->toBe(MemberStatus::PROBATION)
        ->and($member->probation_ends_at->toDateString())->toBe('2026-12-27'); // 8 weeks
});

test('the default probation length can be changed in the app', function () {
    $this->actingAs(chairman());

    Livewire::test('pages::members.index')
        ->set('probationWeeks', 6)
        ->call('saveProbationSettings')
        ->assertHasNoErrors();

    expect(Setting::probationWeeks())->toBe(6);

    $member = Member::factory()->create(['status' => MemberStatus::PROBATION, 'joined_at' => '2026-01-01', 'probation_ends_at' => null]);
    expect($member->probation_ends_at->toDateString())->toBe('2026-02-12');
});

test('probation can be extended, then the member confirmed', function () {
    $this->actingAs(chairman());
    $member = Member::factory()->create(['status' => MemberStatus::PROBATION, 'joined_at' => today()->subWeeks(13), 'probation_ends_at' => today()->subDay()]);

    expect($member->probationDue())->toBeTrue();

    Livewire::test('pages::members.index')
        ->call('openProbation', $member->id)
        ->set('reviewKind', 'concern')
        ->set('reviewNote', 'Missed two rehearsals')
        ->call('addReview')
        ->set('extendWeeks', 4)
        ->call('extendProbation')
        ->assertHasNoErrors();

    $member->refresh();
    expect($member->reviews()->count())->toBe(1)
        ->and($member->probation_ends_at->toDateString())->toBe(today()->addWeeks(4)->toDateString())
        ->and($member->probationDue())->toBeFalse();

    Livewire::test('pages::members.index')->call('confirmMember', $member->id);

    expect($member->fresh()->status)->toBe(MemberStatus::ACTIVE)
        ->and($member->fresh()->confirmed_at)->not->toBeNull();
});

test('discontinued members are no longer rostered', function () {
    $member = Member::factory()->probation()->create();
    $member->discontinue();

    expect($member->fresh()->status)->toBe(MemberStatus::INACTIVE)
        ->and($member->fresh()->is_active)->toBeFalse();
});

test('members on probation can serve as backups but never lead', function () {
    $probation = Member::factory()->count(3)->probation()->create(['can_lead_praise' => true, 'can_lead_worship' => true]);
    Member::factory()->count(2)->leader()->create();
    Member::factory()->count(2)->backupOnly()->create();

    expect($probation[0]->canLead(MinistryRole::PRAISE_LEAD))->toBeFalse()
        ->and($probation[0]->canLead(MinistryRole::BACKUP))->toBeTrue();

    app(RosterGenerator::class)->generateMonth(2026, 12);

    $leadIds = Ministration::with('members')->get()->flatMap(fn ($s) => [$s->praiseLead()?->id, $s->worshipLead()?->id]);
    $backupIds = Ministration::with('members')->get()->flatMap(fn ($s) => $s->backups()->pluck('id'));

    expect($leadIds->intersect($probation->pluck('id')))->toBeEmpty()
        ->and($backupIds->intersect($probation->pluck('id')))->not->toBeEmpty();
});

test('members list downloads as pdf and excel', function (string $format) {
    $this->actingAs(chairman());
    Member::factory()->create(['name' => 'Grace Wanjiru']);
    Member::factory()->probation()->create();

    $this->get(route('exports.members', ['format' => $format, 'filter' => 'current']))
        ->assertOk()
        ->assertHeader('content-disposition');
})->with(['pdf', 'xlsx']);
