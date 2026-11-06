<?php

use App\Ai\GeminiException;
use App\Ai\WorshipAssistant;
use App\Enums\SongCategory;
use App\Enums\SongLanguage;
use App\Models\Song;
use Flux\Flux;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Songs & hymns')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $language = '';

    #[Url]
    public string $category = '';

    // Song form
    public ?int $editingId = null;
    public array $form = [];

    // Viewing lyrics
    public ?int $viewingId = null;

    // AI
    public string $aiLanguage = 'swahili';
    public string $aiCategory = '';
    public string $aiTheme = '';
    public array $suggestions = [];
    public ?string $aiError = null;

    public function mount(): void
    {
        $this->form = $this->blankForm();
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'language', 'category'])) {
            $this->resetPage();
        }
    }

    protected function blankForm(): array
    {
        return [
            'title' => '', 'language' => 'english', 'category' => 'worship', 'artist' => '',
            'musical_key' => '', 'tempo_bpm' => null, 'time_signature' => '4/4', 'theme' => '',
            'lyrics' => '', 'is_public_domain' => false, 'notes' => '',
        ];
    }

    protected function rules(string $prefix = ''): array
    {
        return [
            $prefix.'title' => ['required', 'string', 'max:255'],
            $prefix.'language' => ['required', Rule::enum(SongLanguage::class)],
            $prefix.'category' => ['required', Rule::enum(SongCategory::class)],
            $prefix.'artist' => ['nullable', 'string', 'max:255'],
            $prefix.'musical_key' => ['nullable', 'string', 'max:10'],
            $prefix.'tempo_bpm' => ['nullable', 'integer', 'min:30', 'max:240'],
            $prefix.'time_signature' => ['nullable', 'string', 'max:5'],
            $prefix.'theme' => ['nullable', 'string', 'max:255'],
            $prefix.'lyrics' => ['nullable', 'string', 'max:20000'],
            $prefix.'is_public_domain' => ['boolean'],
            $prefix.'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    #[Computed]
    public function songs()
    {
        return Song::query()
            ->withCount('ministrations')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$this->search}%")
                ->orWhere('artist', 'like', "%{$this->search}%")
                ->orWhere('theme', 'like', "%{$this->search}%")))
            ->when($this->language, fn ($q) => $q->where('language', $this->language))
            ->when($this->category, fn ($q) => $q->where('category', $this->category))
            ->orderBy('title')
            ->paginate(24);
    }

    #[Computed]
    public function viewing(): ?Song
    {
        return $this->viewingId ? Song::find($this->viewingId) : null;
    }

    public function showSong(int $id): void
    {
        $this->viewingId = $id;
        unset($this->viewing);
        Flux::modal('lyrics')->show();
    }

    public function newSong(): void
    {
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->resetValidation();
        Flux::modal('song-form')->show();
    }

    public function edit(int $id): void
    {
        $song = Song::findOrFail($id);
        $this->editingId = $song->id;
        $this->form = [...$this->blankForm(), ...collect($song->only(array_keys($this->blankForm())))
            ->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all()];
        $this->resetValidation();
        Flux::modal('lyrics')->close();
        Flux::modal('song-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate($this->rules('form.'))['form'];
        $data['tempo_bpm'] = $data['tempo_bpm'] ?: null;

        Song::updateOrCreate(['id' => $this->editingId], $data);

        Flux::modal('song-form')->close();
        Flux::toast(variant: 'success', text: 'Song saved.');
        unset($this->songs);
    }

    public function delete(int $id): void
    {
        Song::findOrFail($id)->delete();
        Flux::modal('lyrics')->close();
        unset($this->songs);
        Flux::toast(text: 'Song deleted.');
    }

    // --- AI ------------------------------------------------------------

    public function suggest(): void
    {
        $this->aiError = null;
        $this->suggestions = [];

        try {
            $this->suggestions = app(WorshipAssistant::class)->songSuggestions(
                SongLanguage::from($this->aiLanguage),
                SongCategory::tryFrom($this->aiCategory),
                $this->aiTheme ?: null,
            );

            if ($this->suggestions === []) {
                $this->aiError = 'No new songs suggested. Try another theme or category.';
            }
        } catch (GeminiException $e) {
            $this->aiError = $e->getMessage();
        }
    }

    public function acceptSuggestion(int $index): void
    {
        $data = $this->suggestions[$index] ?? null;

        if (! $data) {
            return;
        }

        $fields = array_intersect_key($data, $this->blankForm());
        $fields['tempo_bpm'] = $fields['tempo_bpm'] ?: null;

        if (! $fields['is_public_domain']) {
            $fields['lyrics'] = ''; // never store AI lyrics of copyrighted songs
        }

        $validator = Validator::make($fields, $this->rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError("suggestions.{$index}.{$field}", $messages[0]);
            }

            return;
        }

        if (Song::where('title', $fields['title'])->where('language', $fields['language'])->exists()) {
            $this->addError("suggestions.{$index}.title", 'This song is already in the library.');

            return;
        }

        $notes = ! $fields['is_public_domain'] && filled($data['first_line'] ?? null)
            ? 'First line: '.$data['first_line'].'. Add licensed lyrics manually.'
            : null;

        Song::create([...$validator->validated(), 'notes' => $notes, 'source' => 'ai']);

        array_splice($this->suggestions, $index, 1);
        $this->resetErrorBag();
        unset($this->songs);
        Flux::toast(variant: 'success', text: 'Song added to the library.');
    }

    public function dismissSuggestion(int $index): void
    {
        array_splice($this->suggestions, $index, 1);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Songs &amp; hymns</flux:heading>
            <flux:subheading>Praise, worship and hymns in English and Kiswahili, ready for each service's setlist.</flux:subheading>
        </div>
        <div class="flex gap-2">
            @php $exportFilters = array_filter(['search' => $search, 'language' => $language, 'category' => $category]); @endphp
            <flux:dropdown>
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down">Download</flux:button>
                <flux:menu>
                    <flux:menu.heading>{{ $exportFilters ? 'Current filtered list' : 'Whole library' }}</flux:menu.heading>
                    <flux:menu.item icon="document-text" :href="route('exports.songs', ['format' => 'pdf', ...$exportFilters])">PDF list</flux:menu.item>
                    <flux:menu.item icon="document-duplicate" :href="route('exports.songs', ['format' => 'pdf', 'lyrics' => 1, ...$exportFilters])">PDF with lyric sheets</flux:menu.item>
                    <flux:menu.item icon="table-cells" :href="route('exports.songs', ['format' => 'xlsx', ...$exportFilters])">Excel</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
            <flux:modal.trigger name="ai-songs">
                <flux:button icon="sparkles">AI suggestions</flux:button>
            </flux:modal.trigger>
            <flux:button variant="primary" icon="plus" wire:click="newSong">Add song</flux:button>
        </div>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search title, artist, theme..." clearable class="w-full sm:w-72" />
        <flux:select wire:model.live="language" class="w-full sm:w-40">
            <flux:select.option value="">All languages</flux:select.option>
            @foreach (\App\Enums\SongLanguage::cases() as $l)
                <flux:select.option :value="$l->value">{{ $l->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="category" class="w-full sm:w-40">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach (\App\Enums\SongCategory::cases() as $c)
                <flux:select.option :value="$c->value">{{ $c->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->songs->isEmpty())
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <flux:icon.musical-note class="size-10 text-zinc-300 dark:text-zinc-600" />
            <flux:heading>No songs yet</flux:heading>
            <flux:text>Add your repertoire, or let AI suggest well-known songs and public-domain hymns.</flux:text>
            <flux:modal.trigger name="ai-songs"><flux:button size="sm" icon="sparkles">Get AI suggestions</flux:button></flux:modal.trigger>
        </flux:card>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->songs as $song)
                <button type="button" wire:key="song-{{ $song->id }}" wire:click="showSong({{ $song->id }})" class="text-start">
                    <flux:card class="h-full space-y-2 p-4! transition hover:border-zinc-300 hover:shadow-sm dark:hover:border-zinc-600">
                        <div class="flex items-start justify-between gap-2">
                            <flux:heading class="line-clamp-2">{{ $song->title }}</flux:heading>
                            <flux:badge size="sm" :color="$song->category->color()">{{ $song->category->label() }}</flux:badge>
                        </div>
                        <flux:text size="sm">{{ $song->artist ?: 'Unknown' }} · {{ $song->language->label() }}</flux:text>
                        <div class="flex flex-wrap gap-1.5">
                            @if ($song->musical_key)<flux:badge size="sm">Key {{ $song->musical_key }}</flux:badge>@endif
                            @if ($song->tempo_bpm)<flux:badge size="sm">{{ $song->tempo_bpm }} bpm</flux:badge>@endif
                            @if ($song->time_signature)<flux:badge size="sm">{{ $song->time_signature }}</flux:badge>@endif
                            @if ($song->lyrics)<flux:badge size="sm" color="green" icon="document-text">Lyrics</flux:badge>@endif
                            @if ($song->source === 'ai')<flux:badge size="sm" color="violet" icon="sparkles">AI</flux:badge>@endif
                        </div>
                    </flux:card>
                </button>
            @endforeach
        </div>
        <flux:pagination :paginator="$this->songs" />
    @endif

    {{-- Lyrics / details --}}
    <flux:modal name="lyrics" class="w-full md:w-xl">
        @if ($song = $this->viewing)
            <div class="space-y-4">
                <div class="pe-8">
                    <flux:heading size="lg">{{ $song->title }}</flux:heading>
                    <flux:text>{{ $song->artist ?: 'Unknown' }} · {{ $song->language->label() }} · {{ $song->category->label() }}</flux:text>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <flux:badge>Key: {{ $song->musical_key ?: '—' }}</flux:badge>
                    <flux:badge>Tempo: {{ $song->tempo_bpm ? $song->tempo_bpm.' bpm' : '—' }}</flux:badge>
                    <flux:badge>Time: {{ $song->time_signature ?: '—' }}</flux:badge>
                    @if ($song->theme)<flux:badge color="sky">{{ $song->theme }}</flux:badge>@endif
                    @if ($song->is_public_domain)<flux:badge color="green">Public domain</flux:badge>@endif
                </div>
                @if ($song->lyrics)
                    <div class="max-h-96 overflow-y-auto whitespace-pre-line rounded-lg bg-zinc-50 p-4 text-sm leading-relaxed dark:bg-white/5">{{ $song->lyrics }}</div>
                @else
                    <flux:callout icon="information-circle" heading="No lyrics stored" text="Paste the lyrics your church is licensed to use (e.g. via CCLI) by editing this song." />
                @endif
                @if ($song->notes)<flux:text size="sm">{{ $song->notes }}</flux:text>@endif
                <div class="flex justify-between">
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="delete({{ $song->id }})" wire:confirm="Delete this song?">Delete</flux:button>
                    <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $song->id }})">Edit</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Add / edit --}}
    <flux:modal name="song-form" class="w-full md:w-2xl">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit song' : 'Add song' }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="form.title" label="Title" />
                <flux:input wire:model="form.artist" label="Artist / writer" />
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <flux:select wire:model="form.language" label="Language">
                    @foreach (\App\Enums\SongLanguage::cases() as $l)<flux:select.option :value="$l->value">{{ $l->label() }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="form.category" label="Category">
                    @foreach (\App\Enums\SongCategory::cases() as $c)<flux:select.option :value="$c->value">{{ $c->label() }}</flux:select.option>@endforeach
                </flux:select>
                <flux:input wire:model="form.musical_key" label="Key" placeholder="G" />
                <flux:input wire:model="form.tempo_bpm" type="number" label="BPM" />
                <flux:input wire:model="form.time_signature" label="Time" placeholder="4/4" />
            </div>
            <flux:input wire:model="form.theme" label="Theme" placeholder="Grace, thanksgiving, the cross…" />
            <flux:textarea wire:model="form.lyrics" label="Lyrics" rows="8" />
            <flux:checkbox wire:model="form.is_public_domain" label="Public domain" />
            <flux:textarea wire:model="form.notes" label="Notes" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- AI suggestions --}}
    <flux:modal name="ai-songs" class="w-full md:w-3xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" class="flex items-center gap-2"><flux:icon.sparkles variant="mini" class="text-violet-500" /> Song suggestions</flux:heading>
                <flux:text class="mt-1">Gemini lists real, well-known songs with key, tempo and time signature. Full lyrics are included only for public-domain hymns; for copyrighted songs, add your licensed lyrics yourself. Check each entry before saving.</flux:text>
            </div>
            <form wire:submit="suggest" class="flex flex-wrap items-end gap-2">
                <flux:select wire:model="aiLanguage" label="Language" class="w-36">
                    @foreach (\App\Enums\SongLanguage::cases() as $l)<flux:select.option :value="$l->value">{{ $l->label() }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="aiCategory" label="Category" class="w-36">
                    <flux:select.option value="">Any</flux:select.option>
                    @foreach (\App\Enums\SongCategory::cases() as $c)<flux:select.option :value="$c->value">{{ $c->label() }}</flux:select.option>@endforeach
                </flux:select>
                <flux:input wire:model="aiTheme" label="Theme (optional)" placeholder="Holy Spirit, thanksgiving…" class="min-w-40 flex-1" />
                <flux:button type="submit" variant="primary" icon="sparkles">Suggest</flux:button>
            </form>

            @if ($aiError)<flux:callout variant="warning" icon="exclamation-triangle" :heading="$aiError" />@endif

            <div wire:loading wire:target="suggest" class="w-full space-y-2">
                @foreach (range(1, 3) as $i)<flux:skeleton class="h-16 w-full rounded-lg" animate="shimmer" />@endforeach
            </div>

            <div wire:loading.remove wire:target="suggest" class="max-h-[60vh] space-y-3 overflow-y-auto">
                @foreach ($suggestions as $i => $s)
                    <div wire:key="song-suggestion-{{ $i }}-{{ md5($s['title']) }}" class="space-y-2 rounded-lg border border-violet-200 p-3 dark:border-violet-500/30">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <flux:input wire:model="suggestions.{{ $i }}.title" size="sm" label="Title" />
                            <flux:input wire:model="suggestions.{{ $i }}.artist" size="sm" label="Artist / writer" />
                        </div>
                        <div class="grid grid-cols-4 gap-2">
                            <flux:select wire:model="suggestions.{{ $i }}.category" size="sm" label="Category">
                                @foreach (\App\Enums\SongCategory::cases() as $c)<flux:select.option :value="$c->value">{{ $c->label() }}</flux:select.option>@endforeach
                            </flux:select>
                            <flux:input wire:model="suggestions.{{ $i }}.musical_key" size="sm" label="Key" />
                            <flux:input wire:model="suggestions.{{ $i }}.tempo_bpm" size="sm" type="number" label="BPM" />
                            <flux:input wire:model="suggestions.{{ $i }}.time_signature" size="sm" label="Time" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                            @if ($s['theme'])<flux:badge size="sm" color="sky">{{ $s['theme'] }}</flux:badge>@endif
                            @if ($s['is_public_domain'])
                                <flux:badge size="sm" color="green">Public domain · lyrics included</flux:badge>
                            @else
                                <span>Copyrighted, first line: “{{ $s['first_line'] }}”</span>
                            @endif
                        </div>
                        @if ($s['is_public_domain'] && $s['lyrics'])
                            <flux:textarea wire:model="suggestions.{{ $i }}.lyrics" size="sm" rows="4" label="Lyrics (check before saving)" />
                        @endif
                        @error("suggestions.$i.title")<flux:text size="sm" class="text-red-600!">{{ $message }}</flux:text>@enderror
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" variant="ghost" wire:click="dismissSuggestion({{ $i }})">Dismiss</flux:button>
                            <flux:button size="sm" variant="primary" icon="check" wire:click="acceptSuggestion({{ $i }})">Validate &amp; save</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </flux:modal>
</div>
