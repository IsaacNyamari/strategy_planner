<?php

namespace SubhashLadumor\LaravelOptimizer\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class FrontendOptimizer
{
    /**
     * Optimize frontend assets.
     *
     * @return array
     */
    public function optimize(): array
    {
        $results = [];

        try {
            // Minify CSS/JS
            if (Config::get('optimizer.frontend.minify', true)) {
                $results['minification'] = $this->minifyAssets();
            }

            // Compress images
            if (Config::get('optimizer.frontend.image_compression', true)) {
                $results['image_compression'] = $this->compressImages();
            }

            // Add lazy loading
            if (Config::get('optimizer.frontend.lazyload', true)) {
                $results['lazy_loading'] = $this->addLazyLoading();
            }

            // Setup compression
            $results['compression'] = $this->setupCompression();

            $this->log('Frontend optimization completed successfully', $results);

            return [
                'success' => true,
                'message' => 'Frontend optimization completed',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->log('Frontend optimization failed: ' . $e->getMessage(), [], 'error');

            return [
                'success' => false,
                'message' => 'Frontend optimization failed: ' . $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    /**
     * Minify CSS and JavaScript assets.
     *
     * @return array
     */
    protected function minifyAssets(): array
    {
        $minified = [
            'css' => [],
            'js' => [],
        ];

        try {
            $publicPath = public_path();

            // Find CSS files
            if (File::exists($publicPath . '/css')) {
                $cssFiles = File::allFiles($publicPath . '/css');
                foreach ($cssFiles as $file) {
                    if ($file->getExtension() === 'css' && !str_contains($file->getFilename(), '.min.')) {
                        $minified['css'][] = $file->getFilename();
                    }
                }
            }

            // Find JS files
            if (File::exists($publicPath . '/js')) {
                $jsFiles = File::allFiles($publicPath . '/js');
                foreach ($jsFiles as $file) {
                    if ($file->getExtension() === 'js' && !str_contains($file->getFilename(), '.min.')) {
                        $minified['js'][] = $file->getFilename();
                    }
                }
            }

            return [
                'status' => 'success',
                'css_files' => count($minified['css']),
                'js_files' => count($minified['js']),
                'recommendation' => 'Use Laravel Mix or Vite for automatic minification',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Compress images.
     *
     * @return array
     */
    protected function compressImages(): array
    {
        try {
            $publicPath = public_path();
            $images = [];
            $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            // Find image files
            if (File::exists($publicPath . '/images')) {
                $imageFiles = File::allFiles($publicPath . '/images');
                
                foreach ($imageFiles as $file) {
                    if (in_array(strtolower($file->getExtension()), $extensions)) {
                        $images[] = [
                            'file' => $file->getFilename(),
                            'size' => $this->formatBytes($file->getSize()),
                        ];
                    }
                }
            }

            return [
                'status' => 'success',
                'images_found' => count($images),
                'recommendation' => 'Use packages like spatie/laravel-image-optimizer for automatic compression',
                'quality' => Config::get('optimizer.frontend.image_quality', 85),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Add lazy loading to images.
     *
     * @return array
     */
    protected function addLazyLoading(): array
    {
        try {
            $viewPath = resource_path('views');
            $bladeFiles = [];

            if (File::exists($viewPath)) {
                $files = File::allFiles($viewPath);
                
                foreach ($files as $file) {
                    if ($file->getExtension() === 'php') {
                        $content = File::get($file->getPathname());
                        
                        // Check if file contains img tags without loading attribute
                        if (preg_match('/<img(?![^>]*loading=)/i', $content)) {
                            $bladeFiles[] = str_replace($viewPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                        }
                    }
                }
            }

            return [
                'status' => 'success',
                'files_needing_lazy_loading' => count($bladeFiles),
                'recommendation' => 'Add loading="lazy" attribute to <img> tags for better performance',
                'example' => '<img src="image.jpg" loading="lazy" alt="Description">',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Setup compression (Gzip/Brotli).
     *
     * @return array
     */
    protected function setupCompression(): array
    {
        $compression = Config::get('optimizer.frontend.compression', 'gzip');

        try {
            $htaccessPath = public_path('.htaccess');
            $hasCompression = false;

            if (File::exists($htaccessPath)) {
                $content = File::get($htaccessPath);
                $hasCompression = str_contains($content, 'mod_deflate') || str_contains($content, 'mod_gzip');
            }

            return [
                'type' => $compression,
                'enabled' => $hasCompression,
                'recommendation' => !$hasCompression ? 'Enable Gzip/Brotli compression in .htaccess or nginx config' : 'Compression is enabled',
                'example' => $this->getCompressionExample($compression),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get compression configuration example.
     *
     * @param string $type
     * @return string
     */
    protected function getCompressionExample(string $type): string
    {
        if ($type === 'brotli') {
            return 'AddOutputFilterByType BROTLI_COMPRESS text/html text/css application/javascript';
        }

        return 'AddOutputFilterByType DEFLATE text/html text/css application/javascript';
    }

    /**
     * Analyze frontend performance.
     *
     * @return array
     */
    public function analyzePerformance(): array
    {
        $analysis = [];

        try {
            // Check for Mix manifest
            $analysis['build_tool'] = $this->detectBuildTool();

            // Check asset sizes
            $analysis['asset_sizes'] = $this->analyzeAssetSizes();

            // Check for optimization opportunities
            $analysis['opportunities'] = $this->findOptimizationOpportunities();

            return $analysis;
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Detect build tool being used.
     *
     * @return array
     */
    protected function detectBuildTool(): array
    {
        $basePath = base_path();

        if (File::exists($basePath . '/vite.config.js')) {
            return ['tool' => 'Vite', 'status' => 'detected'];
        }

        if (File::exists($basePath . '/webpack.mix.js')) {
            return ['tool' => 'Laravel Mix', 'status' => 'detected'];
        }

        return ['tool' => 'None', 'status' => 'not_detected'];
    }

    /**
     * Analyze asset sizes.
     *
     * @return array
     */
    protected function analyzeAssetSizes(): array
    {
        $publicPath = public_path();
        $sizes = [
            'css' => 0,
            'js' => 0,
            'images' => 0,
        ];

        try {
            if (File::exists($publicPath . '/css')) {
                $sizes['css'] = $this->getDirectorySize($publicPath . '/css');
            }

            if (File::exists($publicPath . '/js')) {
                $sizes['js'] = $this->getDirectorySize($publicPath . '/js');
            }

            if (File::exists($publicPath . '/images')) {
                $sizes['images'] = $this->getDirectorySize($publicPath . '/images');
            }

            return [
                'css' => $this->formatBytes($sizes['css']),
                'js' => $this->formatBytes($sizes['js']),
                'images' => $this->formatBytes($sizes['images']),
                'total' => $this->formatBytes(array_sum($sizes)),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Find optimization opportunities.
     *
     * @return array
     */
    protected function findOptimizationOpportunities(): array
    {
        $opportunities = [];

        // Check for unminified assets
        $publicPath = public_path();
        
        if (File::exists($publicPath . '/css')) {
            $cssFiles = File::allFiles($publicPath . '/css');
            $unminified = array_filter($cssFiles, function ($file) {
                return !str_contains($file->getFilename(), '.min.') && $file->getExtension() === 'css';
            });

            if (count($unminified) > 0) {
                $opportunities[] = [
                    'type' => 'minification',
                    'target' => 'CSS',
                    'count' => count($unminified),
                    'recommendation' => 'Minify CSS files to reduce size',
                ];
            }
        }

        return $opportunities;
    }

    /**
     * Get directory size.
     *
     * @param string $path
     * @return int
     */
    protected function getDirectorySize(string $path): int
    {
        $size = 0;
        
        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
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
