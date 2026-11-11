<?php

namespace SubhashLadumor\LaravelOptimizer\Console;

use Illuminate\Console\Command;
use SubhashLadumor\LaravelOptimizer\Services\CleanupService;

class OptimizeCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:cleanup 
                            {--stats : Show cleanup statistics}
                            {--force : Force cleanup without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup logs, sessions, cache, and temporary files';

    /**
     * Execute the console command.
     *
     * @param CleanupService $service
     * @return int
     */
    public function handle(CleanupService $service): int
    {
        $this->info('🧹 Starting Cleanup...');
        $this->newLine();

        if ($this->option('stats')) {
            $this->displayStatistics($service);
            return Command::SUCCESS;
        }

        // Confirm cleanup
        if (!$this->option('force')) {
            if (!$this->confirm('This will delete old logs, sessions, and temporary files. Continue?', true)) {
                $this->warn('Cleanup cancelled.');
                return Command::SUCCESS;
            }
        }

        $result = $service->cleanup();

        if (!$result['success']) {
            $this->error('Cleanup failed: ' . $result['message']);
            return Command::FAILURE;
        }

        $this->info('✓ Cleanup completed successfully');
        $this->newLine();

        $this->displayResults($result['results']);

        return Command::SUCCESS;
    }

    /**
     * Display cleanup results.
     *
     * @param array $results
     * @return void
     */
    protected function displayResults(array $results): void
    {
        // Logs
        if (isset($results['logs'])) {
            $this->info('📄 Log Files:');
            $logs = $results['logs'];
            
            if ($logs['status'] === 'success') {
                $this->line("   Deleted: " . ($logs['deleted'] ?? 0) . " files");
                $this->line("   Retention: " . ($logs['days'] ?? 0) . " days");
                
                if (isset($logs['files']) && count($logs['files']) > 0) {
                    $this->line("   Files: " . implode(', ', array_slice($logs['files'], 0, 5)));
                }
            } else {
                $this->line("   Status: " . ($logs['message'] ?? 'Failed'));
            }
            $this->newLine();
        }

        // Sessions
        if (isset($results['sessions'])) {
            $this->info('🔐 Session Files:');
            $sessions = $results['sessions'];
            
            if ($sessions['status'] === 'success') {
                $this->line("   Deleted: " . ($sessions['deleted'] ?? 0) . " files");
                $this->line("   Retention: " . ($sessions['days'] ?? 0) . " days");
            } else {
                $this->line("   Status: " . ($sessions['message'] ?? 'Skipped'));
            }
            $this->newLine();
        }

        // Cache
        if (isset($results['cache'])) {
            $this->info('💾 Cache:');
            $cache = $results['cache'];
            
            if ($cache['status'] === 'success') {
                $this->line("   Cleared: " . implode(', ', $cache['cleared'] ?? []));
            } else {
                $this->line("   Status: " . ucfirst($cache['status'] ?? 'unknown'));
                if (isset($cache['message'])) {
                    $this->line("   Message: " . $cache['message']);
                }
            }
            $this->newLine();
        }

        // Compiled Files
        if (isset($results['compiled'])) {
            $this->info('📦 Compiled Files:');
            $compiled = $results['compiled'];
            
            if ($compiled['status'] === 'success') {
                $this->line("   Deleted: " . implode(', ', $compiled['deleted'] ?? []));
            } else {
                $this->line("   Status: " . ($compiled['message'] ?? 'Failed'));
            }
            $this->newLine();
        }

        // Temp Files
        if (isset($results['temp'])) {
            $this->info('🗑️  Temporary Files:');
            $temp = $results['temp'];
            
            if ($temp['status'] === 'success') {
                $this->line("   Deleted: " . ($temp['deleted'] ?? 0) . " files");
            } else {
                $this->line("   Status: " . ($temp['message'] ?? 'Failed'));
            }
        }
    }

    /**
     * Display cleanup statistics.
     *
     * @param CleanupService $service
     * @return void
     */
    protected function displayStatistics(CleanupService $service): void
    {
        $this->info('📊 Cleanup Statistics:');
        $this->newLine();

        $stats = $service->getStatistics();

        // Logs
        if (isset($stats['logs'])) {
            $this->info('Log Files:');
            $this->line("   Count: " . ($stats['logs']['count'] ?? 0));
            $this->line("   Size: " . ($stats['logs']['size'] ?? 'N/A'));
            $this->newLine();
        }

        // Cache
        if (isset($stats['cache'])) {
            $this->info('Cache Files:');
            $this->line("   Count: " . ($stats['cache']['count'] ?? 0));
            $this->line("   Size: " . ($stats['cache']['size'] ?? 'N/A'));
            $this->newLine();
        }

        // Storage
        if (isset($stats['storage'])) {
            $this->info('Total Storage:');
            $this->line("   Size: " . ($stats['storage']['total_size'] ?? 'N/A'));
        }
    }
}

