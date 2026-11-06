<?php

use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'worship.deploy.enabled' => true,
        'worship.deploy.admins' => ['admin@example.com'],
        'worship.deploy.command' => 'sudo -n /usr/local/bin/strategy-planner-deploy',
    ]);
    Cache::flush();
    File::delete([storage_path('app/deploy-state.json'), storage_path('logs/deploy.log')]);

    Process::fake([
        '*status*' => Process::result('{"current":{"hash":"abc1234","message":"Add deploy button","date":"2026-11-06T20:00:00+00:00"},"latest":"def5678"}'),
        '*run*' => Process::result('started'),
    ]);
});

afterEach(fn () => File::delete([storage_path('app/deploy-state.json'), storage_path('logs/deploy.log')]));

function admin(): User
{
    return User::factory()->create(['email' => 'admin@example.com']);
}

function confirmedSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

test('only listed admins can open the deployments page', function () {
    $this->actingAs(User::factory()->create(['email' => 'someone@example.com']))
        ->get(route('deployments'))->assertForbidden();

    $this->actingAs(admin())->withSession(confirmedSession())
        ->get(route('deployments'))->assertOk()->assertSee('Deploy now')->assertSee('abc1234')->assertSee('Update available');
});

test('the feature is off unless enabled on the server', function () {
    config(['worship.deploy.enabled' => false]);
    $admin = admin();

    $this->actingAs($admin)->withSession(confirmedSession())->get(route('deployments'))->assertForbidden();
    $this->actingAs($admin)->get(route('deployments.status'))->assertForbidden();
});

test('guests are sent to login', function () {
    $this->get(route('deployments'))->assertRedirect(route('login'));
});

test('the page asks for the password first', function () {
    $this->actingAs(admin())->get(route('deployments'))->assertRedirect(route('password.confirm'));
});

test('only admins see the sidebar link', function () {
    $this->actingAs(admin())->withSession(confirmedSession())->get(route('dashboard'))->assertSee('Deployments');
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee('Deployments');
});

test('the deploy button starts the helper with only the fixed command', function () {
    $this->actingAs(admin())->withSession(confirmedSession());

    Livewire::test('pages::deployments.index')
        ->call('deploy')
        ->assertDispatched('deploy-started');

    Process::assertRan(fn (PendingProcess $p) => $p->command === ['sudo', '-n', '/usr/local/bin/strategy-planner-deploy', 'run', 'admin@example.com']);
});

test('a deploy is refused while one is running', function () {
    File::ensureDirectoryExists(storage_path('app'));
    File::put(storage_path('app/deploy-state.json'), '{"status":"running","started_at":"2026-11-06T20:00:00Z","finished_at":"","exit_code":null,"commit":"","requested_by":"admin@example.com"}');
    $this->actingAs(admin())->withSession(confirmedSession());

    Livewire::test('pages::deployments.index')->call('deploy')->assertNotDispatched('deploy-started');

    Process::assertDidntRun(fn (PendingProcess $p) => in_array('run', (array) $p->command, true));
});

test('deploying needs a recent password confirmation even from the page', function () {
    $this->actingAs(admin())->withSession(['auth.password_confirmed_at' => time() - 99999]);

    Livewire::test('pages::deployments.index')->call('deploy')->assertRedirect(route('password.confirm'));

    Process::assertDidntRun(fn (PendingProcess $p) => in_array('run', (array) $p->command, true));
});

test('non-admins cannot trigger a deploy action', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::deployments.index')->call('deploy')->assertForbidden();
});

test('the status endpoint returns state and a clean log, even in maintenance mode', function () {
    File::ensureDirectoryExists(storage_path('logs'));
    File::put(storage_path('logs/deploy.log'), "\e[1;34m==> Pulling latest code\e[0m\nDone\n");
    File::put(storage_path('app/deploy-state.json'), '{"status":"success","started_at":"2026-11-06T20:00:00Z","finished_at":"2026-11-06T20:01:10Z","exit_code":0,"commit":"def5678 Add deploy button","requested_by":"admin@example.com"}');

    $this->artisan('down');
    try {
        $this->actingAs(admin())->getJson(route('deployments.status'))
            ->assertOk()
            ->assertJsonPath('state.status', 'success')
            ->assertJsonPath('state.exit_code', 0)
            ->assertJsonPath('log', "==> Pulling latest code\nDone\n");
    } finally {
        $this->artisan('up');
    }
});
