<?php

use App\Access\Access;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Song;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('the five roles exist with their default permissions', function () {
    expect(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'chairman', 'music director', 'secretary', 'treasurer']);

    $chair = chairman();
    expect($chair->can('finance.manage'))->toBeTrue()->and($chair->can('access.manage'))->toBeTrue()->and($chair->can('deploy.run'))->toBeFalse();

    $md = chairman(Access::MUSIC_DIRECTOR);
    expect($md->can('songs.manage'))->toBeTrue()->and($md->can('contributions.view'))->toBeFalse()->and($md->can('meetings.view'))->toBeFalse();
});

test('each role only opens its own pages', function (string $role, array $allowed, array $denied) {
    $this->actingAs(chairman($role));

    foreach ($allowed as $route) {
        $this->get(route($route))->assertOk();
    }
    foreach ($denied as $route) {
        $this->get(route($route))->assertForbidden();
    }
})->with([
    'music director' => [Access::MUSIC_DIRECTOR, ['dashboard', 'tasks', 'songs'], ['contributions', 'finance', 'meetings', 'members', 'roster', 'access', 'audit']],
    'secretary' => [Access::SECRETARY, ['dashboard', 'tasks', 'meetings'], ['songs', 'contributions', 'finance', 'access']],
    'treasurer' => [Access::TREASURER, ['dashboard', 'contributions', 'finance'], ['songs', 'meetings', 'access', 'audit']],
    'chairman' => [Access::CHAIRMAN, ['songs', 'meetings', 'contributions', 'finance', 'access', 'audit', 'members', 'roster'], []],
]);

test('actions are refused on the server even if someone calls them directly', function () {
    $this->actingAs(chairman(Access::SECRETARY));
    $member = Member::factory()->create();

    // A secretary may take attendance...
    $meeting = Meeting::create(['title' => 'Team meeting', 'date' => today()]);
    Livewire::test('pages::meetings.show', ['meeting' => $meeting])->call('mark', $member->id, 'present')->assertOk();
    expect($meeting->attendances()->count())->toBe(1);

    // ...but not change songs, even by calling the song page's action.
    $this->actingAs(chairman(Access::MUSIC_DIRECTOR));
    Livewire::test('pages::songs.index')->call('save')->assertHasErrors(); // allowed: validation runs

    $this->actingAs(chairman(Access::TREASURER));
    Livewire::test('pages::meetings.index')->call('delete', $meeting->id)->assertForbidden();
    expect(Meeting::count())->toBe(1);
});

test('people without tasks.view only see and move their own tasks', function () {
    $user = chairman(Access::MUSIC_DIRECTOR);
    $mine = Member::factory()->create(['user_id' => $user->id]);
    $other = Member::factory()->create();
    $myTask = Task::factory()->create(['assignee_id' => $mine->id, 'title' => 'Pick Sunday songs']);
    $theirTask = Task::factory()->create(['assignee_id' => $other->id, 'title' => 'Buy cables']);
    $this->actingAs($user);

    $this->get(route('tasks'))->assertSee('Pick Sunday songs')->assertDontSee('Buy cables');
    Livewire::test('pages::task.index')->call('setStatus', $myTask->id, 'Completed');
    expect($myTask->fresh()->status->value)->toBe('Completed');

    Livewire::test('pages::task.index')->call('setStatus', $theirTask->id, 'Completed')->assertForbidden();
    expect($theirTask->fresh()->status->value)->not->toBe('Completed');
});

test('everyone sees their own contributions but not other members', function () {
    $user = chairman(Access::SECRETARY);
    $mine = Member::factory()->create(['user_id' => $user->id]);
    $other = Member::factory()->create();
    $this->actingAs($user);

    $this->get(route('contributions.mine'))->assertRedirect(route('contributions.member', $mine));
    $this->get(route('contributions.member', $mine))->assertOk();
    $this->get(route('contributions.member', $other))->assertForbidden();
    $this->get(route('exports.statement', $other))->assertForbidden();
});

