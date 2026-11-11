@extends('exports.layout')

@php
    $byStatus = $meeting->attendances->sortBy(fn ($a) => $a->member->name ?? '')->groupBy('status');
    $present = ($byStatus['present'] ?? collect())->count() + ($byStatus['late'] ?? collect())->count();
@endphp

@section('styles')
    <style>
        .meta td { padding: 3px 0; border: 0; }
        .meta .k { color: #64748b; width: 18%; }
        .box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; white-space: pre-line; }
        .names { line-height: 1.6; }
        .done { color: #166534; }
    </style>
@endsection

@section('stats')
    @if ($meeting->attendances->isNotEmpty())
        <span class="stat"><div class="n">{{ $present }}/{{ $meeting->attendances->count() }}</div><div class="l">Present</div></span>
    @endif
@endsection

@section('content')
    <table class="meta">
        <tr><td class="k">Status</td><td class="strong">{{ ucfirst($meeting->status) }}</td><td class="k">Time</td><td>{{ $meeting->timeLabel() ?: '–' }}</td></tr>
        <tr><td class="k">Venue</td><td>{{ $meeting->venue ?: '–' }}</td><td class="k">Chaired by</td><td>{{ $meeting->chair->name ?? '–' }}</td></tr>
        <tr><td class="k"></td><td></td><td class="k">Minutes by</td><td>{{ $meeting->minuteTaker->name ?? '–' }}</td></tr>
    </table>

    @if ($meeting->agenda)
        <h2>Agenda</h2>
        <div class="box">{{ $meeting->agenda }}</div>
    @endif

    <h2>Attendance <span class="count">· {{ $present }} present of {{ $meeting->attendances->count() }} recorded</span></h2>
    @if ($meeting->attendances->isEmpty())
        <div class="empty">No attendance recorded.</div>
    @else
        <table class="grid">
            @foreach (\App\Models\Meeting::ATTENDANCE as $status => $label)
                @if (isset($byStatus[$status]))
                    <tr>
                        <td style="width: 16%" class="strong">{{ $label }} ({{ $byStatus[$status]->count() }})</td>
                        <td class="names">{{ $byStatus[$status]->map(fn ($a) => ($a->member->name ?? '?').($a->note ? ' ('.$a->note.')' : ''))->implode(', ') }}</td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endif

    <h2>Minutes</h2>
    @if ($meeting->minutes)
        <div class="box">{{ $meeting->minutes }}</div>
    @else
        <div class="empty">No minutes written yet.</div>
    @endif

    @if ($meeting->actions->isNotEmpty())
        <h2>Action items</h2>
        <table class="grid">
            <thead><tr><th>Action</th><th style="width: 22%">Who</th><th style="width: 14%">Due</th><th style="width: 12%">Status</th></tr></thead>
            <tbody>
                @foreach ($meeting->actions as $a)
                    <tr>
                        <td>{{ $a->description }}</td>
                        <td>{{ $a->member->name ?? '–' }}</td>
                        <td class="nowrap">{{ $a->due_date?->format('j M Y') ?? '–' }}</td>
                        <td>{!! $a->done_at ? '<span class="done strong">Done</span>' : 'Open' !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
