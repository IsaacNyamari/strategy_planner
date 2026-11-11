<?php

namespace SubhashLadumor\LaravelOptimizer\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class BackendOptimizer
{
    /**
     * Optimize Laravel backend performance.
     *
     * @return array
     */
    public function optimize(): array
    {
        $results = [];

        try {
            // Optimize configuration cache
            $results['config'] = $this->optimizeConfig();

            // Optimize route cache
            $results['routes'] = $this->optimizeRoutes();

            // Optimize view cache
            $results['views'] = $this->optimizeViews();

            // Optimize event cache
            $results['events'] = $this->optimizeEvents();

            // Analyze memory usage
            $results['memory'] = $this->analyzeMemory();

            $this->log('Backend optimization completed successfully', $results);

            return [
                'success' => true,
                'message' => 'Backend optimization completed',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->log('Backend optimization failed: ' . $e->getMessage(), [], 'error');

            return [
                'success' => false,
                'message' => 'Backend optimization failed: ' . $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    /**
     * Optimize configuration cache.
     *
     * @return array
     */
    protected function optimizeConfig(): array
    {
        try {
            Artisan::call('config:cache');

            return [
                'status' => 'success',
                'message' => 'Configuration cached successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Optimize route cache.
     *
     * @return array
     */
    protected function optimizeRoutes(): array
    {
        try {
            Artisan::call('route:cache');

            return [
                'status' => 'success',
                'message' => 'Routes cached successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Optimize view cache.
     *
     * @return array
     */
    protected function optimizeViews(): array
    {
        try {
            Artisan::call('view:cache');

            return [
                'status' => 'success',
                'message' => 'Views cached successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Optimize event cache.
     *
     * @return array
     */
    protected function optimizeEvents(): array
    {
        try {
            if (method_exists(Artisan::class, 'call')) {
                Artisan::call('event:cache');
                
                return [
                    'status' => 'success',
                    'message' => 'Events cached successfully',
                ];
            }

            return [
                'status' => 'skipped',
                'message' => 'Event caching not available',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Analyze memory usage.
     *
     * @return array
     */
    protected function analyzeMemory(): array
    {
        $memoryUsage = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = ini_get('memory_limit');

        return [
            'current' => $this->formatBytes($memoryUsage),
            'peak' => $this->formatBytes($peakMemory),
            'limit' => $memoryLimit,
            'percentage' => $this->calculateMemoryPercentage($peakMemory, $memoryLimit),
        ];
    }

    /**
     * Analyze environment configuration.
     *
     * @return array
     */
    public function analyzeEnvironment(): array
    {
        $recommendations = [];

        // Check APP_DEBUG
        if (Config::get('app.debug') === true && app()->environment('production')) {
            $recommendations[] = [
                'key' => 'APP_DEBUG',
                'current' => 'true',
                'recommended' => 'false',
                'reason' => 'Debug mode should be disabled in production',
            ];
        }

        // Check APP_ENV
        if (app()->environment('local') && php_sapi_name() !== 'cli') {
            $recommendations[] = [
                'key' => 'APP_ENV',
                'current' => 'local',
                'recommended' => 'production',
                'reason' => 'Consider using production environment',
            ];
        }

        // Check cache driver
        $cacheDriver = Config::get('cache.default');
        if (in_array($cacheDriver, ['array', 'file']) && app()->environment('production')) {
            $recommendations[] = [
                'key' => 'CACHE_DRIVER',
                'current' => $cacheDriver,
                'recommended' => 'redis',
                'reason' => 'Use Redis or Memcached for better performance in production',
            ];
        }

        // Check session driver
        $sessionDriver = Config::get('session.driver');
        if ($sessionDriver === 'file' && app()->environment('production')) {
            $recommendations[] = [
                'key' => 'SESSION_DRIVER',
                'current' => $sessionDriver,
                'recommended' => 'redis',
                'reason' => 'Use Redis or database for session in production',
            ];
        }

        // Check queue connection
        $queueConnection = Config::get('queue.default');
        if ($queueConnection === 'sync' && app()->environment('production')) {
            $recommendations[] = [
                'key' => 'QUEUE_CONNECTION',
                'current' => $queueConnection,
                'recommended' => 'redis',
                'reason' => 'Use async queue driver for better performance',
            ];
        }

        return $recommendations;
    }

    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes
     * @return string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Calculate memory usage percentage.
     *
     * @param int $used
     * @param string $limit
     * @return float
     */
    protected function calculateMemoryPercentage(int $used, string $limit): float
    {
        $limitBytes = $this->convertToBytes($limit);
        
        if ($limitBytes <= 0) {
            return 0;
        }

        return round(($used / $limitBytes) * 100, 2);
    }

    /**
     * Convert memory limit string to bytes.
     *
     * @param string $value
     * @return int
     */
    protected function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Log optimization activity.
     *
     * @param string $message
     * @param array $context
     * @param string $level
     * @return void
     */
    protected function log(string $message, array $context = [], string $level = 'info'): void
    {
        if (Config::get('optimizer.logging.enabled', true)) {
            Log::channel(Config::get('optimizer.logging.channel', 'single'))
                ->$level($message, $context);
        }
    }
}
