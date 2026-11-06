<?php

use App\EisenhowerQuadrant;
use App\Models\Strategy;
use App\Models\Task;
use App\TasksEnum;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tasks')] class extends Component {
    use WithPagination;

    #[Url]
    public string $view = 'list';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $quadrant = '';

    #[Url]
    public string $strategy = '';

    #[Url]
    public string $assignee = '';

    #[Url]
    public string $sortBy = 'due_at';

    #[Url]
    public string $sortDirection = 'asc';

    // Create form
    public ?int $objective_id = null;
    public string $title = '';
    public string $description = '';
    public string $eisenhower_quadrant = 'Schedule';
    public int $effort_hours = 1;
    public ?string $due_at = null;
    public ?int $assignee_id = null;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'quadrant', 'strategy', 'assignee'])) {
            $this->resetPage();
        }
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'quadrant', 'strategy', 'assignee');
        $this->resetPage();
    }

    protected function query()
    {
        return Task::query()
            ->with('objective:id,title,strategy_id', 'objective.strategy:id,title', 'assignee:id,name')
            ->when($this->assignee === 'none', fn ($q) => $q->whereNull('assignee_id'))
            ->when($this->assignee && $this->assignee !== 'none', fn ($q) => $q->where('assignee_id', $this->assignee))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%")))
            ->when($this->status === 'open', fn ($q) => $q->open())
            ->when($this->status && $this->status !== 'open', fn ($q) => $q->where('status', $this->status))
            ->when($this->quadrant, fn ($q) => $q->where('eisenhower_quadrant', $this->quadrant))
            ->when($this->strategy, fn ($q) => $q->whereHas('objective', fn ($q) => $q->where('strategy_id', $this->strategy)));
    }

    #[Computed]
    public function tasks()
    {
        $column = in_array($this->sortBy, ['title', 'due_at', 'effort_hours', 'status', 'eisenhower_quadrant']) ? $this->sortBy : 'due_at';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return $this->query()
            ->when($column === 'due_at', fn ($q) => $q->orderByRaw('due_at is null'))
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate(15);
    }

    #[Computed]
    public function board(): array
    {
        $tasks = $this->query()
            ->when(! $this->status, fn ($q) => $q->open())
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->get();

        return collect(EisenhowerQuadrant::cases())
            ->mapWithKeys(fn ($q) => [$q->value => $tasks->where('eisenhower_quadrant', $q)])
            ->all();
    }

    #[Computed]
    public function strategies()
    {
        return Strategy::with('objectives:id,strategy_id,title')->orderBy('title')->get(['id', 'title']);
    }

    #[Computed]
    public function members()
    {
        return \App\Models\Member::active()->orderBy('name')->get(['id', 'name']);
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->quadrant !== '' || $this->strategy !== '' || $this->assignee !== '';
    }

    public function setAssignee(int $taskId, ?string $memberId): void
    {
        Task::findOrFail($taskId)->update(['assignee_id' => $memberId ?: null]);
    }

    public function create(): void
    {
        $validated = $this->validate([
            'objective_id' => ['required', 'exists:objectives,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'eisenhower_quadrant' => ['required', Rule::enum(EisenhowerQuadrant::class)],
            'effort_hours' => ['required', 'integer', 'min:1', 'max:999'],
            'due_at' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'exists:members,id'],
        ], attributes: ['objective_id' => 'objective', 'assignee_id' => 'assignee']);

        Task::create([...$validated, 'due_at' => $validated['due_at'] ?: null, 'status' => TasksEnum::NOT_STARTED]);

        $this->reset('title', 'description', 'eisenhower_quadrant', 'effort_hours', 'due_at', 'assignee_id');
        Flux::modal('create-task')->close();
        Flux::toast(variant: 'success', text: 'Task created.');
    }

    public function setStatus(int $taskId, string $status): void
    {
        Task::findOrFail($taskId)->update(['status' => TasksEnum::from($status)]);
    }

    public function setQuadrant(int $taskId, string $quadrant): void
    {
        Task::findOrFail($taskId)->update(['eisenhower_quadrant' => EisenhowerQuadrant::from($quadrant)]);
    }

    public function delete(int $taskId): void
    {
        Task::findOrFail($taskId)->delete();
        Flux::toast(text: 'Task deleted.');
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Tasks</flux:heading>
            <flux:subheading>Every task traces back to an objective and strategy.</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button.group>
                <flux:button size="sm" icon="list-bullet" wire:click="$set('view', 'list')" :variant="$view === 'list' ? 'primary' : 'outline'">List</flux:button>
                <flux:button size="sm" icon="squares-2x2" wire:click="$set('view', 'matrix')" :variant="$view === 'matrix' ? 'primary' : 'outline'">Matrix</flux:button>
            </flux:button.group>

            <flux:modal.trigger name="create-task">
                <flux:button variant="primary" icon="plus">New task</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search tasks..." clearable class="w-full sm:w-64" />

        <flux:select wire:model.live="status" class="w-full sm:w-40">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="open">Open</flux:select.option>
            @foreach (\App\TasksEnum::cases() as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($view === 'list')
            <flux:select wire:model.live="quadrant" class="w-full sm:w-40">
                <flux:select.option value="">All priorities</flux:select.option>
                @foreach (\App\EisenhowerQuadrant::cases() as $q)
                    <flux:select.option :value="$q->value">{{ $q->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:select wire:model.live="strategy" class="w-full sm:w-56">
            <flux:select.option value="">All strategies</flux:select.option>
            @foreach ($this->strategies as $s)
                <flux:select.option :value="$s->id">{{ Str::limit($s->title, 40) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="assignee" class="w-full sm:w-44">
            <flux:select.option value="">Anyone</flux:select.option>
            <flux:select.option value="none">Unassigned</flux:select.option>
            @foreach ($this->members as $m)
                <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->hasFilters())
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">Clear</flux:button>
        @endif
    </div>

    @if ($view === 'matrix')
        {{-- Eisenhower board --}}
        @if (! $status)
            <flux:text size="sm" class="-mt-3">Showing open tasks. Use the status filter to include completed ones.</flux:text>
        @endif
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach (\App\EisenhowerQuadrant::cases() as $q)
                @php $items = $this->board[$q->value]; @endphp
                <flux:card wire:key="quadrant-{{ $q->value }}" class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:badge :color="$q->color()">{{ $q->label() }}</flux:badge>
                            <flux:text size="sm" class="mt-1">{{ $q->description() }}</flux:text>
                        </div>
                        <flux:heading size="lg">{{ $items->count() }}</flux:heading>
                    </div>

                    <div class="max-h-96 space-y-2 overflow-y-auto">
                        @forelse ($items as $task)
                            <div wire:key="board-task-{{ $task->id }}" class="flex items-start justify-between gap-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                                <div class="min-w-0">
                                    <div @class(['truncate text-sm font-medium text-zinc-800 dark:text-white', 'line-through opacity-60' => $task->status === \App\TasksEnum::COMPLETED])>{{ $task->title }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500">
                                        <span class="truncate">{{ $task->objective?->strategy?->title }}</span>
                                        @if ($task->due_at)
                                            <span @class(['text-red-600 dark:text-red-400 font-medium' => $task->isOverdue()])>{{ $task->due_at->format('M j') }}</span>
                                        @endif
                                        <flux:badge size="sm" :color="$task->status->color()">{{ $task->status->label() }}</flux:badge>
                                    </div>
                                </div>
                                <flux:dropdown align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" aria-label="Task actions" />
                                    <flux:menu>
                                        <flux:menu.group heading="Status">
                                            @foreach (\App\TasksEnum::cases() as $s)
                                                <flux:menu.item :icon="$s->icon()" wire:click="setStatus({{ $task->id }}, '{{ $s->value }}')">{{ $s->label() }}</flux:menu.item>
                                            @endforeach
                                        </flux:menu.group>
                                        <flux:menu.separator />
                                        <flux:menu.group heading="Move to">
                                            @foreach (\App\EisenhowerQuadrant::cases() as $target)
                                                @continue($target === $q)
                                                <flux:menu.item wire:click="setQuadrant({{ $task->id }}, '{{ $target->value }}')">{{ $target->label() }}</flux:menu.item>
                                            @endforeach
                                        </flux:menu.group>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        @empty
                            <flux:text size="sm" class="py-6 text-center text-zinc-400!">No tasks</flux:text>
                        @endforelse
                    </div>
                </flux:card>
            @endforeach
        </div>
    @else
        {{-- List view --}}
        @if ($this->tasks->isEmpty())
            <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
                <flux:icon.clipboard-document-list class="size-10 text-zinc-300 dark:text-zinc-600" />
                @if ($this->hasFilters())
                    <flux:heading>No tasks match these filters</flux:heading>
                    <flux:button size="sm" wire:click="clearFilters">Clear filters</flux:button>
                @else
                    <flux:heading>No tasks yet</flux:heading>
                    <flux:text>Add a task to an objective to start tracking work.</flux:text>
                @endif
            </flux:card>
        @else
            <flux:card class="p-0! overflow-hidden">
                <flux:table :paginate="$this->tasks" class="[&_th:first-child]:ps-5 [&_td:first-child]:ps-5 [&_th:last-child]:pe-5 [&_td:last-child]:pe-5" container:class="[&_[data-flux-pagination]]:px-5 [&_[data-flux-pagination]]:pb-3">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">Task</flux:table.column>
                        <flux:table.column>Strategy / objective</flux:table.column>
                        <flux:table.column>Assignee</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'eisenhower_quadrant'" :direction="$sortDirection" wire:click="sort('eisenhower_quadrant')">Priority</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'due_at'" :direction="$sortDirection" wire:click="sort('due_at')">Due</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'effort_hours'" :direction="$sortDirection" wire:click="sort('effort_hours')" align="end">Effort</flux:table.column>
                        <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">Status</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->tasks as $task)
                            <flux:table.row wire:key="task-{{ $task->id }}">
                                <flux:table.cell variant="strong" class="max-w-64 truncate">
                                    <span @class(['line-through text-zinc-400!' => $task->status === \App\TasksEnum::COMPLETED])>{{ $task->title }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="max-w-64">
                                    @if ($task->objective?->strategy)
                                        <a href="{{ route('strategies.show', $task->objective->strategy) }}" wire:navigate class="block truncate text-zinc-800 hover:underline dark:text-zinc-200">{{ $task->objective->strategy->title }}</a>
                                    @endif
                                    <div class="truncate text-xs text-zinc-500">{{ $task->objective?->title }}</div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <select wire:change="setAssignee({{ $task->id }}, $event.target.value)" aria-label="Assignee"
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
                                <flux:table.cell @class(['text-red-600! dark:text-red-400! font-medium' => $task->isOverdue()])>
                                    {{ $task->due_at?->format('M j, Y') ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $task->effort_hours }}h</flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown>
                                        <flux:badge as="button" size="sm" :color="$task->status->color()" :icon="$task->status->icon()" icon:trailing="chevron-down" inset="top bottom">
                                            {{ $task->status->label() }}
                                        </flux:badge>
                                        <flux:menu>
                                            @foreach (\App\TasksEnum::cases() as $s)
                                                <flux:menu.item :icon="$s->icon()" wire:click="setStatus({{ $task->id }}, '{{ $s->value }}')">{{ $s->label() }}</flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" aria-label="Delete task"
                                        wire:click="delete({{ $task->id }})" wire:confirm="Delete this task?" />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif
    @endif

    <flux:modal name="create-task" class="md:w-lg">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">New task</flux:heading>
                <flux:text class="mt-1">Tasks must belong to an objective.</flux:text>
            </div>

            <flux:select wire:model="objective_id" label="Objective" placeholder="Choose an objective...">
                @foreach ($this->strategies as $s)
                    @if ($s->objectives->isNotEmpty())
                        <optgroup label="{{ Str::limit($s->title, 50) }}">
                            @foreach ($s->objectives as $o)
                                <flux:select.option :value="$o->id">{{ $o->title }}</flux:select.option>
                            @endforeach
                        </optgroup>
                    @endif
                @endforeach
            </flux:select>

            <flux:input wire:model="title" label="Title" placeholder="What needs doing?" />
            <flux:textarea wire:model="description" label="Description" rows="2" />

            <flux:select wire:model="eisenhower_quadrant" label="Priority (Eisenhower)">
                @foreach (\App\EisenhowerQuadrant::cases() as $q)
                    <flux:select.option :value="$q->value">{{ $q->label() }} — {{ $q->description() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="effort_hours" type="number" min="1" label="Effort (hours)" />
                <flux:input wire:model="due_at" type="date" label="Due date" />
            </div>

            <flux:select wire:model="assignee_id" label="Assign to">
                <flux:select.option value="">Unassigned</flux:select.option>
                @foreach ($this->members as $m)
                    <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Create task</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
