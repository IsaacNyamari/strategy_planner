<?php

namespace SubhashLadumor\LaravelOptimizer\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CacheOptimizer
{
    /**
     * Optimize cache performance.
     *
     * @return array
     */
    public function optimize(): array
    {
        $results = [];

        try {
            // Detect cache driver
            $results['driver'] = $this->detectCacheDriver();

            // Clear stale cache
            $results['clear'] = $this->clearStaleCache();

            // Warm cache
            $results['warm'] = $this->warmCache();

            // Get cache statistics
            $results['statistics'] = $this->getCacheStatistics();

            $this->log('Cache optimization completed successfully', $results);

            return [
                'success' => true,
                'message' => 'Cache optimization completed',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->log('Cache optimization failed: ' . $e->getMessage(), [], 'error');

            return [
                'success' => false,
                'message' => 'Cache optimization failed: ' . $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    /**
     * Detect and validate cache driver.
     *
     * @return array
     */
    protected function detectCacheDriver(): array
    {
        $driver = Config::get('cache.default');
        $recommendations = [];

        // Check if Redis is available
        if ($driver !== 'redis' && $this->isRedisAvailable()) {
            $recommendations[] = 'Redis is available but not configured as cache driver';
        }

        // Check if Memcached is available
        if ($driver !== 'memcached' && $this->isMemcachedAvailable()) {
            $recommendations[] = 'Memcached is available but not configured as cache driver';
        }

        // Warn about file/array drivers in production
        if (in_array($driver, ['file', 'array']) && app()->environment('production')) {
            $recommendations[] = 'Consider using Redis or Memcached for better performance in production';
        }

        return [
            'current' => $driver,
            'recommendations' => $recommendations,
            'redis_available' => $this->isRedisAvailable(),
            'memcached_available' => $this->isMemcachedAvailable(),
        ];
    }

    /**
     * Check if Redis is available.
     *
     * @return bool
     */
    protected function isRedisAvailable(): bool
    {
        try {
            if (extension_loaded('redis')) {
                Redis::connection()->ping();
                return true;
            }
        } catch (\Exception $e) {
            // Redis not available
        }

        return false;
    }

    /**
     * Check if Memcached is available.
     *
     * @return bool
     */
    protected function isMemcachedAvailable(): bool
    {
        return extension_loaded('memcached');
    }

    /**
     * Clear stale cache entries.
     *
     * @return array
     */
    protected function clearStaleCache(): array
    {
        try {
            // Clear application cache
            Cache::flush();

            return [
                'status' => 'success',
                'message' => 'Cache cleared successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Warm cache with frequently accessed data.
     *
     * @return array
     */
    protected function warmCache(): array
    {
        $warmed = [];

        try {
            // Cache configuration
            if (Config::has('app')) {
                Cache::put('app_config', Config::get('app'), Config::get('optimizer.cache_ttl.config', 3600));
                $warmed[] = 'app_config';
            }

            // Cache routes (metadata)
            $routes = app('router')->getRoutes();
            $routeCount = count($routes);
            Cache::put('routes_count', $routeCount, Config::get('optimizer.cache_ttl.routes', 3600));
            $warmed[] = 'routes_metadata';

            return [
                'status' => 'success',
                'cached_items' => $warmed,
                'count' => count($warmed),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'cached_items' => $warmed,
            ];
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    protected function getCacheStatistics(): array
    {
        $driver = Config::get('cache.default');
        $stats = [
            'driver' => $driver,
        ];

        try {
            if ($driver === 'redis') {
                $stats = array_merge($stats, $this->getRedisStatistics());
            }

            return $stats;
        } catch (\Exception $e) {
            return [
                'driver' => $driver,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get Redis cache statistics.
     *
     * @return array
     */
    protected function getRedisStatistics(): array
    {
        try {
            $info = Redis::connection()->info();

            return [
                'used_memory' => $info['used_memory_human'] ?? 'N/A',
                'connected_clients' => $info['connected_clients'] ?? 'N/A',
                'total_commands_processed' => $info['total_commands_processed'] ?? 'N/A',
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate(
                    $info['keyspace_hits'] ?? 0,
                    $info['keyspace_misses'] ?? 0
                ),
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to get Redis statistics',
            ];
        }
    }

    /**
     * Calculate cache hit rate.
     *
     * @param int $hits
     * @param int $misses
     * @return string
     */
    protected function calculateHitRate(int $hits, int $misses): string
    {
        $total = $hits + $misses;

        if ($total === 0) {
            return '0%';
        }

        $rate = ($hits / $total) * 100;

        return round($rate, 2) . '%';
    }

    /**
     * Get TTL recommendations based on cache type.
     *
     * @param string $type
     * @return int
     */
    public function getRecommendedTTL(string $type): int
    {
        return Config::get("optimizer.cache_ttl.{$type}", 3600);
    }

    /**
     * Analyze cache usage patterns.
     *
     * @return array
     */
    public function analyzeCacheUsage(): array
    {
        $driver = Config::get('cache.default');

        $analysis = [
            'driver' => $driver,
            'recommendations' => [],
        ];

        // Check cache driver performance
        if (in_array($driver, ['file', 'array'])) {
            $analysis['recommendations'][] = [
                'type' => 'performance',
                'message' => 'Consider upgrading to Redis or Memcached for better performance',
                'priority' => 'high',
            ];
        }

        // Check if cache is being used effectively
        if ($driver === 'redis' && $this->isRedisAvailable()) {
            $stats = $this->getRedisStatistics();
            $hitRate = (float) str_replace('%', '', $stats['hit_rate'] ?? '0');

            if ($hitRate < 50) {
                $analysis['recommendations'][] = [
                    'type' => 'efficiency',
                    'message' => "Low cache hit rate ({$hitRate}%). Review caching strategy.",
                    'priority' => 'medium',
                ];
            }
        }

        return $analysis;
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
