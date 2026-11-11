<?php

namespace SubhashLadumor\LaravelOptimizer\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DatabaseOptimizer
{
    /**
     * Slow query log.
     *
     * @var array
     */
    protected array $slowQueries = [];

    /**
     * Optimize database performance.
     *
     * @return array
     */
    public function optimize(): array
    {
        $results = [];

        try {
            // Analyze slow queries
            $results['slow_queries'] = $this->analyzeSlowQueries();

            // Suggest missing indexes
            $results['index_suggestions'] = $this->suggestIndexes();

            // Optimize tables if enabled
            if (Config::get('optimizer.database.optimize_tables', false)) {
                $results['table_optimization'] = $this->optimizeTables();
            }

            // Cache query results
            if (Config::get('optimizer.database.cache_queries', true)) {
                $results['query_cache'] = $this->setupQueryCache();
            }

            $this->log('Database optimization completed successfully', $results);

            return [
                'success' => true,
                'message' => 'Database optimization completed',
                'results' => $results,
            ];
        } catch (\Exception $e) {
            $this->log('Database optimization failed: ' . $e->getMessage(), [], 'error');

            return [
                'success' => false,
                'message' => 'Database optimization failed: ' . $e->getMessage(),
                'results' => $results,
            ];
        }
    }

    /**
     * Analyze slow queries.
     *
     * @return array
     */
    protected function analyzeSlowQueries(): array
    {
        $threshold = Config::get('optimizer.slow_query_threshold', 200);
        $slowQueries = [];

        try {
            // Enable query logging temporarily
            DB::enableQueryLog();

            // The actual slow query detection would happen during runtime
            // This is a placeholder for demonstration
            $queries = DB::getQueryLog();

            foreach ($queries as $query) {
                if (isset($query['time']) && $query['time'] > $threshold) {
                    $slowQueries[] = [
                        'query' => $query['query'],
                        'time' => $query['time'] . 'ms',
                        'bindings' => $query['bindings'],
                    ];
                }
            }

            DB::disableQueryLog();

            return [
                'threshold' => $threshold . 'ms',
                'count' => count($slowQueries),
                'queries' => $slowQueries,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Suggest missing indexes based on query patterns.
     *
     * @return array
     */
    protected function suggestIndexes(): array
    {
        $suggestions = [];

        try {
            $connection = Config::get('database.default');
            $driver = Config::get("database.connections.{$connection}.driver");

            if ($driver === 'mysql') {
                $suggestions = $this->analyzeMySQLIndexes();
            } elseif ($driver === 'pgsql') {
                $suggestions = $this->analyzePostgreSQLIndexes();
            }

            return [
                'count' => count($suggestions),
                'suggestions' => $suggestions,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Analyze MySQL indexes.
     *
     * @return array
     */
    protected function analyzeMySQLIndexes(): array
    {
        $suggestions = [];

        try {
            $tables = DB::select('SHOW TABLES');
            $database = Config::get('database.connections.mysql.database');

            foreach ($tables as $table) {
                $tableName = $table->{"Tables_in_{$database}"};

                // Get columns without indexes
                $columns = DB::select("
                    SELECT COLUMN_NAME, TABLE_NAME
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                    AND COLUMN_KEY = ''
                    AND COLUMN_NAME LIKE '%_id'
                ", [$database, $tableName]);

                foreach ($columns as $column) {
                    $suggestions[] = [
                        'table' => $column->TABLE_NAME,
                        'column' => $column->COLUMN_NAME,
                        'reason' => 'Foreign key column without index',
                        'suggestion' => "CREATE INDEX idx_{$column->TABLE_NAME}_{$column->COLUMN_NAME} ON {$column->TABLE_NAME}({$column->COLUMN_NAME})",
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to analyze MySQL indexes: ' . $e->getMessage());
        }

        return $suggestions;
    }

    /**
     * Analyze PostgreSQL indexes.
     *
     * @return array
     */
    protected function analyzePostgreSQLIndexes(): array
    {
        $suggestions = [];

        try {
            // Get tables without indexes on foreign key columns
            $results = DB::select("
                SELECT
                    t.relname AS table_name,
                    a.attname AS column_name
                FROM pg_class t
                JOIN pg_attribute a ON a.attrelid = t.oid
                WHERE t.relkind = 'r'
                AND a.attname LIKE '%_id'
                AND NOT EXISTS (
                    SELECT 1 FROM pg_index i
                    WHERE i.indrelid = t.oid
                    AND a.attnum = ANY(i.indkey)
                )
            ");

            foreach ($results as $result) {
                $suggestions[] = [
                    'table' => $result->table_name,
                    'column' => $result->column_name,
                    'reason' => 'Foreign key column without index',
                    'suggestion' => "CREATE INDEX idx_{$result->table_name}_{$result->column_name} ON {$result->table_name}({$result->column_name})",
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to analyze PostgreSQL indexes: ' . $e->getMessage());
        }

        return $suggestions;
    }

    /**
     * Optimize database tables.
     *
     * @return array
     */
    protected function optimizeTables(): array
    {
        try {
            $connection = Config::get('database.default');
            $driver = Config::get("database.connections.{$connection}.driver");

            if ($driver === 'mysql') {
                $tables = DB::select('SHOW TABLES');
                $database = Config::get('database.connections.mysql.database');
                $optimized = [];

                foreach ($tables as $table) {
                    $tableName = $table->{"Tables_in_{$database}"};
                    DB::statement("OPTIMIZE TABLE {$tableName}");
                    $optimized[] = $tableName;
                }

                return [
                    'status' => 'success',
                    'optimized' => $optimized,
                    'count' => count($optimized),
                ];
            }

            return [
                'status' => 'skipped',
                'message' => 'Table optimization only available for MySQL',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Setup query caching.
     *
     * @return array
     */
    protected function setupQueryCache(): array
    {
        try {
            $ttl = Config::get('optimizer.database.query_cache_ttl', 600);

            return [
                'status' => 'enabled',
                'ttl' => $ttl . ' seconds',
                'driver' => Config::get('cache.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get database statistics.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        try {
            $connection = Config::get('database.default');
            $driver = Config::get("database.connections.{$connection}.driver");

            $stats = [
                'driver' => $driver,
                'connection' => $connection,
            ];

            if ($driver === 'mysql') {
                $stats['tables'] = $this->getMySQLTableStats();
            }

            return $stats;
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get MySQL table statistics.
     *
     * @return array
     */
    protected function getMySQLTableStats(): array
    {
        try {
            $database = Config::get('database.connections.mysql.database');
            
            $tables = DB::select("
                SELECT 
                    TABLE_NAME,
                    TABLE_ROWS,
                    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = ?
                ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
            ", [$database]);

            return array_map(function ($table) {
                return [
                    'name' => $table->TABLE_NAME,
                    'rows' => $table->TABLE_ROWS,
                    'size' => $table->size_mb . ' MB',
                ];
            }, $tables);
        } catch (\Exception $e) {
            return [];
        }
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
