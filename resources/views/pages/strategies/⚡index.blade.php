<?php

use App\Livewire\Guard;
use App\Models\Strategy;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Strategies'), Guard('strategies.manage')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $sort = 'target_date';

    #[Url]
    public string $horizon = '';

    public string $newHorizon = 'long_term';

    public string $title = '';
    public string $description = '';
    public ?string $target_date = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedHorizon(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function strategies()
    {
        $query = Strategy::query()
            ->withCount(['objectives', 'tasks'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%")))
            ->when($this->horizon, fn ($q) => $q->where('horizon', $this->horizon));

        match ($this->sort) {
            'progress_desc' => $query->orderByDesc('current_progress'),
            'progress_asc' => $query->orderBy('current_progress'),
            'title' => $query->orderBy('title'),
            'newest' => $query->latest(),
            default => $query->orderByRaw('target_date is null')->orderBy('target_date'),
        };

        return $query->paginate(9);
    }

    public function create(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_date' => ['nullable', 'date'],
            'newHorizon' => ['required', \Illuminate\Validation\Rule::enum(\App\Enums\Horizon::class)],
        ]);

        $strategy = Strategy::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'target_date' => $validated['target_date'] ?: null,
            'horizon' => $validated['newHorizon'],
        ]);

        $this->reset('title', 'description', 'target_date', 'newHorizon');
        Flux::modal('create-strategy')->close();
        Flux::toast(variant: 'success', text: 'Strategy created.');

        $this->redirectRoute('strategies.show', $strategy, navigate: true);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Strategies</flux:heading>
            <flux:subheading>High-level goals, broken down into objectives and tasks.</flux:subheading>
        </div>

        <flux:modal.trigger name="create-strategy">
            <flux:button variant="primary" icon="plus">New strategy</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search strategies..." clearable class="sm:max-w-xs" />
        <flux:select wire:model.live="horizon" class="sm:max-w-44">
            <flux:select.option value="">All horizons</flux:select.option>
            @foreach (\App\Enums\Horizon::cases() as $h)
                <flux:select.option :value="$h->value">{{ $h->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="sort" class="sm:max-w-52">
            <flux:select.option value="target_date">Target date</flux:select.option>
            <flux:select.option value="progress_desc">Most progress</flux:select.option>
            <flux:select.option value="progress_asc">Least progress</flux:select.option>
            <flux:select.option value="title">Title (A–Z)</flux:select.option>
            <flux:select.option value="newest">Newest</flux:select.option>
        </flux:select>
    </div>

    @if ($this->strategies->isEmpty())
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <flux:icon.flag class="size-10 text-zinc-300 dark:text-zinc-600" />
            @if ($search)
                <flux:heading>No strategies match “{{ $search }}”</flux:heading>
                <flux:button size="sm" wire:click="$set('search', '')">Clear search</flux:button>
            @else
                <flux:heading>No strategies yet</flux:heading>
                <flux:text>Create your first strategy to start planning.</flux:text>
                <flux:modal.trigger name="create-strategy">
                    <flux:button size="sm" variant="primary" icon="plus">New strategy</flux:button>
                </flux:modal.trigger>
            @endif
        </flux:card>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->strategies as $strategy)
                <a href="{{ route('strategies.show', $strategy) }}" wire:navigate wire:key="strategy-{{ $strategy->id }}" class="group">
                    <flux:card class="flex h-full flex-col gap-3 transition group-hover:border-zinc-300 group-hover:shadow-md dark:group-hover:border-zinc-600">
                        <div class="flex items-start justify-between gap-3">
                            <flux:heading size="lg" class="line-clamp-2">{{ $strategy->title }}</flux:heading>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                            <flux:badge size="sm" :color="$strategy->horizon->color()">{{ $strategy->horizon->label() }}</flux:badge>
                            @if ($strategy->current_progress >= 100)
                                <flux:badge size="sm" color="green" icon="check">Done</flux:badge>
                            @elseif ($strategy->target_date?->isPast())
                                <flux:badge size="sm" color="red">Past due</flux:badge>
                            @endif
                            </div>
                        </div>

                        <flux:text class="line-clamp-3 flex-1">{{ $strategy->description ?: 'No description.' }}</flux:text>

                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="flex items-center gap-1"><flux:icon.viewfinder-circle variant="micro" /> {{ $strategy->objectives_count }} {{ Str::plural('objective', $strategy->objectives_count) }}</span>
                            <span class="flex items-center gap-1"><flux:icon.clipboard-document-list variant="micro" /> {{ $strategy->tasks_count }} {{ Str::plural('task', $strategy->tasks_count) }}</span>
                            @if ($strategy->target_date)
                                <span class="flex items-center gap-1"><flux:icon.calendar variant="micro" /> {{ $strategy->target_date->format('M j, Y') }}</span>
                            @endif
                        </div>

                        <div>
                            <div class="mb-1 flex justify-between text-xs">
                                <span class="text-zinc-500 dark:text-zinc-400">Progress</span>
                                <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $strategy->current_progress }}%</span>
                            </div>
                            <flux:progress :value="$strategy->current_progress" :color="getColor($strategy->current_progress)" />
                        </div>
                    </flux:card>
                </a>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->strategies" />
    @endif

    <flux:modal name="create-strategy" class="md:w-lg">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">New strategy</flux:heading>
                <flux:text class="mt-1">A high-level goal you want to achieve.</flux:text>
            </div>

            <flux:input wire:model="title" label="Title" placeholder="e.g. Train 5 new worship leaders" autofocus />
            <flux:textarea wire:model="description" label="Description" rows="3" placeholder="Why does this matter?" />
            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="newHorizon" label="Horizon">
                    @foreach (\App\Enums\Horizon::cases() as $h)
                        <flux:select.option :value="$h->value">{{ $h->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="target_date" type="date" label="Target date" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create strategy</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
