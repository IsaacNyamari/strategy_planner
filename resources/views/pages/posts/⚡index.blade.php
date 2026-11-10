<?php

use App\Models\ScheduledPost;
use App\Models\WhatsappTarget;
use App\Reports\WorshipReports;
use App\Social\FacebookPage;
use App\Social\PostPublisher;
use App\WhatsApp\MessageTemplates;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Post scheduler')] class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;
    public string $body = '';
    public $image = null;
    public ?string $existingImage = null;
    public bool $removeImage = false;
    /** @var list<int|string> */
    public array $targets = [];
    public bool $toFacebook = false;
    public ?string $date = null;
    public ?string $time = null;
    public string $repeat = 'none';
    public string $template = '';

    protected function tz(): string
    {
        return (string) config('worship.timezone');
    }

    #[Computed]
    public function upcoming()
    {
        return ScheduledPost::whereIn('status', ['scheduled', 'sending'])->orderBy('scheduled_at')->get();
    }

    #[Computed]
    public function history()
    {
        return ScheduledPost::whereNotIn('status', ['scheduled', 'sending'])->latest('updated_at')->limit(25)->get();
    }

    #[Computed]
    public function waTargets()
    {
        return WhatsappTarget::orderByDesc('is_default')->orderBy('name')->get();
    }

    public function facebookEnabled(): bool
    {
        return app(FacebookPage::class)->enabled();
    }

    public function newPost(): void
    {
        $this->reset('editingId', 'body', 'image', 'existingImage', 'removeImage', 'toFacebook', 'repeat', 'template');
        $next = CarbonImmutable::now($this->tz())->addHour()->startOfHour();
        $this->date = $next->toDateString();
        $this->time = $next->format('H:i');
        $this->targets = WhatsappTarget::where('is_default', true)->pluck('id')->all();
        $this->resetValidation();
        Flux::modal('post-form')->show();
    }

    public function edit(int $id, bool $copy = false): void
    {
        $post = ScheduledPost::findOrFail($id);
        $local = $post->localTime();

        $this->editingId = $copy ? null : $post->id;
        $this->body = $post->body;
        $this->image = null;
        $this->existingImage = $post->image_path;
        $this->removeImage = false;
        $this->targets = $post->whatsapp_target_ids ?? [];
        $this->toFacebook = $post->to_facebook;
        $this->repeat = $post->repeat;
        $this->template = '';
        $later = $copy ? CarbonImmutable::now($this->tz())->addHour()->startOfHour() : $local;
        $this->date = $later->toDateString();
        $this->time = $later->format('H:i');
        $this->resetValidation();
        Flux::modal('post-form')->show();
    }

    /** "Start from": fill the text from an existing message template. */
    public function updatedTemplate(string $value): void
    {
        $t = app(MessageTemplates::class);
        $now = CarbonImmutable::now($this->tz());

        $text = match ($value) {
            'roster-this' => $t->roster(WorshipReports::monthStart($now->format('Y-m'))),
            'roster-next' => $t->roster(WorshipReports::monthStart($now->addMonthNoOverflow()->format('Y-m'))),
            'uniforms-this' => $t->uniforms(WorshipReports::monthStart($now->format('Y-m'))),
            'uniforms-next' => $t->uniforms(WorshipReports::monthStart($now->addMonthNoOverflow()->format('Y-m'))),
            'contributions' => $t->contributionsSummary($now->year),
            default => null,
        };

        if ($text !== null) {
            $this->body = $text;
        }
    }

    public function save(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:6000'],
            'image' => ['nullable', 'image', 'max:5120'],
            'targets' => ['array'],
            'targets.*' => ['integer', 'exists:whatsapp_targets,id'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'repeat' => ['required', Rule::in(array_keys(ScheduledPost::REPEATS))],
        ]);

        if (! $this->targets && ! ($this->toFacebook && $this->facebookEnabled())) {
            $this->addError('targets', 'Choose at least one destination.');

            return;
        }

        $when = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->date.' '.$this->time, $this->tz());
        if ($when->lt(now()->subMinute())) {
            $this->addError('time', 'That time has already passed. Use “Send now” to post immediately.');

            return;
        }

        $imagePath = $this->removeImage ? null : $this->existingImage;
        if ($this->image) {
            $imagePath = $this->image->store('scheduled-posts', 'public');
        }

        ScheduledPost::updateOrCreate(['id' => $this->editingId], [
            'body' => trim($this->body),
            'image_path' => $imagePath,
            'whatsapp_target_ids' => array_values(array_map('intval', $this->targets)),
            'to_facebook' => $this->toFacebook && $this->facebookEnabled(),
            'scheduled_at' => $when->utc(),
            'repeat' => $this->repeat,
            'status' => 'scheduled',
            'user_id' => $this->editingId ? ScheduledPost::find($this->editingId)?->user_id : auth()->id(),
        ]);

        Flux::modal('post-form')->close();
        Flux::toast(variant: 'success', text: 'Scheduled for '.$when->format('D j M, H:i').'.');
        unset($this->upcoming);
    }

    public function sendNow(int $id): void
    {
        $post = ScheduledPost::findOrFail($id);
        $post->update(['scheduled_at' => now()]);
        app(PostPublisher::class)->publish($post);
        $post->refresh();

        Flux::toast(
            variant: match ($post->status) { 'sent' => 'success', 'partial' => 'warning', default => 'danger' },
            text: 'Posted: '.collect($post->results)->where('ok', true)->count().' of '.count($post->results ?? []).' destinations.',
        );
        unset($this->upcoming, $this->history);
    }

    public function cancel(int $id): void
    {
        ScheduledPost::where('status', 'scheduled')->findOrFail($id)->update(['status' => 'cancelled']);
        unset($this->upcoming, $this->history);
        Flux::toast(text: 'Post cancelled.');
    }

    public function delete(int $id): void
    {
        $post = ScheduledPost::whereNotIn('status', ['sending'])->findOrFail($id);
        if ($post->image_path && ! ScheduledPost::where('image_path', $post->image_path)->whereKeyNot($post->id)->exists()) {
            Storage::disk('public')->delete($post->image_path);
        }
        $post->delete();
        unset($this->history, $this->upcoming);
    }

    public function targetNames(ScheduledPost $post): string
    {
        $names = WhatsappTarget::whereIn('id', $post->whatsapp_target_ids ?? [])->pluck('name')->all();
        if ($post->to_facebook) {
            $names[] = 'Facebook Page';
        }

        return $names ? implode(', ', $names) : 'no destinations';
    }
};
?>

