<?php

namespace SubhashLadumor\LaravelOptimizer\Console;

use Illuminate\Console\Command;
use SubhashLadumor\LaravelOptimizer\Services\FrontendOptimizer;

class OptimizeFrontendCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:frontend 
                            {--analyze : Analyze frontend performance}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize frontend assets (minify CSS/JS, compress images, lazy loading)';

    /**
     * Execute the console command.
     *
     * @param FrontendOptimizer $optimizer
     * @return int
     */
    public function handle(FrontendOptimizer $optimizer): int
    {
        $this->info('🎨 Optimizing Frontend Assets...');
        $this->newLine();

        if ($this->option('analyze')) {
            $this->analyzeFrontend($optimizer);
            return Command::SUCCESS;
        }

        $result = $optimizer->optimize();

        if (!$result['success']) {
            $this->error('Frontend optimization failed: ' . $result['message']);
            return Command::FAILURE;
        }

        $this->info('✓ Frontend optimization completed successfully');
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
        // Minification
        if (isset($results['minification'])) {
            $this->info('📦 Asset Minification:');
            $minification = $results['minification'];
            
            if ($minification['status'] === 'success') {
                $this->line("   CSS Files: " . ($minification['css_files'] ?? 0));
                $this->line("   JS Files: " . ($minification['js_files'] ?? 0));
                $this->line("   Recommendation: " . ($minification['recommendation'] ?? 'N/A'));
            }
            $this->newLine();
        }

        // Image Compression
        if (isset($results['image_compression'])) {
            $this->info('🖼️  Image Compression:');
            $compression = $results['image_compression'];
            
            $this->line("   Images Found: " . ($compression['images_found'] ?? 0));
            $this->line("   Quality Setting: " . ($compression['quality'] ?? 'N/A'));
            $this->line("   Recommendation: " . ($compression['recommendation'] ?? 'N/A'));
            $this->newLine();
        }

        // Lazy Loading
        if (isset($results['lazy_loading'])) {
            $this->info('⏳ Lazy Loading:');
            $lazyLoad = $results['lazy_loading'];
            
            $this->line("   Files Needing Lazy Loading: " . ($lazyLoad['files_needing_lazy_loading'] ?? 0));
            
            if (isset($lazyLoad['recommendation'])) {
                $this->line("   Recommendation: " . $lazyLoad['recommendation']);
            }
            
            if (isset($lazyLoad['example'])) {
                $this->line("   Example: " . $lazyLoad['example']);
            }
            $this->newLine();
        }

        // Compression
        if (isset($results['compression'])) {
            $this->info('🗜️  Compression:');
            $compression = $results['compression'];
            
            $this->line("   Type: " . ucfirst($compression['type'] ?? 'N/A'));
            $this->line("   Enabled: " . ($compression['enabled'] ? 'Yes' : 'No'));
            $this->line("   Recommendation: " . ($compression['recommendation'] ?? 'N/A'));
        }
    }

    /**
     * Analyze frontend performance.
     *
     * @param FrontendOptimizer $optimizer
     * @return void
     */
    protected function analyzeFrontend(FrontendOptimizer $optimizer): void
    {
        $this->info('🔍 Analyzing Frontend Performance...');
        $this->newLine();

        $analysis = $optimizer->analyzePerformance();

        // Build Tool
        if (isset($analysis['build_tool'])) {
            $this->info('🛠️  Build Tool:');
            $buildTool = $analysis['build_tool'];
            $this->line("   Tool: " . ($buildTool['tool'] ?? 'N/A'));
            $this->line("   Status: " . ucfirst($buildTool['status'] ?? 'unknown'));
            $this->newLine();
        }

        // Asset Sizes
        if (isset($analysis['asset_sizes'])) {
            $this->info('📊 Asset Sizes:');
            $sizes = $analysis['asset_sizes'];
            
            if (isset($sizes['error'])) {
                $this->line("   Error: " . $sizes['error']);
            } else {
                $this->table(
                    ['Asset Type', 'Size'],
                    [
                        ['CSS', $sizes['css'] ?? 'N/A'],
                        ['JavaScript', $sizes['js'] ?? 'N/A'],
                        ['Images', $sizes['images'] ?? 'N/A'],
                        ['Total', $sizes['total'] ?? 'N/A'],
                    ]
                );
            }
            $this->newLine();
        }

        // Optimization Opportunities
        if (isset($analysis['opportunities']) && count($analysis['opportunities']) > 0) {
            $this->warn('⚠️  Optimization Opportunities:');
            $this->newLine();

            $rows = [];
            foreach ($analysis['opportunities'] as $opportunity) {
                $rows[] = [
                    ucfirst($opportunity['type'] ?? 'general'),
                    $opportunity['target'] ?? 'N/A',
                    $opportunity['count'] ?? 'N/A',
                    $opportunity['recommendation'] ?? 'N/A',
                ];
            }

            $this->table(['Type', 'Target', 'Count', 'Recommendation'], $rows);
        } else {
            $this->info('✓ No optimization opportunities found. Frontend is well optimized!');
        }
    }
}
