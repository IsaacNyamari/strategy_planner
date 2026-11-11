<?php

namespace SubhashLadumor\LaravelOptimizer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array analyze()
 * @method static array generateReport()
 *
 * @see \SubhashLadumor\LaravelOptimizer\Services\Analyzer
 */
class OptimizerPro extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'optimizer-pro';
    }
}
