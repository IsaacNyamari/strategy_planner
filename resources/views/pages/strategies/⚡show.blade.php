<?php

use App\Livewire\Guard;
use App\Ai\GeminiException;
use App\Ai\WorshipAssistant;
use App\EisenhowerQuadrant;
use App\Enums\Horizon;
use App\Models\Member;
use App\Models\Objective;
use App\Models\Strategy;
use App\Models\Task;
use App\TasksEnum;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new #[Guard('strategies.manage')] class extends Component {
    public Strategy $strategy;

    // Strategy edit form
    public string $title = '';
    public string $description = '';
    public ?string $target_date = null;
    public string $horizon = 'long_term';

    // AI breakdown
    public array $aiObjectives = [];
    public ?string $aiError = null;

    // Objective form
    public ?int $editingObjectiveId = null;
    public string $objectiveTitle = '';
    public string $objectiveDescription = '';
    public ?string $objectiveTargetDate = null;
    public ?int $objectiveOwnerId = null;

    // Task form
    public ?int $taskObjectiveId = null;
    public string $taskTitle = '';
    public string $taskQuadrant = 'Schedule';
    public int $taskEffort = 1;
    public ?string $taskDueAt = null;
    public ?int $taskAssigneeId = null;

    public function mount(Strategy $strategy): void
    {
        $this->strategy = $strategy;
    }

    public function rendering($view): void
    {
        $view->title($this->strategy->title);
    }

    #[Computed]
    public function objectives()
    {
        return $this->strategy->objectives()
            ->with(['owner:id,name', 'tasks.assignee:id,name', 'tasks' => fn ($q) => $q->orderByRaw("status = 'Completed'")->orderByRaw('due_at is null')->orderBy('due_at')])
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function members()
    {
        return Member::active()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function taskStats(): array
    {
        $tasks = $this->objectives->flatMap->tasks;

        return [
            'total' => $tasks->count(),
            'done' => $tasks->where('status', TasksEnum::COMPLETED)->count(),
            'overdue' => $tasks->filter->isOverdue()->count(),
        ];
    }

    // --- Strategy -----------------------------------------------------

    public function editStrategy(): void
    {
        $this->title = $this->strategy->title;
        $this->description = (string) $this->strategy->description;
        $this->target_date = $this->strategy->target_date?->format('Y-m-d');
        $this->horizon = $this->strategy->horizon->value;
        $this->resetValidation();
        Flux::modal('edit-strategy')->show();
    }

    public function updateStrategy(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_date' => ['nullable', 'date'],
            'horizon' => ['required', Rule::enum(Horizon::class)],
        ]);
        $data['target_date'] = $data['target_date'] ?: null;
        $this->strategy->update($data);

        Flux::modal('edit-strategy')->close();
        Flux::toast(variant: 'success', text: 'Strategy updated.');
    }

    public function deleteStrategy(): void
    {
        $this->strategy->delete();
        Flux::toast(variant: 'success', text: 'Strategy deleted.');
        $this->redirectRoute('strategies', navigate: true);
    }

    // --- Objectives ---------------------------------------------------

    public function newObjective(): void
    {
        $this->reset('editingObjectiveId', 'objectiveTitle', 'objectiveDescription', 'objectiveTargetDate', 'objectiveOwnerId');
        $this->resetValidation();
        Flux::modal('objective-form')->show();
    }

    public function editObjective(int $id): void
    {
        $objective = $this->strategy->objectives()->findOrFail($id);

        $this->editingObjectiveId = $objective->id;
        $this->objectiveTitle = $objective->title;
        $this->objectiveDescription = (string) $objective->description;
        $this->objectiveTargetDate = $objective->target_date?->format('Y-m-d');
        $this->objectiveOwnerId = $objective->owner_id;
        $this->resetValidation();
        Flux::modal('objective-form')->show();
    }

    public function saveObjective(): void
    {
        $this->validate([
            'objectiveTitle' => ['required', 'string', 'max:255'],
            'objectiveDescription' => ['nullable', 'string', 'max:2000'],
            'objectiveTargetDate' => ['nullable', 'date'],
            'objectiveOwnerId' => ['nullable', 'exists:members,id'],
        ], attributes: ['objectiveTitle' => 'title', 'objectiveTargetDate' => 'target date']);

        $data = [
            'title' => $this->objectiveTitle,
            'description' => $this->objectiveDescription,
            'target_date' => $this->objectiveTargetDate ?: null,
            'owner_id' => $this->objectiveOwnerId ?: null,
        ];

        if ($this->editingObjectiveId) {
            $this->strategy->objectives()->findOrFail($this->editingObjectiveId)->update($data);
        } else {
            $this->strategy->objectives()->create($data);
            $this->strategy->recalculateProgress();
        }

        Flux::modal('objective-form')->close();
        Flux::toast(variant: 'success', text: $this->editingObjectiveId ? 'Objective updated.' : 'Objective added.');
        $this->refreshData();
    }

    public function deleteObjective(int $id): void
    {
        $this->strategy->objectives()->findOrFail($id)->delete();
        Flux::toast(text: 'Objective deleted.');
        $this->refreshData();
    }

    // --- Tasks --------------------------------------------------------

    public function newTask(int $objectiveId): void
    {
        $this->reset('taskTitle', 'taskQuadrant', 'taskEffort', 'taskDueAt', 'taskAssigneeId');
        $this->taskObjectiveId = $objectiveId;
        $this->resetValidation();
        Flux::modal('task-form')->show();
    }

    public function saveTask(): void
    {
        $this->validate([
            'taskObjectiveId' => ['required', Rule::exists('objectives', 'id')->where('strategy_id', $this->strategy->id)],
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskQuadrant' => ['required', Rule::enum(EisenhowerQuadrant::class)],
            'taskEffort' => ['required', 'integer', 'min:1', 'max:999'],
            'taskDueAt' => ['nullable', 'date'],
            'taskAssigneeId' => ['nullable', 'exists:members,id'],
        ], attributes: ['taskTitle' => 'title', 'taskEffort' => 'effort', 'taskDueAt' => 'due date']);

        Task::create([
            'objective_id' => $this->taskObjectiveId,
            'title' => $this->taskTitle,
            'eisenhower_quadrant' => $this->taskQuadrant,
            'effort_hours' => $this->taskEffort,
            'due_at' => $this->taskDueAt ?: null,
            'assignee_id' => $this->taskAssigneeId ?: null,
            'status' => TasksEnum::NOT_STARTED,
        ]);

        Flux::modal('task-form')->close();
        Flux::toast(variant: 'success', text: 'Task added.');
        $this->refreshData();
    }

    public function setTaskStatus(int $taskId, string $status): void
    {
        $task = $this->strategy->tasks()->findOrFail($taskId);
        $task->update(['status' => TasksEnum::from($status)]);
        $this->refreshData();
    }

    public function assignTask(int $taskId, ?string $memberId): void
    {
        $this->strategy->tasks()->findOrFail($taskId)->update(['assignee_id' => $memberId ?: null]);
        $this->refreshData();
    }

    // --- AI breakdown -------------------------------------------------

    public function generateBreakdown(): void
    {
        $this->aiError = null;
        $this->aiObjectives = [];

        try {
            $this->aiObjectives = collect(app(WorshipAssistant::class)->strategyBreakdown($this->strategy))
                ->map(fn ($o) => [...$o, 'selected' => true])->all();
        } catch (GeminiException $e) {
            $this->aiError = $e->getMessage();
        }
    }

    public function applyBreakdown(): void
    {
        $chosen = collect($this->aiObjectives)->where('selected', true);

        foreach ($chosen as $o) {
            if (blank($o['title'] ?? null)) {
                continue;
            }

            $objective = $this->strategy->objectives()->create([
                'title' => mb_substr($o['title'], 0, 255),
                'description' => $o['description'] ?? null,
            ]);

            foreach ($o['tasks'] ?? [] as $t) {
                if (blank($t['title'] ?? null)) {
                    continue;
                }

                Task::create([
                    'objective_id' => $objective->id,
                    'title' => mb_substr($t['title'], 0, 255),
                    'eisenhower_quadrant' => EisenhowerQuadrant::tryFrom($t['quadrant'] ?? '') ?? EisenhowerQuadrant::SCHEDULE,
                    'effort_hours' => max(1, (int) ($t['effort_hours'] ?? 1)),
                    'status' => TasksEnum::NOT_STARTED,
                ]);
            }
        }

        $this->aiObjectives = [];
        Flux::modal('ai-breakdown')->close();
        Flux::toast(variant: 'success', text: $chosen->count().' objectives added. Assign owners and tasks to team members.');
        $this->refreshData();
    }

    public function deleteTask(int $taskId): void
    {
        $this->strategy->tasks()->findOrFail($taskId)->delete();
        Flux::toast(text: 'Task deleted.');
        $this->refreshData();
    }

    protected function refreshData(): void
    {
        $this->strategy->refresh();
        unset($this->objectives, $this->taskStats);
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('strategies')" wire:navigate>Strategies</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ Str::limit($strategy->title, 40) }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl" level="1">{{ $strategy->title }}</flux:heading>
                    <flux:badge :color="$strategy->horizon->color()">{{ $strategy->horizon->label() }}</flux:badge>
                </div>
                @if ($strategy->description)
                    <flux:text class="mt-2">{{ $strategy->description }}</flux:text>
                @endif
            </div>

            <div class="flex gap-2">
                <flux:modal.trigger name="ai-breakdown">
                    <flux:button icon="sparkles">AI breakdown</flux:button>
                </flux:modal.trigger>
                <flux:button icon="pencil-square" wire:click="editStrategy">Edit</flux:button>
                <flux:dropdown align="end">
                    <flux:button icon="ellipsis-horizontal" aria-label="More actions" />
                    <flux:menu>
                        <flux:modal.trigger name="delete-strategy">
                            <flux:menu.item icon="trash" variant="danger">Delete strategy</flux:menu.item>
                        </flux:modal.trigger>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card class="sm:col-span-2 space-y-2">
            <div class="flex items-baseline justify-between">
                <flux:text>Overall progress</flux:text>
                <flux:heading size="xl">{{ $strategy->current_progress }}%</flux:heading>
            </div>
            <flux:progress :value="$strategy->current_progress" :color="getColor($strategy->current_progress)" />
            <flux:text size="sm">Average of {{ $this->objectives->count() }} {{ Str::plural('objective', $this->objectives->count()) }}</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Tasks done</flux:text>
            <flux:heading size="xl">{{ $this->taskStats['done'] }} <span class="text-base font-normal text-zinc-400">/ {{ $this->taskStats['total'] }}</span></flux:heading>
            @if ($this->taskStats['overdue'])
                <flux:text size="sm" class="text-red-600! dark:text-red-400!">{{ $this->taskStats['overdue'] }} overdue</flux:text>
            @endif
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text>Target date</flux:text>
            <flux:heading size="xl">{{ $strategy->target_date?->format('M j, Y') ?? '—' }}</flux:heading>
            @if ($strategy->target_date)
                <flux:text size="sm" @class(['text-red-600! dark:text-red-400!' => $strategy->target_date->isPast() && $strategy->current_progress < 100])>
                    {{ $strategy->target_date->diffForHumans() }}
                </flux:text>
            @endif
        </flux:card>
    </div>

    {{-- Objectives --}}
    <div class="flex items-center justify-between">
        <flux:heading size="lg">Objectives</flux:heading>
        <flux:button size="sm" icon="plus" wire:click="newObjective">Add objective</flux:button>
    </div>

    @forelse ($this->objectives as $objective)
        <flux:card wire:key="objective-{{ $objective->id }}" class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">{{ $objective->title }}</flux:heading>
                        <flux:badge size="sm" :color="$objective->status->color()">{{ $objective->status->label() }}</flux:badge>
                    </div>
                    @if ($objective->description)
                        <flux:text size="sm" class="mt-1">{{ $objective->description }}</flux:text>
                    @endif
                    <flux:text size="sm" class="mt-1 flex items-center gap-1"><flux:icon.user variant="micro" /> Owner: {{ $objective->owner?->name ?? 'unassigned' }}</flux:text>
                </div>
                <div class="flex items-center gap-1">
                    @if ($objective->target_date)
                        <flux:text size="sm" class="me-2 flex items-center gap-1"><flux:icon.calendar variant="micro" /> {{ $objective->target_date->format('M j, Y') }}</flux:text>
                    @endif
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="newTask({{ $objective->id }})">Task</flux:button>
                    <flux:dropdown align="end">
                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Objective actions" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="editObjective({{ $objective->id }})">Edit</flux:menu.item>
                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteObjective({{ $objective->id }})"
                                wire:confirm="Delete this objective and all of its tasks?">Delete</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <flux:progress :value="$objective->current_progress" :color="getColor($objective->current_progress)" class="flex-1" />
                <span class="w-10 text-end text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $objective->current_progress }}%</span>
            </div>

            @if ($objective->tasks->isNotEmpty())
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Task</flux:table.column>
                        <flux:table.column>Assignee</flux:table.column>
                        <flux:table.column>Priority</flux:table.column>
                        <flux:table.column>Due</flux:table.column>
                        <flux:table.column align="end">Effort</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($objective->tasks as $task)
                            <flux:table.row wire:key="task-{{ $task->id }}">
                                <flux:table.cell class="max-w-xs truncate" variant="strong">
                                    <span @class(['line-through text-zinc-400!' => $task->status === \App\TasksEnum::COMPLETED])>{{ $task->title }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <select wire:change="assignTask({{ $task->id }}, $event.target.value)" aria-label="Assignee"
                                        class="w-36 rounded-md border border-zinc-200 bg-transparent px-2 py-1 text-sm dark:border-zinc-600">
                                        <option value="">Unassigned</option>
                                        @foreach ($this->members as $m)
                                            <option value="{{ $m->id }}" @selected($task->assignee_id === $m->id)>{{ $m->name }}</option>
                                        @endforeach
                                    </select>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$task->eisenhower_quadrant->color()" inset="top bottom">{{ $task->eisenhower_quadrant->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell @class(['text-red-600! dark:text-red-400!' => $task->isOverdue()])>
                                    {{ $task->due_at?->format('M j') ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $task->effort_hours }}h</flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown>
                                        <flux:badge as="button" size="sm" :color="$task->status->color()" :icon="$task->status->icon()" icon:trailing="chevron-down" inset="top bottom">
                                            {{ $task->status->label() }}
                                        </flux:badge>
                                        <flux:menu>
                                            @foreach (\App\TasksEnum::cases() as $status)
                                                <flux:menu.item :icon="$status->icon()" wire:click="setTaskStatus({{ $task->id }}, '{{ $status->value }}')">{{ $status->label() }}</flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" aria-label="Delete task"
                                        wire:click="deleteTask({{ $task->id }})" wire:confirm="Delete this task?" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @else
                <flux:text size="sm" class="italic">No tasks yet — add one to start tracking progress.</flux:text>
            @endif
        </flux:card>
    @empty
        <flux:card class="flex flex-col items-center gap-3 py-10 text-center">
            <flux:icon.viewfinder-circle class="size-10 text-zinc-300 dark:text-zinc-600" />
            <flux:heading>No objectives yet</flux:heading>
            <flux:text>Break this strategy into measurable objectives.</flux:text>
            <flux:button size="sm" variant="primary" icon="plus" wire:click="newObjective">Add objective</flux:button>
        </flux:card>
    @endforelse

    {{-- Modals --}}
    <flux:modal name="edit-strategy" class="md:w-lg">
        <form wire:submit="updateStrategy" class="space-y-6">
            <flux:heading size="lg">Edit strategy</flux:heading>
            <flux:input wire:model="title" label="Title" />
            <flux:textarea wire:model="description" label="Description" rows="3" />
            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="horizon" label="Horizon">
                    @foreach (\App\Enums\Horizon::cases() as $h)
                        <flux:select.option :value="$h->value">{{ $h->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="target_date" type="date" label="Target date" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-strategy" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete strategy?</flux:heading>
                <flux:text class="mt-2">This permanently deletes “{{ $strategy->title }}” along with all of its objectives and tasks.</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteStrategy">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="objective-form" class="md:w-lg">
        <form wire:submit="saveObjective" class="space-y-6">
            <flux:heading size="lg">{{ $editingObjectiveId ? 'Edit objective' : 'New objective' }}</flux:heading>
            <flux:input wire:model="objectiveTitle" label="Title" placeholder="e.g. Sign 5 enterprise customers" />
            <flux:textarea wire:model="objectiveDescription" label="Description" rows="3" />
            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="objectiveOwnerId" label="Owner">
                    <flux:select.option value="">Unassigned</flux:select.option>
                    @foreach ($this->members as $m)
                        <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="objectiveTargetDate" type="date" label="Target date" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingObjectiveId ? 'Save' : 'Add objective' }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="task-form" class="md:w-lg">
        <form wire:submit="saveTask" class="space-y-6">
            <flux:heading size="lg">New task</flux:heading>
            <flux:input wire:model="taskTitle" label="Title" placeholder="What needs doing?" />
            <flux:select wire:model="taskQuadrant" label="Priority (Eisenhower)">
                @foreach (\App\EisenhowerQuadrant::cases() as $quadrant)
                    <flux:select.option :value="$quadrant->value">{{ $quadrant->label() }} — {{ $quadrant->description() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="taskEffort" type="number" min="1" label="Effort (hours)" />
                <flux:input wire:model="taskDueAt" type="date" label="Due date" />
            </div>
            <flux:select wire:model="taskAssigneeId" label="Assign to">
                <flux:select.option value="">Unassigned</flux:select.option>
                @foreach ($this->members as $m)
                    <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Add task</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="ai-breakdown" class="w-full md:w-2xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" class="flex items-center gap-2"><flux:icon.sparkles variant="mini" class="text-violet-500" /> AI breakdown</flux:heading>
                <flux:text class="mt-1">Gemini proposes objectives and tasks for this strategy. Untick anything you do not want, then add the rest.</flux:text>
            </div>
            <flux:button variant="primary" icon="sparkles" wire:click="generateBreakdown">{{ $aiObjectives ? 'Regenerate' : 'Generate proposal' }}</flux:button>
            @if ($aiError)<flux:callout variant="warning" icon="exclamation-triangle" :heading="$aiError" />@endif
            <div wire:loading wire:target="generateBreakdown" class="w-full space-y-2">
                @foreach (range(1, 3) as $i)<flux:skeleton class="h-20 w-full rounded-lg" animate="shimmer" />@endforeach
            </div>
            <div wire:loading.remove wire:target="generateBreakdown" class="max-h-[55vh] space-y-3 overflow-y-auto">
                @foreach ($aiObjectives as $i => $o)
                    <div wire:key="ai-obj-{{ $i }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:checkbox wire:model="aiObjectives.{{ $i }}.selected" :label="$o['title']" :description="$o['description']" />
                        <ul class="mt-2 space-y-1 ps-7 text-sm text-zinc-600 dark:text-zinc-300">
                            @foreach ($o['tasks'] as $t)
                                <li>• {{ $t['title'] }} <span class="text-xs text-zinc-400">({{ str_replace('_', ' ', $t['quadrant']) }}, {{ $t['effort_hours'] }}h)</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            @if ($aiObjectives)
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="applyBreakdown">Add selected</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
