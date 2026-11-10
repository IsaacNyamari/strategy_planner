<?php

namespace App\Providers;

use App\Deployment\Deployer;
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

        // Only the admins listed in WEB_DEPLOY_ADMINS may deploy from the app.
        Gate::define('deploy', fn (User $user) => app(Deployer::class)->allows($user));

        // WhatsApp: admins link the number and pick groups/channels; any team user can send.
        Gate::define('manage-whatsapp', fn (User $user) => app(Waha::class)->enabled() && $user->isAdmin());

        // Post scheduler: any team user, once there's somewhere to post.
        Gate::define('schedule-posts', fn (User $user) => (app(Waha::class)->enabled() && WhatsappTarget::exists())
            || app(FacebookPage::class)->enabled());

        // Admins create accounts and grant admin (public sign-up is closed).
        Gate::define('manage-users', fn (User $user) => $user->isAdmin());
        Gate::define('send-whatsapp', fn (User $user) => app(Waha::class)->enabled() && WhatsappTarget::exists());

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
