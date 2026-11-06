<?php

use App\Models\Ministration;
use App\Models\SpecialContribution;
use App\Models\WhatsappTarget;
use App\Reports\WorshipReports;
use App\WhatsApp\Attachments;
use App\WhatsApp\MessageTemplates;
use App\WhatsApp\WhatsAppSender;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Send to WhatsApp" dialog. Open it from anywhere with:
 *   $dispatch('whatsapp-share', { type: 'roster', month: '2026-12' })
 *   types: roster{month} | uniforms{month} | meeting{id} | arising{id} | contributions{year} | announcement{month,text}
 * The message is built server-side, can be edited, then sent to the chosen groups/channels.
 */
new class extends Component {
    public string $type = '';
    public array $params = [];
    public string $title = '';
    public string $text = '';
    public bool $attach = false;
    public bool $canAttach = false;
    /** @var list<int> */
    public array $selected = [];
    /** @var list<array{name: string, status: string, error: ?string}> */
    public array $results = [];

    #[On('whatsapp-share')]
    public function open(string $type, ?string $month = null, ?int $id = null, ?int $year = null, ?string $text = null): void
    {
        $this->authorize('send-whatsapp');

        $this->reset('results');
        $this->type = $type;
        $this->params = array_filter(['month' => $month, 'id' => $id, 'year' => $year], fn ($v) => $v !== null);
        $templates = app(MessageTemplates::class);
        $monthStart = WorshipReports::monthStart($month);

        [$this->title, $this->text, $this->canAttach] = match ($type) {
            'roster' => ['Roster '.$monthStart->format('F Y'), $templates->roster($monthStart), true],
            'announcement' => ['Roster announcement '.$monthStart->format('F Y'), (string) $text, true],
            'uniforms' => ['Uniform timetable '.$monthStart->format('F Y'), $templates->uniforms($monthStart), true],
            'meeting' => ['Service / meeting', $templates->meeting(Ministration::findOrFail($id)), false],
            // Text only: the arising-contribution PDF lists each member's balance (private).
            'arising' => ['Arising contribution', $templates->arising(SpecialContribution::findOrFail($id)), false],
            'contributions' => ['Contributions update '.($year ?? now()->year), $templates->contributionsSummary($year ?? (int) now()->year), false],
            default => abort(404),
        };

        $this->attach = $this->canAttach;
        $this->selected = WhatsappTarget::where('is_default', true)->pluck('id')->all();

        Flux::modal('whatsapp-share')->show();
    }

    #[Computed]
    public function targets()
    {
        return WhatsappTarget::orderByDesc('is_default')->orderBy('name')->get();
    }

    public function send(): void
    {
        $this->authorize('send-whatsapp');

        $this->validate([
            'text' => ['required', 'string', 'max:6000'],
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['integer', 'exists:whatsapp_targets,id'],
        ], ['selected.required' => 'Choose at least one group or channel.']);

        $attachment = null;

        if ($this->attach && $this->canAttach) {
            $attachmentType = $this->type === 'announcement' ? 'roster' : $this->type;
            $attachment = app(Attachments::class)->make($attachmentType, $this->params);
        }

        $sent = app(WhatsAppSender::class)->send(array_map('intval', $this->selected), $this->text, $this->type, $attachment, auth()->user());

        $this->results = $sent->map(fn ($m) => ['name' => $m->target_name, 'status' => $m->status, 'error' => $m->error])->all();

        $failed = $sent->where('status', 'failed')->count();
        $failed
            ? Flux::toast(variant: 'warning', text: ($sent->count() - $failed).' sent, '.$failed.' failed.')
            : Flux::toast(variant: 'success', text: 'Sent to '.$sent->count().' WhatsApp '.Str::plural('chat', $sent->count()).'.');
    }
};
?>

<div>
    <flux:modal name="whatsapp-share" class="w-full md:w-2xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg" class="flex items-center gap-2">
                    <svg viewBox="0 0 24 24" class="size-5 fill-green-600" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.1-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5c.2-.2.2-.3.3-.5.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.1-.3-.2-.6-.3zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.3c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.3 8.3 0 1 1 12 20.3z"/></svg>
                    Send to WhatsApp
                </flux:heading>
                <flux:text class="mt-1">{{ $title }}</flux:text>
            </div>

            @if ($results)
                <div class="space-y-2">
                    @foreach ($results as $r)
                        <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                            <span class="text-sm font-medium">{{ $r['name'] }}</span>
                            <div class="text-end">
                                <flux:badge size="sm" :color="$r['status'] === 'failed' ? 'red' : 'green'" :icon="$r['status'] === 'failed' ? 'x-circle' : 'check-circle'">{{ $r['status'] === 'failed' ? 'Failed' : 'Sent' }}</flux:badge>
                                @if ($r['error'])<div class="mt-0.5 text-xs {{ $r['status'] === 'failed' ? 'text-red-600' : 'text-zinc-500' }}">{{ $r['error'] }}</div>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-end gap-2">
                    @can('manage-whatsapp')
                        <flux:button variant="ghost" :href="route('whatsapp')" wire:navigate>Message log</flux:button>
                    @endcan
                    <flux:modal.close><flux:button variant="primary">Done</flux:button></flux:modal.close>
                </div>
            @else
                <form wire:submit="send" class="space-y-5">
                    <flux:field>
                        <flux:label>Send to</flux:label>
                        <div class="space-y-1.5">
                            @foreach ($this->targets as $t)
                                <label class="flex items-center gap-2 text-sm" wire:key="share-target-{{ $t->id }}">
                                    <input type="checkbox" wire:model="selected" value="{{ $t->id }}" class="rounded">
                                    {{ $t->name }}
                                    <span class="text-xs text-zinc-400">{{ $t->isChannel() ? 'channel' : 'group' }}</span>
                                </label>
                            @endforeach
                        </div>
                        <flux:error name="selected" />
                    </flux:field>

                    <flux:textarea wire:model="text" label="Message" rows="12" description="WhatsApp formatting works: *bold*, _italic_." />

                    @if ($canAttach)
                        <flux:checkbox wire:model="attach" label="Attach the PDF" description="Sent to groups. Channels receive the text only." />
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary" icon="paper-airplane">
                            <span wire:loading.remove wire:target="send">Send</span>
                            <span wire:loading wire:target="send">Sending…</span>
                        </flux:button>
                    </div>
                </form>
            @endif
        </div>
    </flux:modal>
</div>
