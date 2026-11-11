<?php

namespace App\Providers;

use App\Audit\Audit;
use App\Deployment\Deployer;
use App\Livewire\GuardHook;
use App\Models\Member;
use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Social\FacebookPage;
use App\WhatsApp\Waha;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Roles & permissions (spatie/laravel-permission, see App\Access\Access).
        // People in ADMIN_EMAILS can never be locked out.
        Gate::before(fn (User $user, string $ability) => $user->isPermanentAdmin() && $ability !== 'deploy' ? true : null);

        Gate::define('deploy', fn (User $user) => app(Deployer::class)->allows($user));
        Gate::define('manage-whatsapp', fn (User $user) => app(Waha::class)->enabled() && $user->can('whatsapp.manage'));
        Gate::define('send-whatsapp', fn (User $user) => app(Waha::class)->enabled() && WhatsappTarget::exists() && $user->can('whatsapp.send'));
        Gate::define('schedule-posts', fn (User $user) => $user->can('posts.manage')
            && ((app(Waha::class)->enabled() && WhatsappTarget::exists()) || app(FacebookPage::class)->enabled()));
        Gate::define('manage-users', fn (User $user) => $user->can('access.manage'));

        // A member's own statement: their own, or anyone's with contributions.view.
        Gate::define('view-statement', fn (User $user, Member $member) => $user->can('contributions.view') || $user->member?->id === $member->id);

        Gate::define('view-receipt', fn (User $user, Payment $payment) => $user->can('contributions.view') || $user->can('finance.view') || ($user->member && $user->member->id === $payment->member_id));

        Livewire::componentHook(GuardHook::class);
        Audit::register();

        // Local development behind an HTTPS preview tunnel (localhost.run, Pinggy...):
        // the tunnel doesn't always send X-Forwarded-Proto, so force https for
        // non-local hostnames. In production the scheme comes from APP_URL / the proxy.
        if (app()->isLocal() && ! app()->runningInConsole() && ! in_array(request()->getHost(), ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            URL::forceScheme('https');
        }

        if (str_starts_with((string) config('app.url'), 'https://') && app()->isProduction()) {
            URL::forceScheme('https');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
