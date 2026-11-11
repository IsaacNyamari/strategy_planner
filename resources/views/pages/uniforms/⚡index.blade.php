<?php

use App\Livewire\Guard;
use App\Ai\GeminiException;
use App\Ai\WorshipAssistant;
use App\Models\Ministration;
use App\Models\Uniform;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Uniforms'), Guard('uniforms.manage')] class extends Component {
    /** @var list<array{name: string, top: string, ladies_bottom: string, men_bottom: string, colors: string, notes: string}> */
    public array $suggestions = [];

    public bool $suggestionsLoaded = false;
    public ?string $aiError = null;
    public string $occasion = '';

    // Catalogue form
    public ?int $editingId = null;
    public string $name = '';
    public string $top = '';
    public string $ladies_bottom = '';
    public string $men_bottom = '';
    public string $colors = '';
    public string $notes = '';
    public bool $is_active = true;

    /**
     * Only ever runs when the user clicks "Generate ideas", never on page
     * load, to protect the Gemini quota. The first click reuses ideas cached
     * in the last 30 minutes; later clicks ask Gemini for fresh ones.
     */
    public function generateSuggestions(): void
    {
        $fresh = $this->suggestionsLoaded;
        $this->suggestions = [];
        $this->loadSuggestions($fresh);
    }

    public function loadSuggestions(bool $fresh = false): void
    {
        $this->aiError = null;
        $assistant = app(WorshipAssistant::class);

        if (! $assistant->configured()) {
            $this->aiError = 'AI is not configured. Add GEMINI_API_KEY to your .env file.';
            $this->suggestionsLoaded = true;

            return;
        }

        $key = 'uniform-suggestions:'.md5(Uniform::pluck('name')->implode('|').'|'.$this->occasion);

        if ($fresh) {
            Cache::forget($key);
        }

        try {
            // Cached briefly so repeat clicks / other users don't burn the free-tier quota.
            $this->suggestions = Cache::remember($key, now()->addMinutes(30), fn () => $assistant->uniformSuggestions(4, $this->occasion ?: null));
        } catch (GeminiException $e) {
            $this->aiError = $e->getMessage();
        }

        $this->suggestionsLoaded = true;
    }

    /** Validate an (optionally edited) AI suggestion and save it to the catalogue. */
    public function acceptSuggestion(int $index): void
    {
        $data = $this->suggestions[$index] ?? null;

        if (! $data) {
            return;
        }

        $validator = Validator::make($data, $this->rules(), [], [
            'ladies_bottom' => "ladies' dress/skirt",
            'men_bottom' => "men's trousers",
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError("suggestions.{$index}.{$field}", $messages[0]);
            }

            return;
        }

        if (Uniform::where('name', $data['name'])->exists()) {
            $this->addError("suggestions.{$index}.name", 'A uniform with this name already exists. Rename it first.');

            return;
        }

        Uniform::create([...$validator->validated(), 'source' => 'ai', 'is_active' => true]);

        array_splice($this->suggestions, $index, 1);
        $this->resetErrorBag();
        unset($this->uniforms);
        Flux::toast(variant: 'success', text: 'Uniform saved. It is now available for the roster.');
    }

    public function dismissSuggestion(int $index): void
    {
        array_splice($this->suggestions, $index, 1);
        $this->resetErrorBag();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'top' => ['required', 'string', 'max:255'],
            'ladies_bottom' => ['required', 'string', 'max:255'],
            'men_bottom' => ['required', 'string', 'max:255'],
            'colors' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    #[Computed]
    public function uniforms()
    {
        return Uniform::withCount(['ministrations as upcoming_count' => fn ($q) => $q->whereDate('date', '>=', today())])
            ->orderByDesc('is_active')->orderBy('name')->get();
    }

    /** Uniform timetable: the next services and what the team wears. */
    #[Computed]
    public function timetable()
    {
        return Ministration::with('uniform')
            ->whereDate('date', '>=', today())
            ->orderBy('date')->orderBy('starts_at')
            ->limit(15)
            ->get();
    }

    public function setServiceUniform(int $serviceId, ?string $uniformId): void
    {
        Ministration::findOrFail($serviceId)->update(['uniform_id' => $uniformId ?: null]);
        unset($this->timetable, $this->uniforms);
        Flux::toast(text: 'Uniform updated for that service.');
    }

    public function newUniform(): void
    {
        $this->reset('editingId', 'name', 'top', 'ladies_bottom', 'men_bottom', 'colors', 'notes', 'is_active');
        $this->resetValidation();
        Flux::modal('uniform-form')->show();
    }

    public function edit(int $id): void
    {
        $u = Uniform::findOrFail($id);
        $this->editingId = $u->id;
        $this->fill($u->only('name', 'top', 'ladies_bottom', 'men_bottom', 'is_active'));
        $this->colors = (string) $u->colors;
        $this->notes = (string) $u->notes;
        $this->resetValidation();
        Flux::modal('uniform-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([...$this->rules(), 'is_active' => ['boolean']]);

        Uniform::updateOrCreate(['id' => $this->editingId], $data);

        Flux::modal('uniform-form')->close();
        Flux::toast(variant: 'success', text: 'Uniform saved.');
        unset($this->uniforms);
    }

    public function delete(int $id): void
    {
        Uniform::findOrFail($id)->delete();
        unset($this->uniforms, $this->timetable);
        Flux::toast(text: 'Uniform deleted. Services using it now show "no uniform".');
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Uniforms</flux:heading>
            <flux:subheading>Top + ladies' dress/skirt + men's trousers, assigned to each service.</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:dropdown>
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down">Timetable</flux:button>
                <flux:menu>
                    @foreach ([now(), now()->addMonthNoOverflow()] as $m)
                        <flux:menu.heading>{{ $m->format('F Y') }}</flux:menu.heading>
                        <flux:menu.item icon="document-text" :href="route('exports.uniforms', ['format' => 'pdf', 'month' => $m->format('Y-m')])">PDF</flux:menu.item>
                        <flux:menu.item icon="table-cells" :href="route('exports.uniforms', ['format' => 'xlsx', 'month' => $m->format('Y-m')])">Excel</flux:menu.item>
                        @can('send-whatsapp')
                            <flux:menu.item icon="chat-bubble-left-right" x-on:click="$dispatch('whatsapp-share', { type: 'uniforms', month: '{{ $m->format('Y-m') }}' })">Send to WhatsApp</flux:menu.item>
                        @endcan
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            <flux:button variant="primary" icon="plus" wire:click="newUniform">Add uniform</flux:button>
        </div>
    </div>

    {{-- AI suggestions --}}
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading size="lg" class="flex items-center gap-2"><flux:icon.sparkles variant="mini" class="text-violet-500" /> AI suggestions</flux:heading>
                <flux:text size="sm">Ideas from Gemini. Edit anything, then save the ones you like to your catalogue.</flux:text>
            </div>
            <form wire:submit="generateSuggestions" class="flex items-end gap-2">
                <flux:input wire:model="occasion" size="sm" placeholder="Occasion (optional): Easter, Christmas…" class="w-64" />
                <flux:button size="sm" type="submit" :variant="$suggestionsLoaded ? 'outline' : 'primary'" :icon="$suggestionsLoaded ? 'arrow-path' : 'sparkles'">
                    {{ $suggestionsLoaded ? 'New ideas' : 'Generate ideas' }}
                </flux:button>
            </form>
        </div>

        @if ($aiError)
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="$aiError" />
        @endif

        <div wire:loading.flex wire:target="generateSuggestions" class="grid w-full gap-3 md:grid-cols-2">
            @foreach (range(1, 4) as $i)
                <div class="space-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:skeleton class="h-5 w-1/2" animate="shimmer" />
                    <flux:skeleton class="h-4 w-full" animate="shimmer" />
                    <flux:skeleton class="h-4 w-5/6" animate="shimmer" />
                </div>
            @endforeach
        </div>

        <div wire:loading.remove wire:target="generateSuggestions">
            @if (! $suggestionsLoaded)
                <div class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
                    <flux:icon.swatch class="size-8 text-zinc-300 dark:text-zinc-600" />
                    <flux:text>Click <strong>Generate ideas</strong> to get uniform suggestions from Gemini.</flux:text>
                    <flux:text size="sm">Ideas are only generated when you ask, to save your AI quota.</flux:text>
                </div>
            @elseif (empty($suggestions) && ! $aiError)
                <flux:text size="sm">All suggestions handled. Click “New ideas” for more.</flux:text>
            @else
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($suggestions as $i => $s)
                        <div wire:key="suggestion-{{ $i }}-{{ md5($s['name']) }}" class="space-y-2 rounded-lg border border-violet-200 bg-violet-50/40 p-4 dark:border-violet-500/30 dark:bg-violet-500/5">
                            <flux:input wire:model="suggestions.{{ $i }}.name" size="sm" label="Name" />
                            <flux:input wire:model="suggestions.{{ $i }}.top" size="sm" label="Top" />
                            <div class="grid grid-cols-2 gap-2">
                                <flux:input wire:model="suggestions.{{ $i }}.ladies_bottom" size="sm" label="Ladies (dress/skirt)" />
                                <flux:input wire:model="suggestions.{{ $i }}.men_bottom" size="sm" label="Men (trousers)" />
                            </div>
                            <flux:input wire:model="suggestions.{{ $i }}.colors" size="sm" label="Colours" />
                            <flux:input wire:model="suggestions.{{ $i }}.notes" size="sm" label="When to wear" />
                            <div class="flex justify-end gap-2 pt-1">
                                <flux:button size="sm" variant="ghost" wire:click="dismissSuggestion({{ $i }})">Dismiss</flux:button>
                                <flux:button size="sm" variant="primary" icon="check" wire:click="acceptSuggestion({{ $i }})">Validate &amp; save</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-5">
        {{-- Catalogue --}}
        <div class="space-y-3 xl:col-span-3">
            <flux:heading size="lg">Catalogue</flux:heading>
            @forelse ($this->uniforms as $u)
                <flux:card wire:key="uniform-{{ $u->id }}" @class(['p-4!', 'opacity-60' => ! $u->is_active])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <flux:heading>{{ $u->name }}</flux:heading>
                                @if ($u->source === 'ai')<flux:badge size="sm" color="violet" icon="sparkles">AI</flux:badge>@endif
                                @unless ($u->is_active)<flux:badge size="sm">Retired</flux:badge>@endunless
                            </div>
                            <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                                <div><dt class="text-xs text-zinc-500">Top</dt><dd>{{ $u->top }}</dd></div>
                                <div><dt class="text-xs text-zinc-500">Ladies</dt><dd>{{ $u->ladies_bottom }}</dd></div>
                                <div><dt class="text-xs text-zinc-500">Men</dt><dd>{{ $u->men_bottom }}</dd></div>
                            </dl>
                            @if ($u->notes)<flux:text size="sm" class="mt-1">{{ $u->notes }}</flux:text>@endif
                            <flux:text size="sm" class="mt-1">{{ $u->upcoming_count }} upcoming {{ Str::plural('service', $u->upcoming_count) }}</flux:text>
                        </div>
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="Uniform actions" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $u->id }})">Edit</flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $u->id }})" wire:confirm="Delete {{ $u->name }}?">Delete</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </flux:card>
            @empty
                <flux:card class="py-8 text-center"><flux:text>No uniforms yet. Save an AI suggestion above or add your own.</flux:text></flux:card>
            @endforelse
        </div>

        {{-- Timetable --}}
        <flux:card class="xl:col-span-2">
            <flux:heading size="lg">Uniform timetable</flux:heading>
            <flux:text size="sm" class="mb-3">Next services. The roster generator rotates uniforms automatically; change any here.</flux:text>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->timetable as $service)
                    <div class="flex items-center justify-between gap-3 py-2" wire:key="tt-{{ $service->id }}">
                        <div class="min-w-0">
                            <div class="text-sm font-medium">{{ $service->date->format('D j M') }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $service->displayTitle() }}</div>
                        </div>
                        <select wire:change="setServiceUniform({{ $service->id }}, $event.target.value)"
                            class="w-40 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                            <option value="">—</option>
                            @foreach ($this->uniforms->where('is_active', true) as $u)
                                <option value="{{ $u->id }}" @selected($service->uniform_id === $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @empty
                    <flux:text size="sm">No upcoming services. <flux:link :href="route('roster')" wire:navigate>Generate the roster</flux:link>.</flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>

    <flux:modal name="uniform-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit uniform' : 'Add uniform' }}</flux:heading>
            <flux:input wire:model="name" label="Name" placeholder="e.g. Royal blue & gold" />
            <flux:input wire:model="top" label="Top" placeholder="Royal blue shirts / blouses" />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="ladies_bottom" label="Ladies (dress/skirt)" />
                <flux:input wire:model="men_bottom" label="Men (trousers)" />
            </div>
            <flux:input wire:model="colors" label="Colours" />
            <flux:textarea wire:model="notes" label="Notes" rows="2" />
            <flux:switch wire:model="is_active" label="In use" description="Retired uniforms are skipped by the roster generator." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