<div class="flex flex-col gap-6" wire:poll.30s>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Post scheduler</flux:heading>
            <flux:subheading>Write once, post to WhatsApp groups &amp; channels{{ $this->facebookEnabled() ? ' and the Facebook Page' : '' }} at the time you choose ({{ config('worship.timezone') }} time).</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newPost">New post</flux:button>
    </div>

    @if ($this->waTargets->isEmpty() && ! $this->facebookEnabled())
        <flux:callout variant="warning" icon="exclamation-triangle" heading="No destinations yet" text="Link WhatsApp and add groups/channels (Admin → WhatsApp), or connect the Facebook Page, before scheduling posts." />
    @endif

    <div class="space-y-3">
        <flux:heading size="lg">Upcoming</flux:heading>
        @forelse ($this->upcoming as $post)
            <flux:card class="flex flex-wrap items-start gap-4 p-4!" wire:key="up-{{ $post->id }}">
                <div class="w-24 shrink-0 rounded-lg bg-indigo-50 p-2 text-center dark:bg-indigo-500/10">
                    <div class="text-xs uppercase text-indigo-600 dark:text-indigo-300">{{ $post->localTime()->format('D') }}</div>
                    <div class="text-lg font-semibold">{{ $post->localTime()->format('j M') }}</div>
                    <div class="text-sm">{{ $post->localTime()->format('H:i') }}</div>
                </div>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <flux:badge size="sm" :color="$post->statusColor()">{{ ucfirst($post->status) }}</flux:badge>
                        @if ($post->repeat !== 'none')<flux:badge size="sm" icon="arrow-path">{{ \App\Models\ScheduledPost::REPEATS[$post->repeat] }}</flux:badge>@endif
                        <flux:text size="sm" class="truncate">→ {{ $this->targetNames($post) }}</flux:text>
                    </div>
                    <div class="line-clamp-3 whitespace-pre-line text-sm">{{ $post->body }}</div>
                </div>
                @if ($post->imageUrl())
                    <img src="{{ $post->imageUrl() }}" alt="" class="size-16 rounded-lg object-cover">
                @endif
                @if ($post->status === 'scheduled')
                    <div class="flex gap-1">
                        <flux:button size="sm" icon="paper-airplane" wire:click="sendNow({{ $post->id }})" wire:confirm="Post this now?">Send now</flux:button>
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="Actions" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $post->id }})">Edit</flux:menu.item>
                                <flux:menu.item icon="document-duplicate" wire:click="edit({{ $post->id }}, true)">Duplicate</flux:menu.item>
                                <flux:menu.item icon="x-circle" variant="danger" wire:click="cancel({{ $post->id }})" wire:confirm="Cancel this post?">Cancel</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                @endif
            </flux:card>
        @empty
            <flux:card class="py-8 text-center"><flux:text>Nothing scheduled. Press “New post”.</flux:text></flux:card>
        @endforelse
    </div>

    @if ($this->history->isNotEmpty())
        <div class="space-y-3">
            <flux:heading size="lg">History</flux:heading>
            @foreach ($this->history as $post)
                <flux:card class="space-y-2 p-4!" wire:key="past-{{ $post->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <flux:badge size="sm" :color="$post->statusColor()">{{ ucfirst($post->status) }}</flux:badge>
                            <flux:text size="sm">{{ ($post->sent_at ?? $post->scheduled_at)->copy()->setTimezone(config('worship.timezone'))->format('D j M Y, H:i') }}</flux:text>
                        </div>
                        <div class="flex gap-1">
                            <flux:button size="xs" variant="ghost" icon="document-duplicate" wire:click="edit({{ $post->id }}, true)">Post again</flux:button>
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $post->id }})" wire:confirm="Delete this post from the history?" aria-label="Delete" />
                        </div>
                    </div>
                    <div class="line-clamp-2 whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{{ $post->body }}</div>
                    @if ($post->results)
                        <div class="flex flex-wrap gap-2">
                            @foreach ($post->results as $r)
                                <flux:badge size="sm" :color="$r['ok'] ? 'green' : 'red'" :icon="$r['ok'] ? 'check' : 'x-mark'" :title="$r['error'] ?? ''">{{ $r['name'] }}{{ $r['ok'] ? '' : ': '.Str::limit((string) $r['error'], 60) }}</flux:badge>
                            @endforeach
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:modal name="post-form" class="w-full md:w-2xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit post' : 'New post' }}</flux:heading>

            <flux:select wire:model.live="template" label="Start from (optional)">
                <flux:select.option value="">Blank</flux:select.option>
                <flux:select.option value="roster-this">Roster: this month</flux:select.option>
                <flux:select.option value="roster-next">Roster: next month</flux:select.option>
                <flux:select.option value="uniforms-this">Uniform timetable: this month</flux:select.option>
                <flux:select.option value="uniforms-next">Uniform timetable: next month</flux:select.option>
                <flux:select.option value="contributions">Contributions update (totals only)</flux:select.option>
            </flux:select>

            <flux:textarea wire:model="body" label="Post" rows="9" description="WhatsApp formatting works: *bold*, _italic_." />

            <flux:field>
                <flux:label>Image (optional)</flux:label>
                <input type="file" wire:model="image" accept="image/*" class="text-sm">
                <flux:error name="image" />
                @if ($image)
                    <img src="{{ $image->temporaryUrl() }}" alt="" class="mt-2 h-24 rounded-lg object-cover">
                @elseif ($existingImage && ! $removeImage)
                    <div class="mt-2 flex items-center gap-2">
                        <img src="{{ Storage::disk('public')->url($existingImage) }}" alt="" class="h-24 rounded-lg object-cover">
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="$set('removeImage', true)">Remove</flux:button>
                    </div>
                @endif
            </flux:field>

            <flux:field>
                <flux:label>Post to</flux:label>
                <div class="space-y-1.5">
                    @foreach ($this->waTargets as $t)
                        <label class="flex items-center gap-2 text-sm" wire:key="pt-{{ $t->id }}">
                            <input type="checkbox" wire:model="targets" value="{{ $t->id }}" class="rounded"> {{ $t->name }}
                            <span class="text-xs text-zinc-400">WhatsApp {{ $t->isChannel() ? 'channel' : 'group' }}</span>
                        </label>
                    @endforeach
                    @if ($this->facebookEnabled())
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="toFacebook" class="rounded"> Facebook Page</label>
                    @endif
                </div>
                <flux:error name="targets" />
            </flux:field>

            <div class="grid grid-cols-3 gap-3">
                <flux:input wire:model="date" type="date" label="Date" />
                <flux:input wire:model="time" type="time" label="Time" />
                <flux:select wire:model="repeat" label="Repeat">
                    @foreach (\App\Models\ScheduledPost::REPEATS as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="clock">{{ $editingId ? 'Save' : 'Schedule' }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
