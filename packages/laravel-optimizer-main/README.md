# 🚀 Laravel Optimizer

[![Latest Version on Packagist](https://img.shields.io/packagist/v/subhashladumor/laravel-optimizer.svg?style=flat-square)](https://packagist.org/packages/subhashladumor/laravel-optimizer)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-red.svg?style=flat-square)](https://laravel.com)
[![PHP Version](https://img.shields.io/packagist/php-v/subhashladumor/laravel-multicloud.svg?style=flat-square)](https://packagist.org/packages/subhashladumor/laravel-multicloud)
[![License](https://img.shields.io/packagist/l/subhashladumor/laravel-optimizer.svg?style=flat-square)](https://packagist.org/packages/subhashladumor/laravel-optimizer)

**All-in-one Laravel optimization suite for speed, caching, database, frontend, and cleanup.**

Laravel Optimizer Pro is a comprehensive performance optimization package that improves your Laravel application across multiple dimensions: backend caching, database queries, frontend assets, queue management, and automated cleanup.

---

## ✨ Features

### 🔧 Backend Optimization
- Optimize config, route, event, and view caching
- Auto-detect environment and suggest `.env` improvements
- Memory usage analyzer and recommendations

### 🗄️ Database Optimization
- Detect and log slow queries (configurable threshold)
- Suggest missing indexes based on query patterns
- Cache repeated query results intelligently
- Optimize database tables (MySQL)

### 💾 Cache Optimization
- Smart Redis/Memcached detection
- Auto-warm cache on deploy or config clear
- Cache TTL recommendations
- Cache hit rate analysis

### 🎨 Frontend Optimization
- Auto-detect unminified CSS/JS files
- Image compression recommendations
- Add lazy loading suggestions for `<img>` tags
- Gzip/Brotli compression detection

### 📬 Queue Optimization
- Tune retry counts, batch size, and memory limits
- Detect failed jobs and suggest retry strategies
- Queue configuration analysis

### 🧹 Cleanup & Maintenance
- Clear old logs, sessions, caches, temp files
- Configurable retention periods
- Safe cleanup with confirmation prompts

### 📊 Performance Analyzer
- Comprehensive performance analysis
- TTFB, route speed, query count tracking
- Cache hit rate monitoring
- Performance score and grading system

---

## 📋 Requirements

- PHP 8.0 or higher
- Laravel 9.x, 10.x, or 11.x

---

## 📦 Installation

Install the package via Composer:

```bash
composer require subhashladumor/laravel-optimizer
```

### Publish Configuration

Publish the configuration file to customize settings:

```bash
php artisan vendor:publish --tag=optimizer-config
```

This will create `config/optimizer.php` in your application.

---

## 🎯 Usage

### Available Commands

| Command | Description |
|---------|-------------|
| `php artisan optimize:all` | Run all optimizations |
| `php artisan optimize:analyze` | Analyze performance |
| `php artisan optimize:db` | Optimize and analyze database |
| `php artisan optimize:cache` | Optimize cache & sessions |
| `php artisan optimize:frontend` | Optimize frontend assets |
| `php artisan optimize:cleanup` | Cleanup project logs & temp files |

### Command Examples

#### 1. Run All Optimizations

```bash
php artisan optimize:all
```

**Output:**
```
🚀 Starting Laravel Optimizer Pro...

⚙️  Optimizing Backend...
   ✓ Backend optimization completed successfully

🗄️  Optimizing Database...
   ✓ Database optimization completed successfully

💾 Optimizing Cache...
   ✓ Cache optimization completed successfully

🎨 Optimizing Frontend...
   ✓ Frontend optimization completed successfully

📬 Optimizing Queue...
   ✓ Queue optimization completed successfully

🧹 Cleaning up...
   ✓ Cleanup optimization completed successfully

✅ All optimizations completed in 2.45s
```

#### 2. Analyze Application Performance

```bash
php artisan optimize:analyze
```

Generate detailed report:
```bash
php artisan optimize:analyze --report
```

#### 3. Database Optimization

```bash
php artisan optimize:db
```

Show database statistics:
```bash
php artisan optimize:db --stats
```

#### 4. Cache Optimization

```bash
php artisan optimize:cache
```

Analyze cache usage:
```bash
php artisan optimize:cache --analyze
```

#### 5. Frontend Optimization

```bash
php artisan optimize:frontend
```

Analyze frontend performance:
```bash
php artisan optimize:frontend --analyze
```

#### 6. Cleanup

```bash
php artisan optimize:cleanup
```

Show cleanup statistics:
```bash
php artisan optimize:cleanup --stats
```

Force cleanup without confirmation:
```bash
php artisan optimize:cleanup --force
```

---

## ⚙️ Configuration

The `config/optimizer.php` file contains all configuration options:

```php
return [
    // Slow query threshold (in milliseconds)
    'slow_query_threshold' => 200,

    // Preferred cache driver
    'cache_driver' => 'redis',

    // Cache TTL settings
    'cache_ttl' => [
        'config' => 3600,
        'routes' => 3600,
        'views' => 3600,
        'queries' => 600,
    ],

    // Cleanup settings
    'cleanup' => [
        'log_days' => 7,
        'session_days' => 30,
        'optimize_schedule' => 'weekly',
    ],

    // Frontend optimization
    'frontend' => [
        'minify' => true,
        'image_compression' => true,
        'lazyload' => true,
        'compression' => 'gzip',
    ],

    // Database optimization
    'database' => [
        'analyze_indexes' => true,
        'cache_queries' => true,
        'log_slow_queries' => true,
    ],

    // Queue optimization
    'queue' => [
        'retry_limit' => 3,
        'batch_size' => 100,
        'memory_limit' => '512M',
        'timeout' => 60,
    ],
];
```

---

## 🎭 Using the Facade

You can use the `OptimizerPro` facade to access analyzer functionality:

```php
use SubhashLadumor\LaravelOptimizer\Facades\OptimizerPro;

// Analyze performance
$analysis = OptimizerPro::analyze();

// Generate detailed report
$report = OptimizerPro::generateReport();
```

---

## 🛠️ Helper Functions

The package includes several helper functions:

```php
// Format bytes
optimizer_format_bytes(1024); // "1 KB"

// Get memory usage
optimizer_memory_usage(); // "128 MB"

// Get peak memory
optimizer_peak_memory(); // "256 MB"

// Get execution time
optimizer_execution_time(); // 150.25 (ms)

// Cache with recommended TTL
optimizer_cache_remember('key', 'config', function() {
    return expensive_operation();
});

// Log optimization activity
optimizer_log('Custom optimization completed', ['key' => 'value']);

// Check if production
optimizer_is_production(); // true/false

// Should cache?
optimizer_should_cache(); // true/false

// Get config value
optimizer_get_config('slow_query_threshold', 200);

// Clear all cache
optimizer_clear_all_cache();

// Warm cache
optimizer_warm_cache();
```

---

## 📊 Performance Analysis

The analyzer provides comprehensive performance metrics:

- **Performance Metrics**: Request time, memory usage, peak memory
- **Route Analysis**: Total routes, middleware usage
- **Database Analysis**: Connection time, query count, slow queries
- **Cache Analysis**: Driver type, read/write performance, hit rate
- **System Information**: PHP version, Laravel version, environment

### Performance Grading

The analyzer assigns a performance score (0-100) and grade (A-F):

- **A (90-100)**: Excellent performance
- **B (80-89)**: Good performance
- **C (70-79)**: Average performance
- **D (60-69)**: Below average
- **F (0-59)**: Poor performance

---

## 🔄 Scheduled Optimization

Add to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Run cleanup weekly
    $schedule->command('optimize:cleanup --force')
        ->weekly()
        ->sundays()
        ->at('01:00');

    // Analyze performance daily
    $schedule->command('optimize:analyze')
        ->daily()
        ->at('02:00');
}
```

---

## 🎯 Best Practices

### Production Environment

1. **Always run optimizations** after deployment:
   ```bash
   php artisan optimize:all
   ```

2. **Disable debug mode**:
   ```env
   APP_DEBUG=false
   ```

3. **Use Redis for caching and sessions**:
   ```env
   CACHE_DRIVER=redis
   SESSION_DRIVER=redis
   ```

4. **Enable queue workers**:
   ```env
   QUEUE_CONNECTION=redis
   ```

### Development Environment

1. **Clear cache frequently** during development:
   ```bash
   php artisan optimize:cleanup --force
   ```

2. **Analyze performance** before going to production:
   ```bash
   php artisan optimize:analyze --report
   ```

---

## 🧪 Testing

The package is designed to be safe and non-destructive. Always test in a staging environment first:

```bash
# Test analysis (read-only)
php artisan optimize:analyze

# Test with skip options
php artisan optimize:all --skip-cleanup --skip-database
```

---

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

---

## 📝 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

## 👨‍💻 Author

**Subhash Ladumor**

- Email: subhash@example.com
- GitHub: [@subhashladumor](https://github.com/subhashladumor)

---

## 🙏 Acknowledgments

- Inspired by Laravel's built-in optimization commands
- Built with ❤️ for the Laravel community

---

## 📚 Additional Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Performance Optimization Guide](https://laravel.com/docs/deployment#optimization)
- [Database Query Optimization](https://laravel.com/docs/queries)

<div align="center">

**Made with ❤️ by [Subhash Ladumor](https://github.com/subhashladumor)**

[⭐ Star this repo](https://github.com/subhashladumor/laravel-optimizer) | [🐛 Report Bug](https://github.com/subhashladumor/laravel-optimizer/issues) | [💡 Request Feature](https://github.com/subhashladumor/laravel-optimizer/issues)

</div>
