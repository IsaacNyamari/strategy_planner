<?php

namespace SubhashLadumor\LaravelOptimizer\Console;

use Illuminate\Console\Command;
use SubhashLadumor\LaravelOptimizer\Services\Analyzer;

class OptimizeAnalyzeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:analyze 
                            {--report : Generate detailed report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze Laravel application performance (TTFB, routes, queries, cache hit rate)';

    /**
     * Execute the console command.
     *
     * @param Analyzer $analyzer
     * @return int
     */
    public function handle(Analyzer $analyzer): int
    {
        $this->info('🔍 Analyzing Application Performance...');
        $this->newLine();

        if ($this->option('report')) {
            $report = $analyzer->generateReport();
            $this->displayReport($report);
        } else {
            $analysis = $analyzer->analyze();
            
            if (!$analysis['success']) {
                $this->error('Analysis failed: ' . $analysis['message']);
                return Command::FAILURE;
            }

            $this->displayAnalysis($analysis['results']);
        }

        return Command::SUCCESS;
    }

    /**
     * Display analysis results.
     *
     * @param array $results
     * @return void
     */
    protected function displayAnalysis(array $results): void
    {
        // Performance Metrics
        if (isset($results['performance'])) {
            $this->info('⚡ Performance Metrics:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Request Time', $results['performance']['request_time'] ?? 'N/A'],
                    ['Memory Usage', $results['performance']['memory_usage'] ?? 'N/A'],
                    ['Peak Memory', $results['performance']['peak_memory'] ?? 'N/A'],
                    ['Memory Limit', $results['performance']['memory_limit'] ?? 'N/A'],
                ]
            );
            $this->newLine();
        }

        // Routes
        if (isset($results['routes'])) {
            $this->info('🛣️  Routes Analysis:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Routes', $results['routes']['total'] ?? 'N/A'],
                    ['Recommendation', $results['routes']['recommendation'] ?? 'N/A'],
                ]
            );
            $this->newLine();
        }

        // Database
        if (isset($results['database'])) {
            $this->info('🗄️  Database Analysis:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Connection Time', $results['database']['connection_time'] ?? 'N/A'],
                    ['Total Queries', $results['database']['total_queries'] ?? 'N/A'],
                    ['Slow Queries', $results['database']['slow_queries'] ?? 'N/A'],
                    ['Threshold', $results['database']['threshold'] ?? 'N/A'],
                    ['Driver', $results['database']['driver'] ?? 'N/A'],
                ]
            );
            $this->newLine();
        }

        // Cache
        if (isset($results['cache'])) {
            $this->info('💾 Cache Analysis:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Driver', $results['cache']['driver'] ?? 'N/A'],
                    ['Write Time', $results['cache']['write_time'] ?? 'N/A'],
                    ['Read Time', $results['cache']['read_time'] ?? 'N/A'],
                    ['Performance', $results['cache']['performance'] ?? 'N/A'],
                    ['Recommendation', $results['cache']['recommendation'] ?? 'N/A'],
                ]
            );
            $this->newLine();
        }

        // System
        if (isset($results['system'])) {
            $this->info('💻 System Information:');
            $this->table(
                ['Property', 'Value'],
                [
                    ['PHP Version', $results['system']['php_version'] ?? 'N/A'],
                    ['Laravel Version', $results['system']['laravel_version'] ?? 'N/A'],
                    ['Environment', $results['system']['environment'] ?? 'N/A'],
                    ['Debug Mode', $results['system']['debug_mode'] ?? 'N/A'],
                    ['Timezone', $results['system']['timezone'] ?? 'N/A'],
                    ['Locale', $results['system']['locale'] ?? 'N/A'],
                ]
            );
        }
    }

    /**
     * Display performance report.
     *
     * @param array $report
     * @return void
     */
    protected function displayReport(array $report): void
    {
        $this->info('📊 Performance Report');
        $this->newLine();

        // Score and Grade
        $score = $report['score'] ?? 0;
        $grade = $report['grade'] ?? 'F';

        $this->info("Performance Score: {$score}/100 (Grade: {$grade})");
        $this->newLine();

        // Recommendations
        if (isset($report['recommendations']) && count($report['recommendations']) > 0) {
            $this->warn('⚠️  Recommendations:');
            $this->newLine();

            $rows = [];
            foreach ($report['recommendations'] as $rec) {
                $rows[] = [
                    ucfirst($rec['category'] ?? 'General'),
                    strtoupper($rec['priority'] ?? 'low'),
                    $rec['message'] ?? 'N/A',
                ];
            }

            $this->table(['Category', 'Priority', 'Recommendation'], $rows);
        } else {
            $this->info('✓ No critical recommendations. Your application is well optimized!');
        }

        $this->newLine();
        $this->info('Generated at: ' . ($report['generated_at'] ?? 'N/A'));
    }
}
