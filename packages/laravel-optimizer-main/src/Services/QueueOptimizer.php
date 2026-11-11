<?php

namespace SubhashLadumor\LaravelOptimizer\Services;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class QueueOptimizer
{
    /**
     * Optimize queue performance.
     *
     * @return array
     */
    public function optimize(): array
    {
        $results = [];

        try {
            // Analyze queue configuration
            $results['configuration'] = $this->analyzeConfiguration();

            // Analyze failed jobs
            $results['failed_jobs'] = $this->analyzeFailedJobs();

            // Get recommendations
            $results['recommendations'] = $this->getRecommendations();

            $this->log('Queue optimization completed successfully', $results);

            return [
                'success' => true,
                'message' => 'Queue optimization completed',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->log('Queue optimization failed: ' . $e->getMessage(), [], 'error');

            return [
                'success' => false,
                'message' => 'Queue optimization failed: ' . $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    /**
     * Analyze queue configuration.
     *
     * @return array
     */
    protected function analyzeConfiguration(): array
    {
        $connection = Config::get('queue.default');
        $config = Config::get("queue.connections.{$connection}");

        return [
            'connection' => $connection,
            'driver' => $config['driver'] ?? 'unknown',
            'retry_limit' => Config::get('optimizer.queue.retry_limit', 3),
            'batch_size' => Config::get('optimizer.queue.batch_size', 100),
            'memory_limit' => Config::get('optimizer.queue.memory_limit', '512M'),
            'timeout' => Config::get('optimizer.queue.timeout', 60),
        ];
    }

    /**
     * Analyze failed jobs.
     *
     * @return array
     */
    protected function analyzeFailedJobs(): array
    {
        try {
            $failedJobs = DB::table('failed_jobs')->get();
            $analysis = [
                'total' => $failedJobs->count(),
                'recent' => [],
                'by_exception' => [],
            ];

            // Get recent failures
            $recent = $failedJobs->sortByDesc('failed_at')->take(5);
            foreach ($recent as $job) {
                $analysis['recent'][] = [
                    'id' => $job->id,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                ];
            }

            // Group by exception
            $exceptionCounts = [];
            foreach ($failedJobs as $job) {
                $payload = json_decode($job->payload, true);
                $exception = $payload['exception'] ?? 'Unknown';
                
                // Extract exception class name
                if (preg_match('/([A-Za-z0-9_\\\\]+Exception)/', $exception, $matches)) {
                    $exceptionClass = $matches[1];
                    $exceptionCounts[$exceptionClass] = ($exceptionCounts[$exceptionClass] ?? 0) + 1;
                }
            }

            arsort($exceptionCounts);
            $analysis['by_exception'] = array_slice($exceptionCounts, 0, 5, true);

            return $analysis;
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get optimization recommendations.
     *
     * @return array
     */
    protected function getRecommendations(): array
    {
        $recommendations = [];
        $connection = Config::get('queue.default');

        // Check queue driver
        if ($connection === 'sync') {
            $recommendations[] = [
                'type' => 'performance',
                'message' => 'Using sync queue driver. Consider using redis, database, or SQS for async processing.',
                'priority' => 'high',
            ];
        }

        // Check for failed jobs
        try {
            $failedCount = DB::table('failed_jobs')->count();
            
            if ($failedCount > 100) {
                $recommendations[] = [
                    'type' => 'reliability',
                    'message' => "You have {$failedCount} failed jobs. Review and retry or clear them.",
                    'priority' => 'medium',
                ];
            }
        } catch (\Exception $e) {
            // Table might not exist
        }

        // Check memory limit
        $memoryLimit = Config::get('optimizer.queue.memory_limit', '512M');
        $limitBytes = $this->convertToBytes($memoryLimit);
        
        if ($limitBytes < 512 * 1024 * 1024) {
            $recommendations[] = [
                'type' => 'performance',
                'message' => 'Queue memory limit is low. Consider increasing for better performance.',
                'priority' => 'low',
            ];
        }

        return $recommendations;
    }

    /**
     * Get retry strategies for failed jobs.
     *
     * @return array
     */
    public function getRetryStrategies(): array
    {
        try {
            $failedJobs = DB::table('failed_jobs')->get();
            $strategies = [];

            foreach ($failedJobs->groupBy('queue') as $queue => $jobs) {
                $strategies[$queue] = [
                    'count' => $jobs->count(),
                    'recommendation' => $this->getRetryRecommendation($jobs->count()),
                ];
            }

            return $strategies;
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get retry recommendation based on failed job count.
     *
     * @param int $count
     * @return string
     */
    protected function getRetryRecommendation(int $count): string
    {
        if ($count === 0) {
            return 'No failed jobs';
        }

        if ($count < 10) {
            return 'Retry individual jobs: php artisan queue:retry <job-id>';
        }

        if ($count < 100) {
            return 'Retry all failed jobs: php artisan queue:retry all';
        }

        return 'Review error patterns before retrying. Consider clearing old failures.';
    }

    /**
     * Tune queue worker settings.
     *
     * @return array
     */
    public function tuneWorkerSettings(): array
    {
        $settings = [
            'memory' => Config::get('optimizer.queue.memory_limit', '512M'),
            'timeout' => Config::get('optimizer.queue.timeout', 60),
            'sleep' => 3,
            'tries' => Config::get('optimizer.queue.retry_limit', 3),
            'max_jobs' => Config::get('optimizer.queue.batch_size', 100),
        ];

        $command = sprintf(
            'php artisan queue:work --memory=%s --timeout=%d --sleep=%d --tries=%d --max-jobs=%d',
            $settings['memory'],
            $settings['timeout'],
            $settings['sleep'],
            $settings['tries'],
            $settings['max_jobs']
        );

        return [
            'settings' => $settings,
            'recommended_command' => $command,
        ];
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
