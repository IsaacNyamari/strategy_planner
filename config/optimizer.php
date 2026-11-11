<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Slow Query Threshold
    |--------------------------------------------------------------------------
    |
    | Define the threshold (in milliseconds) for detecting slow queries.
    | Queries taking longer than this will be logged and analyzed.
    |
    */
    'slow_query_threshold' => env('OPTIMIZER_SLOW_QUERY_THRESHOLD', 200),

    /*
    |--------------------------------------------------------------------------
    | Cache Driver
    |--------------------------------------------------------------------------
    |
    | Specify the preferred cache driver for optimization operations.
    | Options: redis, memcached, file, database, array
    |
    */
    'cache_driver' => env('OPTIMIZER_CACHE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL Recommendations
    |--------------------------------------------------------------------------
    |
    | Default TTL (in seconds) for different types of cached data.
    |
    */
    'cache_ttl' => [
        'config' => 3600,      // 1 hour
        'routes' => 3600,      // 1 hour
        'views' => 3600,       // 1 hour
        'queries' => 600,      // 10 minutes
        'api_responses' => 300, // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup Settings
    |--------------------------------------------------------------------------
    |
    | Configure automatic cleanup settings for logs, cache, and temporary files.
    |
    */
    'cleanup' => [
        'log_days' => env('OPTIMIZER_LOG_DAYS', 7),
        'session_days' => env('OPTIMIZER_SESSION_DAYS', 30),
        'cache_days' => env('OPTIMIZER_CACHE_DAYS', 30),
        'optimize_schedule' => env('OPTIMIZER_SCHEDULE', 'weekly'),
        'clear_compiled' => true,
        'clear_temp_files' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Optimization
    |--------------------------------------------------------------------------
    |
    | Enable or disable frontend optimization features.
    |
    */
    'frontend' => [
        'minify' => env('OPTIMIZER_MINIFY', true),
        'image_compression' => env('OPTIMIZER_IMAGE_COMPRESSION', true),
        'lazyload' => env('OPTIMIZER_LAZYLOAD', true),
        'compression' => env('OPTIMIZER_COMPRESSION', 'gzip'), // gzip, brotli, none
        'image_quality' => env('OPTIMIZER_IMAGE_QUALITY', 85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Optimization
    |--------------------------------------------------------------------------
    |
    | Configure database optimization features.
    |
    */
    'database' => [
        'analyze_indexes' => true,
        'cache_queries' => true,
        'log_slow_queries' => true,
        'optimize_tables' => env('OPTIMIZER_DB_OPTIMIZE_TABLES', false),
        'query_cache_ttl' => 600, // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Optimization
    |--------------------------------------------------------------------------
    |
    | Configure queue optimization settings.
    |
    */
    'queue' => [
        'retry_limit' => 3,
        'batch_size' => 100,
        'memory_limit' => '512M',
        'timeout' => 60,
        'analyze_failed_jobs' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Analyzer
    |--------------------------------------------------------------------------
    |
    | Configure performance analysis settings.
    |
    */
    'analyzer' => [
        'enabled' => true,
        'log_slow_routes' => true,
        'route_threshold' => 1000, // ms
        'track_memory' => true,
        'track_queries' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure optimizer logging settings.
    |
    */
    'logging' => [
        'enabled' => true,
        'channel' => env('OPTIMIZER_LOG_CHANNEL', 'single'),
        'log_file' => 'optimizer.log',
        'level' => env('OPTIMIZER_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    |
    | Paths to exclude from optimization (relative to public directory).
    |
    */
    'excluded_paths' => [
        'vendor',
        'node_modules',
        '.git',
    ],

];
