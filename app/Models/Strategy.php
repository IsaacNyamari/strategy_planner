<?php

namespace App\Models;

use App\Enums\Horizon;
use Carbon\CarbonInterface;
use Database\Factories\StrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property Horizon $horizon
 * @property int $current_progress
 * @property CarbonInterface|null $target_date
 */
#[Fillable(['title', 'description', 'horizon', 'current_progress', 'target_date'])]
class Strategy extends Model
{
    /** @use HasFactory<StrategyFactory> */
    use HasFactory;

    /** Mirror the DB defaults so new instances are usable before a refresh. */
    protected $attributes = [
        'horizon' => 'long_term',
        'current_progress' => 0,
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'horizon' => Horizon::class,
            'current_progress' => 'integer',
        ];
    }

    /** @return HasMany<Objective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class);
    }

    /** @return HasManyThrough<Task, Objective, $this> */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Objective::class);
    }

    /**
     * Strategy progress is the average of its objectives' progress.
     */
    public function recalculateProgress(): void
    {
        $average = (float) ($this->objectives()->avg('current_progress') ?? 0);

        $this->forceFill(['current_progress' => (int) round($average)])->saveQuietly();
    }
}