test('admins create users with roles and link them to a member', function () {
    Notification::fake();
    $admin = chairman(Access::ADMIN);
    $member = Member::factory()->create(['name' => 'Mercy Njeri']);
    $this->actingAs($admin);

    Livewire::test('pages::access.index')
        ->call('newUser')
        ->set('name', 'Mercy Njeri')->set('email', 'Mercy@Example.com')
        ->set('roles', [Access::TREASURER])->set('memberId', $member->id)
        ->call('saveUser')->assertHasNoErrors();

    $mercy = User::where('email', 'mercy@example.com')->firstOrFail();
    expect($mercy->hasRole(Access::TREASURER))->toBeTrue()
        ->and($member->fresh()->user_id)->toBe($mercy->id)
        ->and(AuditLog::where('event', 'roles_changed')->exists())->toBeTrue();
    Notification::assertSentTo($mercy, ResetPassword::class);

    // You can't take away your own admin role.
    Livewire::test('pages::access.index')->call('editUser', $admin->id)->set('roles', [])->call('saveUser')->assertHasErrors('roles');
});

test('role permissions can be changed from the grid, but admin always has everything', function () {
    $this->actingAs(chairman(Access::ADMIN));
    $md = Role::findByName(Access::MUSIC_DIRECTOR);

    Livewire::test('pages::access.index')
        ->call('selectRole', $md->id)
        ->set('rolePermissions', ['songs.view', 'songs.manage', 'roster.view'])
        ->call('saveRole');

    expect(chairman(Access::MUSIC_DIRECTOR)->can('roster.view'))->toBeTrue();

    $admin = Role::findByName(Access::ADMIN);
    Livewire::test('pages::access.index')->call('selectRole', $admin->id)->set('rolePermissions', [])->call('saveRole');
    expect($admin->fresh()->permissions()->count())->toBe(count(Access::permissionNames()));
});

test('switched-off accounts are signed out', function () {
    $user = chairman();
    $user->update(['is_active' => false]);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('the audit trail records who changed what, and downloads', function () {
    $user = chairman();
    $this->actingAs($user);

    $song = Song::create(['title' => 'Amazing Grace', 'language' => 'english', 'category' => 'hymn']);
    $song->update(['title' => 'Amazing Grace (hymn)']);
    $this->get(route('exports.songs', ['format' => 'pdf']))->assertOk();

    $logs = AuditLog::latest('id')->get();
    expect($logs->pluck('event')->all())->toContain('created', 'updated', 'downloaded')
        ->and($logs->firstWhere('event', 'updated')->changes['title'])->toBe(['old' => 'Amazing Grace', 'new' => 'Amazing Grace (hymn)'])
        ->and($logs->firstWhere('event', 'updated')->user_id)->toBe($user->id);

    $this->get(route('audit'))->assertOk()->assertSee('Amazing Grace (hymn)');
});

test('the secretary runs a meeting: attendance, minutes, actions and the PDF', function () {
    $this->actingAs(chairman(Access::SECRETARY));
    [$a, $b] = Member::factory()->count(2)->create();

    Livewire::test('pages::meetings.index')
        ->call('newMeeting')
        ->set('title', 'November team meeting')->set('date', '2026-11-20')->set('startsAt', '18:00')->set('agenda', "Opening prayer\nUniforms")
        ->call('save')->assertHasNoErrors();
    $meeting = Meeting::firstOrFail();

    Livewire::test('pages::meetings.show', ['meeting' => $meeting])
        ->call('mark', $a->id, 'present')
        ->call('markRestAbsent')
        ->set('minutes', 'We agreed on navy uniforms.')->call('saveMinutes')
        ->set('actionText', 'Order navy ties')->set('actionMember', $b->id)->call('addAction')
        ->call('setStatus', 'held');

    expect($meeting->fresh()->status)->toBe('held')
        ->and($meeting->attendances()->pluck('status', 'member_id')->all())->toBe([$a->id => 'present', $b->id => 'absent'])
        ->and($meeting->actions()->first()->member_id)->toBe($b->id);

    $this->get(route('exports.meeting', $meeting))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('only admins can open the whatsapp settings and chats', function () {
    config(['worship.whatsapp.enabled' => true, 'worship.whatsapp.url' => 'http://127.0.0.1:3100', 'worship.whatsapp.api_key' => 'k']);
    Http::fake(['*' => Http::response([])]);

    $this->actingAs(chairman(Access::CHAIRMAN));
    $this->get(route('whatsapp'))->assertForbidden();
    $this->get(route('whatsapp.chats'))->assertForbidden();
    $this->get(route('dashboard'))->assertDontSee(route('whatsapp.chats'));

    $this->actingAs(chairman(Access::ADMIN));
    $this->get(route('whatsapp.chats'))->assertOk();
});
