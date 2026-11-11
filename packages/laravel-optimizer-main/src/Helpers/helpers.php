<?php

if (!function_exists('optimizer_format_bytes')) {
    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    function optimizer_format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}

if (!function_exists('optimizer_memory_usage')) {
    /**
     * Get current memory usage.
     *
     * @param bool $real_usage
     * @return string
     */
    function optimizer_memory_usage(bool $real_usage = true): string
    {
        return optimizer_format_bytes(memory_get_usage($real_usage));
    }
}

if (!function_exists('optimizer_peak_memory')) {
    /**
     * Get peak memory usage.
     *
     * @param bool $real_usage
     * @return string
     */
    function optimizer_peak_memory(bool $real_usage = true): string
    {
        return optimizer_format_bytes(memory_get_peak_usage($real_usage));
    }
}

if (!function_exists('optimizer_execution_time')) {
    /**
     * Get execution time since Laravel start.
     *
     * @return float
     */
    function optimizer_execution_time(): float
    {
        if (defined('LARAVEL_START')) {
            return round((microtime(true) - LARAVEL_START) * 1000, 2);
        }

        return 0.0;
    }
}

if (!function_exists('optimizer_cache_remember')) {
    /**
     * Cache remember with TTL from config.
     *
     * @param string $key
     * @param string $type
     * @param callable $callback
     * @return mixed
     */
    function optimizer_cache_remember(string $key, string $type, callable $callback): mixed
    {
        $ttl = config("optimizer.cache_ttl.{$type}", 3600);
        return cache()->remember($key, $ttl, $callback);
    }
}

if (!function_exists('optimizer_log')) {
    /**
     * Log optimization activity.
     *
     * @param string $message
     * @param array $context
     * @param string $level
     * @return void
     */
    function optimizer_log(string $message, array $context = [], string $level = 'info'): void
    {
        if (config('optimizer.logging.enabled', true)) {
            $channel = config('optimizer.logging.channel', 'single');
            
            logger()->channel($channel)->$level($message, $context);
        }
    }
}

if (!function_exists('optimizer_is_production')) {
    /**
     * Check if app is in production environment.
     *
     * @return bool
     */
    function optimizer_is_production(): bool
    {
        return app()->environment('production');
    }
}

if (!function_exists('optimizer_should_cache')) {
    /**
     * Determine if caching should be enabled based on environment.
     *
     * @return bool
     */
    function optimizer_should_cache(): bool
    {
        return !app()->environment('local', 'testing');
    }
}

if (!function_exists('optimizer_get_config')) {
    /**
     * Get optimizer configuration value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function optimizer_get_config(string $key, mixed $default = null): mixed
    {
        return config("optimizer.{$key}", $default);
    }
}

if (!function_exists('optimizer_clear_all_cache')) {
    /**
     * Clear all Laravel caches.
     *
     * @return array
     */
    function optimizer_clear_all_cache(): array
    {
        $cleared = [];

        try {
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            $cleared[] = 'application';

            \Illuminate\Support\Facades\Artisan::call('config:clear');
            $cleared[] = 'config';

            \Illuminate\Support\Facades\Artisan::call('route:clear');
            $cleared[] = 'routes';

            \Illuminate\Support\Facades\Artisan::call('view:clear');
            $cleared[] = 'views';

            return [
                'success' => true,
                'cleared' => $cleared,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'cleared' => $cleared,
                'error' => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('optimizer_warm_cache')) {
    /**
     * Warm all Laravel caches.
     *
     * @return array
     */
    function optimizer_warm_cache(): array
    {
        $warmed = [];

        try {
            \Illuminate\Support\Facades\Artisan::call('config:cache');
            $warmed[] = 'config';

            \Illuminate\Support\Facades\Artisan::call('route:cache');
            $warmed[] = 'routes';

            \Illuminate\Support\Facades\Artisan::call('view:cache');
            $warmed[] = 'views';

            return [
                'success' => true,
                'warmed' => $warmed,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'warmed' => $warmed,
                'error' => $e->getMessage(),
            ];
        }
    }
}
