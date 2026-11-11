<?php

namespace SubhashLadumor\LaravelOptimizer;

use Illuminate\Support\ServiceProvider;
use SubhashLadumor\LaravelOptimizer\Console\OptimizeAllCommand;
use SubhashLadumor\LaravelOptimizer\Console\OptimizeAnalyzeCommand;
use SubhashLadumor\LaravelOptimizer\Console\OptimizeDbCommand;
use SubhashLadumor\LaravelOptimizer\Console\OptimizeCacheCommand;
use SubhashLadumor\LaravelOptimizer\Console\OptimizeFrontendCommand;
use SubhashLadumor\LaravelOptimizer\Console\OptimizeCleanupCommand;
use SubhashLadumor\LaravelOptimizer\Services\BackendOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\DatabaseOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\CacheOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\FrontendOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\QueueOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\CleanupService;
use SubhashLadumor\LaravelOptimizer\Services\Analyzer;

class OptimizerProServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/config/optimizer.php',
            'optimizer'
        );

        // Register services as singletons
        $this->app->singleton(BackendOptimizer::class, function ($app) {
            return new BackendOptimizer();
        });

        $this->app->singleton(DatabaseOptimizer::class, function ($app) {
            return new DatabaseOptimizer();
        });

        $this->app->singleton(CacheOptimizer::class, function ($app) {
            return new CacheOptimizer();
        });

        $this->app->singleton(FrontendOptimizer::class, function ($app) {
            return new FrontendOptimizer();
        });

        $this->app->singleton(QueueOptimizer::class, function ($app) {
            return new QueueOptimizer();
        });

        $this->app->singleton(CleanupService::class, function ($app) {
            return new CleanupService();
        });

        $this->app->singleton(Analyzer::class, function ($app) {
            return new Analyzer();
        });

        // Register main optimizer facade binding
        $this->app->singleton('optimizer-pro', function ($app) {
            return new Analyzer();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/optimizer.php' => config_path('optimizer.php'),
            ], 'optimizer-config');

            // Register commands
            $this->commands([
                OptimizeAllCommand::class,
                OptimizeAnalyzeCommand::class,
                OptimizeDbCommand::class,
                OptimizeCacheCommand::class,
                OptimizeFrontendCommand::class,
                OptimizeCleanupCommand::class,
            ]);
        }

        // Register scheduled tasks if enabled
        if (config('optimizer.cleanup.optimize_schedule')) {
            $this->registerScheduledTasks();
        }
    }

    /**
     * Register scheduled tasks.
     *
     * @return void
     */
    protected function registerScheduledTasks(): void
    {
        $schedule = config('optimizer.cleanup.optimize_schedule', 'weekly');

        // This would typically be registered in the app's Kernel,
        // but we provide the configuration for users to add manually
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            'optimizer-pro',
            BackendOptimizer::class,
            DatabaseOptimizer::class,
            CacheOptimizer::class,
            FrontendOptimizer::class,
            QueueOptimizer::class,
            CleanupService::class,
            Analyzer::class,
        ];
    }
}
