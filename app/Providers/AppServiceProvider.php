<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
