<?php

namespace SubhashLadumor\LaravelOptimizer\Console;

use Illuminate\Console\Command;
use SubhashLadumor\LaravelOptimizer\Services\CacheOptimizer;

class OptimizeCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:cache 
                            {--analyze : Analyze cache usage patterns}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize cache and sessions (detect driver, warm cache, analyze usage)';

    /**
     * Execute the console command.
     *
     * @param CacheOptimizer $optimizer
     * @return int
     */
    public function handle(CacheOptimizer $optimizer): int
    {
        $this->info('💾 Optimizing Cache...');
        $this->newLine();

        if ($this->option('analyze')) {
            $this->analyzeCache($optimizer);
            return Command::SUCCESS;
        }

        $result = $optimizer->optimize();

        if (!$result['success']) {
            $this->error('Cache optimization failed: ' . $result['message']);
            return Command::FAILURE;
        }

        $this->info('✓ Cache optimization completed successfully');
        $this->newLine();

        $this->displayResults($result['results']);

        return Command::SUCCESS;
    }

    /**
     * Display optimization results.
     *
     * @param array $results
     * @return void
     */
    protected function displayResults(array $results): void
    {
        // Driver Detection
        if (isset($results['driver'])) {
            $this->info('🔍 Cache Driver:');
            $driver = $results['driver'];
            
            $this->line("   Current: " . ($driver['current'] ?? 'N/A'));
            $this->line("   Redis Available: " . ($driver['redis_available'] ? 'Yes' : 'No'));
            $this->line("   Memcached Available: " . ($driver['memcached_available'] ? 'Yes' : 'No'));
            
            if (isset($driver['recommendations']) && count($driver['recommendations']) > 0) {
                $this->newLine();
                $this->warn('   Recommendations:');
                foreach ($driver['recommendations'] as $recommendation) {
                    $this->line("   - {$recommendation}");
                }
            }
            $this->newLine();
        }

        // Cache Clear
        if (isset($results['clear'])) {
            $this->info('🧹 Cache Clear:');
            $clear = $results['clear'];
            
            $this->line("   Status: " . ucfirst($clear['status'] ?? 'unknown'));
            if (isset($clear['message'])) {
                $this->line("   " . $clear['message']);
            }
            $this->newLine();
        }

        // Cache Warming
        if (isset($results['warm'])) {
            $this->info('🔥 Cache Warming:');
            $warm = $results['warm'];
            
            $this->line("   Status: " . ucfirst($warm['status'] ?? 'unknown'));
            $this->line("   Items Cached: " . ($warm['count'] ?? 0));
            
            if (isset($warm['cached_items']) && count($warm['cached_items']) > 0) {
                $this->line("   Items: " . implode(', ', $warm['cached_items']));
            }
            $this->newLine();
        }

        // Statistics
        if (isset($results['statistics'])) {
            $this->info('📊 Cache Statistics:');
            $stats = $results['statistics'];
            
            foreach ($stats as $key => $value) {
                if (!is_array($value)) {
                    $this->line("   " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}");
                }
            }
        }
    }

    /**
     * Analyze cache usage.
     *
     * @param CacheOptimizer $optimizer
     * @return void
     */
    protected function analyzeCache(CacheOptimizer $optimizer): void
    {
        $this->info('🔍 Analyzing Cache Usage...');
        $this->newLine();

        $analysis = $optimizer->analyzeCacheUsage();

        $this->line('Driver: ' . ($analysis['driver'] ?? 'N/A'));
        $this->newLine();

        if (isset($analysis['recommendations']) && count($analysis['recommendations']) > 0) {
            $this->warn('Recommendations:');
            $this->newLine();

            $rows = [];
            foreach ($analysis['recommendations'] as $rec) {
                $rows[] = [
                    ucfirst($rec['type'] ?? 'general'),
                    strtoupper($rec['priority'] ?? 'low'),
                    $rec['message'] ?? 'N/A',
                ];
            }

            $this->table(['Type', 'Priority', 'Message'], $rows);
        } else {
            $this->info('✓ Cache is well configured!');
        }
    }
}
