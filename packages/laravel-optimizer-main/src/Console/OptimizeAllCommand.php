<?php

namespace SubhashLadumor\LaravelOptimizer\Console;

use Illuminate\Console\Command;
use SubhashLadumor\LaravelOptimizer\Services\BackendOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\DatabaseOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\CacheOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\FrontendOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\QueueOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\CleanupService;

class OptimizeAllCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:all 
                            {--skip-backend : Skip backend optimization}
                            {--skip-database : Skip database optimization}
                            {--skip-cache : Skip cache optimization}
                            {--skip-frontend : Skip frontend optimization}
                            {--skip-queue : Skip queue optimization}
                            {--skip-cleanup : Skip cleanup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run all Laravel optimizations (backend, database, cache, frontend, queue, cleanup)';

    /**
     * Execute the console command.
     *
     * @param BackendOptimizer $backendOptimizer
     * @param DatabaseOptimizer $databaseOptimizer
     * @param CacheOptimizer $cacheOptimizer
     * @param FrontendOptimizer $frontendOptimizer
     * @param QueueOptimizer $queueOptimizer
     * @param CleanupService $cleanupService
     * @return int
     */
    public function handle(
        BackendOptimizer $backendOptimizer,
        DatabaseOptimizer $databaseOptimizer,
        CacheOptimizer $cacheOptimizer,
        FrontendOptimizer $frontendOptimizer,
        QueueOptimizer $queueOptimizer,
        CleanupService $cleanupService
    ): int {
        $this->info('🚀 Starting Laravel Optimizer Pro...');
        $this->newLine();

        $startTime = microtime(true);
        $results = [];

        // Backend Optimization
        if (!$this->option('skip-backend')) {
            $this->info('⚙️  Optimizing Backend...');
            $results['backend'] = $backendOptimizer->optimize();
            $this->displayResult('Backend', $results['backend']);
            $this->newLine();
        }

        // Database Optimization
        if (!$this->option('skip-database')) {
            $this->info('🗄️  Optimizing Database...');
            $results['database'] = $databaseOptimizer->optimize();
            $this->displayResult('Database', $results['database']);
            $this->newLine();
        }

        // Cache Optimization
        if (!$this->option('skip-cache')) {
            $this->info('💾 Optimizing Cache...');
            $results['cache'] = $cacheOptimizer->optimize();
            $this->displayResult('Cache', $results['cache']);
            $this->newLine();
        }

        // Frontend Optimization
        if (!$this->option('skip-frontend')) {
            $this->info('🎨 Optimizing Frontend...');
            $results['frontend'] = $frontendOptimizer->optimize();
            $this->displayResult('Frontend', $results['frontend']);
            $this->newLine();
        }

        // Queue Optimization
        if (!$this->option('skip-queue')) {
            $this->info('📬 Optimizing Queue...');
            $results['queue'] = $queueOptimizer->optimize();
            $this->displayResult('Queue', $results['queue']);
            $this->newLine();
        }

        // Cleanup
        if (!$this->option('skip-cleanup')) {
            $this->info('🧹 Cleaning up...');
            $results['cleanup'] = $cleanupService->cleanup();
            $this->displayResult('Cleanup', $results['cleanup']);
            $this->newLine();
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->info("✅ All optimizations completed in {$duration}s");
        $this->newLine();

        // Display summary
        $this->displaySummary($results);

        return Command::SUCCESS;
    }

    /**
     * Display result for each optimization.
     *
     * @param string $name
     * @param array $result
     * @return void
     */
    protected function displayResult(string $name, array $result): void
    {
        if ($result['success']) {
            $this->line("   ✓ {$name} optimization completed successfully");
        } else {
            $this->error("   ✗ {$name} optimization failed: " . $result['message']);
        }
    }

    /**
     * Display summary table.
     *
     * @param array $results
     * @return void
     */
    protected function displaySummary(array $results): void
    {
        $this->info('📊 Optimization Summary:');
        $this->newLine();

        $headers = ['Component', 'Status', 'Details'];
        $rows = [];

        foreach ($results as $component => $result) {
            $rows[] = [
                ucfirst($component),
                $result['success'] ? '✓ Success' : '✗ Failed',
                $result['message'] ?? 'N/A',
            ];
        }

        $this->table($headers, $rows);
    }
}
