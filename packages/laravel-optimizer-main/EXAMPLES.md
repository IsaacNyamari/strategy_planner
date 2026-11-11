# Laravel Optimizer Pro - Examples

## Basic Usage Examples

### 1. Run All Optimizations
```bash
php artisan optimize:all
```

### 2. Analyze Performance
```bash
# Basic analysis
php artisan optimize:analyze

# Detailed report with recommendations
php artisan optimize:analyze --report
```

### 3. Database Optimization
```bash
# Optimize database
php artisan optimize:db

# Show database statistics
php artisan optimize:db --stats
```

### 4. Cache Optimization
```bash
# Optimize cache
php artisan optimize:cache

# Analyze cache usage
php artisan optimize:cache --analyze
```

### 5. Frontend Optimization
```bash
# Optimize frontend
php artisan optimize:frontend

# Analyze frontend performance
php artisan optimize:frontend --analyze
```

### 6. Cleanup
```bash
# Interactive cleanup
php artisan optimize:cleanup

# Show statistics only
php artisan optimize:cleanup --stats

# Force cleanup (no confirmation)
php artisan optimize:cleanup --force
```

---

## Programmatic Usage

### Using Services Directly

```php
use SubhashLadumor\LaravelOptimizer\Services\BackendOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\DatabaseOptimizer;
use SubhashLadumor\LaravelOptimizer\Services\Analyzer;

// Backend optimization
$backendOptimizer = app(BackendOptimizer::class);
$result = $backendOptimizer->optimize();

// Database optimization
$dbOptimizer = app(DatabaseOptimizer::class);
$result = $dbOptimizer->optimize();

// Performance analysis
$analyzer = app(Analyzer::class);
$report = $analyzer->generateReport();

echo "Performance Score: {$report['score']}/100\n";
echo "Grade: {$report['grade']}\n";
```

### Using Facade

```php
use SubhashLadumor\LaravelOptimizer\Facades\OptimizerPro;

// Analyze performance
$analysis = OptimizerPro::analyze();

// Generate report
$report = OptimizerPro::generateReport();

if ($report['score'] < 70) {
    // Send alert to admin
    Log::warning('Low performance score', $report);
}
```

### Using Helper Functions

```php
// Format bytes
echo optimizer_format_bytes(1048576); // "1 MB"

// Get memory usage
echo "Memory: " . optimizer_memory_usage(); // "128 MB"

// Cache with recommended TTL
$config = optimizer_cache_remember('app.config', 'config', function() {
    return config('app');
});

// Log optimization
optimizer_log('Custom optimization completed', [
    'duration' => 2.5,
    'items_processed' => 100
]);

// Check environment
if (optimizer_is_production()) {
    // Production-specific logic
}

// Clear all cache
$result = optimizer_clear_all_cache();
if ($result['success']) {
    echo "Cleared: " . implode(', ', $result['cleared']);
}
```

---

## Advanced Examples

### 1. Custom Optimization Script

```php
<?php

use SubhashLadumor\LaravelOptimizer\Services\Analyzer;
use SubhashLadumor\LaravelOptimizer\Services\CleanupService;

$analyzer = app(Analyzer::class);
$cleanup = app(CleanupService::class);

// Analyze first
$report = $analyzer->generateReport();

echo "Performance Score: {$report['score']}\n";

// If score is low, run cleanup and re-analyze
if ($report['score'] < 70) {
    echo "Running cleanup...\n";
    $cleanup->cleanup();
    
    // Re-analyze
    $newReport = $analyzer->generateReport();
    echo "New Score: {$newReport['score']}\n";
}

// Display recommendations
foreach ($report['recommendations'] as $rec) {
    echo "- [{$rec['priority']}] {$rec['message']}\n";
}
```

### 2. Scheduled Optimization Task

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Daily performance check
    $schedule->call(function () {
        $analyzer = app(\SubhashLadumor\LaravelOptimizer\Services\Analyzer::class);
        $report = $analyzer->generateReport();
        
        if ($report['score'] < 60) {
            Mail::to('admin@example.com')->send(
                new PerformanceAlert($report)
            );
        }
    })->daily();
    
    // Weekly cleanup
    $schedule->command('optimize:cleanup --force')
        ->weekly()
        ->sundays()
        ->at('01:00');
    
    // Weekly full optimization
    $schedule->command('optimize:all')
        ->weekly()
        ->sundays()
        ->at('02:00');
}
```

### 3. Deployment Hook

Create a deployment script:

```bash
#!/bin/bash

echo "🚀 Deploying application..."

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader

# Run migrations
php artisan migrate --force

# Run all optimizations
php artisan optimize:all

# Restart queue workers
php artisan queue:restart

echo "✅ Deployment complete!"
```

### 4. Health Check Endpoint

Create a health check controller:

```php
<?php

namespace App\Http\Controllers;

use SubhashLadumor\LaravelOptimizer\Services\Analyzer;

class HealthCheckController extends Controller
{
    public function index(Analyzer $analyzer)
    {
        $report = $analyzer->generateReport();
        
        $health = [
            'status' => $report['score'] >= 70 ? 'healthy' : 'degraded',
            'score' => $report['score'],
            'grade' => $report['grade'],
            'checks' => [
                'database' => isset($report['metrics']['database']),
                'cache' => isset($report['metrics']['cache']),
                'memory' => true,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
        
        return response()->json($health, 
            $health['status'] === 'healthy' ? 200 : 503
        );
    }
}
```

---

## Integration Examples

### 1. With Laravel Telescope

```php
use Laravel\Telescope\Telescope;
use SubhashLadumor\LaravelOptimizer\Services\Analyzer;

Telescope::filter(function ($entry) {
    $analyzer = app(Analyzer::class);
    $analysis = $analyzer->analyze();
    
    // Only record when performance is good
    return $analysis['results']['performance']['memory_usage'] < 100;
});
```

### 2. With Laravel Debugbar

```php
use Debugbar;
use SubhashLadumor\LaravelOptimizer\Facades\OptimizerPro;

if (app()->environment('local')) {
    $report = OptimizerPro::generateReport();
    Debugbar::info("Performance Score: {$report['score']}");
}
```

### 3. Custom Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use SubhashLadumor\LaravelOptimizer\Services\Analyzer;

class PerformanceMonitor
{
    public function handle($request, Closure $next)
    {
        $start = microtime(true);
        
        $response = $next($request);
        
        $duration = microtime(true) - $start;
        
        if ($duration > 1) {
            optimizer_log('Slow request detected', [
                'url' => $request->fullUrl(),
                'duration' => $duration,
                'memory' => optimizer_memory_usage(),
            ]);
        }
        
        return $response;
    }
}
```

---

## Testing

```bash
# Test individual commands
php artisan optimize:analyze
php artisan optimize:db --stats
php artisan optimize:cache --analyze
php artisan optimize:frontend --analyze
php artisan optimize:cleanup --stats

# Test with skip options
php artisan optimize:all --skip-cleanup
php artisan optimize:all --skip-database --skip-frontend

# Test in dry-run mode (analysis only)
php artisan optimize:analyze --report
```

---

## Troubleshooting

### Issue: Permission Denied

```bash
# Fix storage permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

### Issue: Cache Not Clearing

```bash
# Manual cache clear
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Issue: Out of Memory

Increase memory limit in `config/optimizer.php`:

```php
'queue' => [
    'memory_limit' => '1024M', // Increase from 512M
],
```

---

For more examples and documentation, visit the [GitHub repository](https://github.com/subhashladumor/laravel-optimizer).
