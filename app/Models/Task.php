<?php

namespace App\Models;

use App\EisenhowerQuadrant;
use App\TasksEnum;
use Carbon\CarbonInterface;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property int $id
 * @property int $objective_id
 * @property int|null $assignee_id
 * @property string $title
 * @property string|null $description
 * @property TasksEnum $status
 * @property EisenhowerQuadrant $eisenhower_quadrant
 * @property int $effort_hours
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $completed_at
 * @property-read Objective|null $objective
 * @property-read Member|null $assignee
 */
#[Fillable(['objective_id', 'assignee_id', 'title', 'description', 'status', 'eisenhower_quadrant', 'effort_hours', 'due_at', 'completed_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'eisenhower_quadrant' => EisenhowerQuadrant::class,
            'status' => TasksEnum::class,
            'effort_hours' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === TasksEnum::COMPLETED ? now() : null;
            }
        });

        static::saved(function (Task $task) {
            $task->objective?->recalculateProgress();

            // Moved to a different objective: refresh the old one too.
            if ($task->wasChanged('objective_id') && $original = $task->getOriginal('objective_id')) {
                Objective::query()->whereKey($original)->first()?->recalculateProgress();
            }
        });

        static::deleted(fn (Task $task) => $task->objective?->recalculateProgress());
    }

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assignee_id');
    }

    /** @return HasOneThrough<Strategy, Objective, $this> */
    public function strategy(): HasOneThrough
    {
        return $this->hasOneThrough(Strategy::class, Objective::class, 'id', 'id', 'objective_id', 'strategy_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->status !== TasksEnum::COMPLETED
            && $this->due_at->isPast();
    }

    /** @param Builder<Task> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', TasksEnum::COMPLETED->value);
    }
}
