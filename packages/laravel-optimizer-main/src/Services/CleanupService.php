<?php

namespace SubhashLadumor\LaravelOptimizer\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class CleanupService
{
    /**
     * Perform cleanup operations.
     *
     * @return array
     */
    public function cleanup(): array
    {
        $results = [];

        try {
            // Clear old logs
            $results['logs'] = $this->clearOldLogs();

            // Clear old sessions
            $results['sessions'] = $this->clearOldSessions();

            // Clear cache
            $results['cache'] = $this->clearCache();

            // Clear compiled files
            if (Config::get('optimizer.cleanup.clear_compiled', true)) {
                $results['compiled'] = $this->clearCompiledFiles();
            }

            // Clear temp files
            if (Config::get('optimizer.cleanup.clear_temp_files', true)) {
                $results['temp'] = $this->clearTempFiles();
            }

            $this->log('Cleanup completed successfully', $results);

            return [
                'success' => true,
                'message' => 'Cleanup completed',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->log('Cleanup failed: ' . $e->getMessage(), [], 'error');

            return [
                'success' => false,
                'message' => 'Cleanup failed: ' . $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    /**
     * Clear old log files.
     *
     * @return array
     */
    protected function clearOldLogs(): array
    {
        try {
            $logPath = storage_path('logs');
            $days = Config::get('optimizer.cleanup.log_days', 7);
            $threshold = now()->subDays($days);
            $deleted = [];

            if (File::exists($logPath)) {
                $files = File::files($logPath);

                foreach ($files as $file) {
                    if ($file->getFilename() === 'optimizer.log') {
                        continue; // Skip optimizer log
                    }

                    $fileTime = File::lastModified($file->getPathname());
                    
                    if ($fileTime < $threshold->timestamp) {
                        File::delete($file->getPathname());
                        $deleted[] = $file->getFilename();
                    }
                }
            }

            return [
                'status' => 'success',
                'deleted' => count($deleted),
                'days' => $days,
                'files' => $deleted,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clear old session files.
     *
     * @return array
     */
    protected function clearOldSessions(): array
    {
        try {
            $sessionDriver = Config::get('session.driver');

            if ($sessionDriver === 'file') {
                $sessionPath = storage_path('framework/sessions');
                $days = Config::get('optimizer.cleanup.session_days', 30);
                $threshold = now()->subDays($days);
                $deleted = 0;

                if (File::exists($sessionPath)) {
                    $files = File::files($sessionPath);

                    foreach ($files as $file) {
                        $fileTime = File::lastModified($file->getPathname());
                        
                        if ($fileTime < $threshold->timestamp) {
                            File::delete($file->getPathname());
                            $deleted++;
                        }
                    }
                }

                return [
                    'status' => 'success',
                    'deleted' => $deleted,
                    'days' => $days,
                ];
            }

            return [
                'status' => 'skipped',
                'message' => "Session driver is {$sessionDriver}, file cleanup not needed",
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clear application cache.
     *
     * @return array
     */
    protected function clearCache(): array
    {
        $cleared = [];

        try {
            // Clear application cache
            Artisan::call('cache:clear');
            $cleared[] = 'application';

            // Clear config cache
            Artisan::call('config:clear');
            $cleared[] = 'config';

            // Clear route cache
            Artisan::call('route:clear');
            $cleared[] = 'routes';

            // Clear view cache
            Artisan::call('view:clear');
            $cleared[] = 'views';

            return [
                'status' => 'success',
                'cleared' => $cleared,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'partial',
                'cleared' => $cleared,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clear compiled files.
     *
     * @return array
     */
    protected function clearCompiledFiles(): array
    {
        $deleted = [];

        try {
            // Clear compiled class files
            $compiledPath = storage_path('framework/compiled.php');
            if (File::exists($compiledPath)) {
                File::delete($compiledPath);
                $deleted[] = 'compiled.php';
            }

            // Clear services compiled file
            $servicesPath = storage_path('framework/services.php');
            if (File::exists($servicesPath)) {
                File::delete($servicesPath);
                $deleted[] = 'services.php';
            }

            // Clear packages compiled file
            $packagesPath = storage_path('framework/packages.php');
            if (File::exists($packagesPath)) {
                File::delete($packagesPath);
                $deleted[] = 'packages.php';
            }

            return [
                'status' => 'success',
                'deleted' => $deleted,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'deleted' => $deleted,
            ];
        }
    }

    /**
     * Clear temporary files.
     *
     * @return array
     */
    protected function clearTempFiles(): array
    {
        $deleted = 0;

        try {
            $tempPaths = [
                storage_path('framework/cache/data'),
                storage_path('framework/views'),
            ];

            foreach ($tempPaths as $path) {
                if (File::exists($path)) {
                    $files = File::files($path);
                    
                    foreach ($files as $file) {
                        File::delete($file->getPathname());
                        $deleted++;
                    }
                }
            }

            return [
                'status' => 'success',
                'deleted' => $deleted,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get cleanup statistics.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        try {
            return [
                'logs' => $this->getLogStatistics(),
                'cache' => $this->getCacheStatistics(),
                'storage' => $this->getStorageStatistics(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get log statistics.
     *
     * @return array
     */
    protected function getLogStatistics(): array
    {
        $logPath = storage_path('logs');
        $size = 0;
        $count = 0;

        if (File::exists($logPath)) {
            $files = File::files($logPath);
            $count = count($files);
            
            foreach ($files as $file) {
                $size += $file->getSize();
            }
        }

        return [
            'count' => $count,
            'size' => $this->formatBytes($size),
        ];
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    protected function getCacheStatistics(): array
    {
        $cachePath = storage_path('framework/cache');
        $size = 0;
        $count = 0;

        if (File::exists($cachePath)) {
            $files = File::allFiles($cachePath);
            $count = count($files);
            
            foreach ($files as $file) {
                $size += $file->getSize();
            }
        }

        return [
            'count' => $count,
            'size' => $this->formatBytes($size),
        ];
    }

    /**
     * Get storage statistics.
     *
     * @return array
     */
    protected function getStorageStatistics(): array
    {
        $storagePath = storage_path();
        $size = 0;

        if (File::exists($storagePath)) {
            $files = File::allFiles($storagePath);
            
            foreach ($files as $file) {
                $size += $file->getSize();
            }
        }

        return [
            'total_size' => $this->formatBytes($size),
        ];
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
     * Log cleanup activity.
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
