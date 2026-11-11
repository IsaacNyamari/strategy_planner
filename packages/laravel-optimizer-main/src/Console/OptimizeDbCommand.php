<?php

namespace SubhashLadumor\LaravelOptimizer\Console;

use Illuminate\Console\Command;
use SubhashLadumor\LaravelOptimizer\Services\DatabaseOptimizer;

class OptimizeDbCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:db 
                            {--stats : Show database statistics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize database (detect slow queries, suggest indexes, optimize tables)';

    /**
     * Execute the console command.
     *
     * @param DatabaseOptimizer $optimizer
     * @return int
     */
    public function handle(DatabaseOptimizer $optimizer): int
    {
        $this->info('🗄️  Optimizing Database...');
        $this->newLine();

        if ($this->option('stats')) {
            $this->displayStatistics($optimizer);
            return Command::SUCCESS;
        }

        $result = $optimizer->optimize();

        if (!$result['success']) {
            $this->error('Database optimization failed: ' . $result['message']);
            return Command::FAILURE;
        }

        $this->info('✓ Database optimization completed successfully');
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
        // Slow Queries
        if (isset($results['slow_queries'])) {
            $this->info('🐌 Slow Queries Analysis:');
            $slowQueries = $results['slow_queries'];
            
            $this->line("   Threshold: " . ($slowQueries['threshold'] ?? 'N/A'));
            $this->line("   Found: " . ($slowQueries['count'] ?? 0) . " slow queries");
            
            if (isset($slowQueries['queries']) && count($slowQueries['queries']) > 0) {
                $this->newLine();
                $this->warn('   Slow queries detected:');
                foreach (array_slice($slowQueries['queries'], 0, 5) as $query) {
                    $this->line("   - {$query['time']}: " . substr($query['query'], 0, 100));
                }
            }
            $this->newLine();
        }

        // Index Suggestions
        if (isset($results['index_suggestions'])) {
            $this->info('📑 Index Suggestions:');
            $suggestions = $results['index_suggestions'];
            
            $this->line("   Found: " . ($suggestions['count'] ?? 0) . " suggestions");
            
            if (isset($suggestions['suggestions']) && count($suggestions['suggestions']) > 0) {
                $this->newLine();
                $rows = [];
                foreach (array_slice($suggestions['suggestions'], 0, 10) as $suggestion) {
                    $rows[] = [
                        $suggestion['table'] ?? 'N/A',
                        $suggestion['column'] ?? 'N/A',
                        $suggestion['reason'] ?? 'N/A',
                    ];
                }
                $this->table(['Table', 'Column', 'Reason'], $rows);
            }
            $this->newLine();
        }

        // Table Optimization
        if (isset($results['table_optimization'])) {
            $this->info('🔧 Table Optimization:');
            $optimization = $results['table_optimization'];
            
            if ($optimization['status'] === 'success') {
                $this->line("   ✓ Optimized " . ($optimization['count'] ?? 0) . " tables");
            } else {
                $this->line("   " . ($optimization['message'] ?? 'Skipped'));
            }
            $this->newLine();
        }

        // Query Cache
        if (isset($results['query_cache'])) {
            $this->info('💾 Query Cache:');
            $cache = $results['query_cache'];
            
            $this->line("   Status: " . ucfirst($cache['status'] ?? 'unknown'));
            if (isset($cache['ttl'])) {
                $this->line("   TTL: " . $cache['ttl']);
            }
            if (isset($cache['driver'])) {
                $this->line("   Driver: " . $cache['driver']);
            }
        }
    }

    /**
     * Display database statistics.
     *
     * @param DatabaseOptimizer $optimizer
     * @return void
     */
    protected function displayStatistics(DatabaseOptimizer $optimizer): void
    {
        $this->info('📊 Database Statistics:');
        $this->newLine();

        $stats = $optimizer->getStatistics();

        $this->line('Driver: ' . ($stats['driver'] ?? 'N/A'));
        $this->line('Connection: ' . ($stats['connection'] ?? 'N/A'));
        $this->newLine();

        if (isset($stats['tables']) && count($stats['tables']) > 0) {
            $this->info('Tables:');
            $rows = [];
            foreach (array_slice($stats['tables'], 0, 20) as $table) {
                $rows[] = [
                    $table['name'] ?? 'N/A',
                    $table['rows'] ?? 'N/A',
                    $table['size'] ?? 'N/A',
                ];
            }
            $this->table(['Table Name', 'Rows', 'Size'], $rows);
        }
    }
}
