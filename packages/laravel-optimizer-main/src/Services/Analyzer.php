<?php

namespace SubhashLadumor\LaravelOptimizer\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class Analyzer
{
    /**
     * Analyze application performance.
     *
     * @return array
     */
    public function analyze(): array
    {
        $results = [];

        try {
            // Get performance metrics
            $results['performance'] = $this->getPerformanceMetrics();

            // Analyze routes
            $results['routes'] = $this->analyzeRoutes();

            // Analyze database
            $results['database'] = $this->analyzeDatabasePerformance();

            // Analyze cache
            $results['cache'] = $this->analyzeCachePerformance();

            // Get system information
            $results['system'] = $this->getSystemInformation();

            $this->log('Performance analysis completed successfully', $results);

            return [
                'success' => true,
                'message' => 'Performance analysis completed',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->log('Performance analysis failed: ' . $e->getMessage(), [], 'error');

            return [
                'success' => false,
                'message' => 'Performance analysis failed: ' . $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    /**
     * Get performance metrics.
     *
     * @return array
     */
    protected function getPerformanceMetrics(): array
    {
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $currentTime = microtime(true);

        return [
            'request_time' => round(($currentTime - $startTime) * 1000, 2) . 'ms',
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory' => $this->formatBytes(memory_get_peak_usage(true)),
            'memory_limit' => ini_get('memory_limit'),
        ];
    }

    /**
     * Analyze routes.
     *
     * @return array
     */
    protected function analyzeRoutes(): array
    {
        try {
            $routes = Route::getRoutes();
            $totalRoutes = count($routes);
            $slowRoutes = [];

            // Get middleware statistics
            $middlewareCount = [];
            foreach ($routes as $route) {
                $middleware = $route->middleware();
                foreach ($middleware as $m) {
                    $middlewareCount[$m] = ($middlewareCount[$m] ?? 0) + 1;
                }
            }

            arsort($middlewareCount);

            return [
                'total' => $totalRoutes,
                'middleware_usage' => array_slice($middlewareCount, 0, 5, true),
                'recommendation' => $totalRoutes > 500 ? 'Consider route caching: php artisan route:cache' : 'Route count is optimal',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Analyze database performance.
     *
     * @return array
     */
    protected function analyzeDatabasePerformance(): array
    {
        try {
            DB::enableQueryLog();
            
            // Run a simple query to test
            $start = microtime(true);
            DB::connection()->getPdo();
            $connectionTime = round((microtime(true) - $start) * 1000, 2);

            $queries = DB::getQueryLog();
            $totalQueries = count($queries);
            $slowQueries = 0;
            $threshold = Config::get('optimizer.slow_query_threshold', 200);

            foreach ($queries as $query) {
                if (isset($query['time']) && $query['time'] > $threshold) {
                    $slowQueries++;
                }
            }

            DB::disableQueryLog();

            return [
                'connection_time' => $connectionTime . 'ms',
                'total_queries' => $totalQueries,
                'slow_queries' => $slowQueries,
                'threshold' => $threshold . 'ms',
                'driver' => Config::get('database.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Analyze cache performance.
     *
     * @return array
     */
    protected function analyzeCachePerformance(): array
    {
        try {
            $driver = Config::get('cache.default');
            $start = microtime(true);
            
            // Test cache write
            Cache::put('optimizer_test', 'test_value', 10);
            $writeTime = round((microtime(true) - $start) * 1000, 2);

            // Test cache read
            $start = microtime(true);
            Cache::get('optimizer_test');
            $readTime = round((microtime(true) - $start) * 1000, 2);

            // Clean up
            Cache::forget('optimizer_test');

            $performance = 'good';
            if ($writeTime > 10 || $readTime > 5) {
                $performance = 'slow';
            }

            return [
                'driver' => $driver,
                'write_time' => $writeTime . 'ms',
                'read_time' => $readTime . 'ms',
                'performance' => $performance,
                'recommendation' => $performance === 'slow' ? 'Consider using Redis or Memcached' : 'Cache performance is optimal',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get system information.
     *
     * @return array
     */
    protected function getSystemInformation(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'debug_mode' => Config::get('app.debug') ? 'enabled' : 'disabled',
            'timezone' => Config::get('app.timezone'),
            'locale' => Config::get('app.locale'),
        ];
    }

    /**
     * Generate performance report.
     *
     * @return array
     */
    public function generateReport(): array
    {
        $analysis = $this->analyze();
        
        if (!$analysis['success']) {
            return $analysis;
        }

        $results = $analysis['results'];
        $score = $this->calculatePerformanceScore($results);
        $recommendations = $this->generateRecommendations($results);

        return [
            'score' => $score,
            'grade' => $this->getGrade($score),
            'metrics' => $results,
            'recommendations' => $recommendations,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Calculate performance score.
     *
     * @param array $results
     * @return int
     */
    protected function calculatePerformanceScore(array $results): int
    {
        $score = 100;

        // Deduct points for slow cache
        if (isset($results['cache']['performance']) && $results['cache']['performance'] === 'slow') {
            $score -= 20;
        }

        // Deduct points for slow queries
        if (isset($results['database']['slow_queries']) && $results['database']['slow_queries'] > 0) {
            $score -= min(30, $results['database']['slow_queries'] * 5);
        }

        // Deduct points for too many routes without caching
        if (isset($results['routes']['total']) && $results['routes']['total'] > 500) {
            $score -= 15;
        }

        // Deduct points for debug mode in production
        if (isset($results['system']['environment']) && 
            $results['system']['environment'] === 'production' && 
            isset($results['system']['debug_mode']) && 
            $results['system']['debug_mode'] === 'enabled') {
            $score -= 25;
        }

        return max(0, $score);
    }

    /**
     * Get performance grade.
     *
     * @param int $score
     * @return string
     */
    protected function getGrade(int $score): string
    {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    /**
     * Generate recommendations.
     *
     * @param array $results
     * @return array
     */
    protected function generateRecommendations(array $results): array
    {
        $recommendations = [];

        // Cache recommendations
        if (isset($results['cache']['performance']) && $results['cache']['performance'] === 'slow') {
            $recommendations[] = [
                'category' => 'cache',
                'priority' => 'high',
                'message' => $results['cache']['recommendation'] ?? 'Optimize cache performance',
            ];
        }

        // Database recommendations
        if (isset($results['database']['slow_queries']) && $results['database']['slow_queries'] > 0) {
            $recommendations[] = [
                'category' => 'database',
                'priority' => 'high',
                'message' => 'Optimize slow database queries',
            ];
        }

        // Route recommendations
        if (isset($results['routes']['total']) && $results['routes']['total'] > 500) {
            $recommendations[] = [
                'category' => 'routes',
                'priority' => 'medium',
                'message' => 'Enable route caching for better performance',
            ];
        }

        // Environment recommendations
        if (isset($results['system']['debug_mode']) && $results['system']['debug_mode'] === 'enabled' && 
            isset($results['system']['environment']) && $results['system']['environment'] === 'production') {
            $recommendations[] = [
                'category' => 'security',
                'priority' => 'critical',
                'message' => 'Disable debug mode in production',
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
     * Log analysis activity.
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
