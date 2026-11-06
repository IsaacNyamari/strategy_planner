<?php

namespace App\Models;

use App\TasksEnum;
use Carbon\CarbonInterface;
use Database\Factories\ObjectiveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $strategy_id
 * @property int|null $owner_id
 * @property string $title
 * @property string|null $description
 * @property int $current_progress
 * @property TasksEnum $status
 * @property CarbonInterface|null $target_date
 * @property-read Strategy|null $strategy
 * @property-read Member|null $owner
 */
#[Fillable(['strategy_id', 'owner_id', 'title', 'description', 'current_progress', 'metric_unit', 'status', 'target_date'])]
class Objective extends Model
{
    /** @use HasFactory<ObjectiveFactory> */
    use HasFactory;

    /** Mirror the DB defaults so new instances are usable before a refresh. */
    protected $attributes = [
        'status' => 'Not_Started',
        'current_progress' => 0,
        'metric_unit' => '%',
    ];

    protected function casts(): array
    {
        return [
            'status' => TasksEnum::class,
            'target_date' => 'date',
            'current_progress' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Objective $objective) => $objective->strategy?->recalculateProgress());
    }

    /** @return BelongsTo<Strategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'owner_id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Recalculate progress from tasks (weighted by effort hours) and
     * roll the result up to the parent strategy.
     */
    public function recalculateProgress(): void
    {
        $tasks = $this->tasks()->get(['status', 'effort_hours']);

        if ($tasks->isEmpty()) {
            $progress = 0;
            $status = TasksEnum::NOT_STARTED;
        } else {
            $total = max(1, $tasks->sum(fn (Task $t) => max(1, $t->effort_hours)));
            $done = $tasks->where('status', TasksEnum::COMPLETED)->sum(fn (Task $t) => max(1, $t->effort_hours));
            $progress = (int) round($done / $total * 100);

            $status = match (true) {
                $progress === 100 => TasksEnum::COMPLETED,
                $tasks->contains('status', TasksEnum::BLOCKED) => TasksEnum::BLOCKED,
                $tasks->contains(fn (Task $t) => $t->status !== TasksEnum::NOT_STARTED) => TasksEnum::IN_PROGRESS,
                default => TasksEnum::NOT_STARTED,
            };
        }

        $this->forceFill(['current_progress' => $progress, 'status' => $status])->saveQuietly();

        $this->strategy?->recalculateProgress();
    }
}
