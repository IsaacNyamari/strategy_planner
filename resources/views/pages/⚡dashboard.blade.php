<?php

use App\EisenhowerQuadrant;
use App\Models\Objective;
use App\Models\Strategy;
use App\Models\Task;
use App\TasksEnum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function stats(): array
    {
        return [
            'strategies' => Strategy::count(),
            'avgProgress' => (int) round(Strategy::avg('current_progress') ?? 0),
            'objectives' => Objective::count(),
            'objectivesDone' => Objective::where('status', TasksEnum::COMPLETED->value)->count(),
            'openTasks' => Task::open()->count(),
            'blockedTasks' => Task::where('status', TasksEnum::BLOCKED->value)->count(),
            'overdueTasks' => Task::open()->where('due_at', '<', now())->count(),
        ];
    }

    #[Computed]
    public function matrix(): array
    {
        $tasks = Task::open()->orderBy('due_at')->get(['id', 'title', 'eisenhower_quadrant', 'due_at', 'status']);

        return collect(EisenhowerQuadrant::cases())
            ->mapWithKeys(fn (EisenhowerQuadrant $q) => [
                $q->value => $tasks->where('eisenhower_quadrant', $q),
            ])
            ->all();
    }

    #[Computed]
    public function upcoming()
    {
        return Task::open()
            ->with('objective.strategy:id,title')
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function nextServices()
    {
        return \App\Models\Ministration::with(['members', 'uniform'])
            ->whereDate('date', '>=', today())
            ->orderBy('date')->orderBy('starts_at')
            ->limit(4)
            ->get();
    }

    #[Computed]
    public function strategies()
    {
        return Strategy::withCount('objectives')
            ->orderByRaw('target_date is null')
            ->orderBy('target_date')
            ->limit(5)
            ->get();
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Dashboard</flux:heading>
        <flux:subheading>Praise &amp; worship team at a glance.</flux:subheading>
    </div>

    {{-- Next services --}}
    <flux:card>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <flux:heading size="lg">Next services</flux:heading>
                <flux:text size="sm">Who is ministering and what to wear</flux:text>
            </div>
            <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('roster')" wire:navigate>Roster</flux:button>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($this->nextServices as $service)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium text-zinc-800 dark:text-white">{{ $service->date->isToday() ? 'Today' : $service->date->format('D j M') }}</span>
                        <flux:badge size="sm" :color="$service->type->color()">{{ $service->type === \App\Enums\ServiceType::SPECIAL ? 'Special' : ucfirst($service->type->value) }}</flux:badge>
                    </div>
                    <flux:text size="sm" class="truncate">{{ $service->displayTitle() }} · {{ $service->timeRange() }}</flux:text>
                    <div class="mt-2 space-y-0.5 text-sm">
                        <div><span class="text-xs font-medium text-amber-600 dark:text-amber-400">Praise</span> {{ $service->praiseLead()?->name ?? 'not assigned' }}</div>
                        <div><span class="text-xs font-medium text-violet-600 dark:text-violet-400">Worship</span> {{ $service->worshipLead()?->name ?? 'not assigned' }}</div>
                    </div>
                    <flux:text size="sm">+ {{ $service->backups()->count() }} backups · {{ $service->uniform?->name ?? 'no uniform' }}</flux:text>
                </div>
            @empty
                <flux:text>No upcoming services. <flux:link :href="route('roster')" wire:navigate>Generate this month's roster</flux:link>.</flux:text>
            @endforelse
        </div>
    </flux:card>

    {{-- Stat tiles --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>Strategies</flux:text>
                <flux:icon.flag variant="mini" class="text-zinc-400" />
            </div>
            <flux:heading size="xl">{{ $this->stats['strategies'] }}</flux:heading>
            <flux:progress :value="$this->stats['avgProgress']" :color="getColor($this->stats['avgProgress'])" />
            <flux:text size="sm">{{ $this->stats['avgProgress'] }}% average progress</flux:text>
        </flux:card>

        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>Objectives</flux:text>
                <flux:icon.viewfinder-circle variant="mini" class="text-zinc-400" />
            </div>
            <flux:heading size="xl">{{ $this->stats['objectives'] }}</flux:heading>
            <flux:text size="sm">{{ $this->stats['objectivesDone'] }} completed</flux:text>
        </flux:card>

        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>Open tasks</flux:text>
                <flux:icon.clipboard-document-list variant="mini" class="text-zinc-400" />
            </div>
            <flux:heading size="xl">{{ $this->stats['openTasks'] }}</flux:heading>
            <flux:text size="sm">{{ $this->stats['blockedTasks'] }} blocked</flux:text>
        </flux:card>

        <flux:card class="space-y-2">
            <div class="flex items-center justify-between">
                <flux:text>Overdue</flux:text>
                <flux:icon.exclamation-triangle variant="mini" @class(['text-red-500' => $this->stats['overdueTasks'] > 0, 'text-zinc-400' => $this->stats['overdueTasks'] === 0]) />
            </div>
            <flux:heading size="xl" @class(['text-red-600! dark:text-red-400!' => $this->stats['overdueTasks'] > 0])>
                {{ $this->stats['overdueTasks'] }}
            </flux:heading>
            <flux:text size="sm">open tasks past their due date</flux:text>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-5">
        {{-- Eisenhower matrix --}}
        <flux:card class="xl:col-span-3">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Eisenhower matrix</flux:heading>
                    <flux:text size="sm">Open tasks by urgency and importance</flux:text>
                </div>
                <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('tasks', ['view' => 'matrix'])" wire:navigate>
                    Open board
                </flux:button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (\App\EisenhowerQuadrant::cases() as $quadrant)
                    @php $items = $this->matrix[$quadrant->value]; @endphp
                    <a href="{{ route('tasks', ['quadrant' => $quadrant->value]) }}" wire:navigate
                        class="group rounded-lg border border-zinc-200 p-4 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:bg-white/5">
                        <div class="mb-3 flex items-start justify-between gap-2">
                            <div>
                                <flux:badge size="sm" :color="$quadrant->color()">{{ $quadrant->label() }}</flux:badge>
                                <flux:text size="sm" class="mt-1">{{ $quadrant->description() }}</flux:text>
                            </div>
                            <flux:heading size="lg">{{ $items->count() }}</flux:heading>
                        </div>
                        <ul class="space-y-1">
                            @forelse ($items->take(3) as $task)
                                <li class="truncate text-sm text-zinc-700 dark:text-zinc-300">• {{ $task->title }}</li>
                            @empty
                                <li class="text-sm text-zinc-400">Nothing here</li>
                            @endforelse
                            @if ($items->count() > 3)
                                <li class="text-xs text-zinc-400">+ {{ $items->count() - 3 }} more</li>
                            @endif
                        </ul>
                    </a>
                @endforeach
            </div>
        </flux:card>

        {{-- Upcoming deadlines --}}
        <flux:card class="xl:col-span-2">
            <flux:heading size="lg">Upcoming deadlines</flux:heading>
            <flux:text size="sm" class="mb-4">Next open tasks by due date</flux:text>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->upcoming as $task)
                    <div class="flex items-center justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $task->title }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $task->objective?->strategy?->title }}</div>
                        </div>
                        <flux:badge size="sm" :color="$task->isOverdue() ? 'red' : 'zinc'" class="shrink-0">
                            {{ $task->isOverdue() ? 'Overdue · ' : '' }}{{ $task->due_at->format('M j') }}
                        </flux:badge>
                    </div>
                @empty
                    <flux:text>No upcoming deadlines. 🎉</flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>

    {{-- Strategies --}}
    <flux:card>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <flux:heading size="lg">Strategies by target date</flux:heading>
                <flux:text size="sm">Progress rolls up from objectives and their tasks</flux:text>
            </div>
            <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('strategies')" wire:navigate>
                All strategies
            </flux:button>
        </div>

        <div class="space-y-4">
            @forelse ($this->strategies as $strategy)
                <a href="{{ route('strategies.show', $strategy) }}" wire:navigate class="block rounded-lg p-2 -m-2 hover:bg-zinc-50 dark:hover:bg-white/5">
                    <div class="mb-1.5 flex items-center justify-between gap-4 text-sm">
                        <span class="truncate font-medium text-zinc-800 dark:text-white">{{ $strategy->title }}</span>
                        <span class="shrink-0 text-zinc-500">
                            {{ $strategy->objectives_count }} {{ Str::plural('objective', $strategy->objectives_count) }}
                            @if ($strategy->target_date)
                                · due {{ $strategy->target_date->format('M j, Y') }}
                            @endif
                            · <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $strategy->current_progress }}%</span>
                        </span>
                    </div>
                    <flux:progress :value="$strategy->current_progress" :color="getColor($strategy->current_progress)" />
                </a>
            @empty
                <flux:text>No strategies yet. <flux:link :href="route('strategies')" wire:navigate>Create your first one</flux:link>.</flux:text>
            @endforelse
        </div>
    </flux:card>
</div>
