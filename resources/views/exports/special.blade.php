@extends('exports.layout')

@php
    $money = fn ($v) => \App\Contributions\ContributionLedger::money($v);
    $c = $status['contribution'];
    $pct = $status['expected'] > 0 ? min(100, round($status['collected'] / $status['expected'] * 100)) : 0;
@endphp

@section('styles')
    <style>
        .s-paid { background: #dcfce7; color: #166534; }
        .s-partial { background: #fef3c7; color: #92400e; }
        .s-due { background: #fee2e2; color: #b91c1c; }
        .bar { background: #e0e7ff; border-radius: 4px; height: 9px; }
        .bar div { background: #6366f1; border-radius: 4px; height: 9px; }
    </style>
@endsection

@section('stats')
    <span class="stat"><div class="n">{{ number_format($status['collected']) }}</div><div class="l">Collected</div></span>
    <span class="stat"><div class="n">{{ $status['paid_count'] }}/{{ count($status['rows']) }}</div><div class="l">Paid</div></span>
@endsection

@section('content')
    @if ($c->description)<p class="muted" style="margin: 0 0 8px">{{ $c->description }}</p>@endif
    <p style="margin: 0 0 6px">
        {{ $c->amount_per_member ? $money($c->amount_per_member).' per member' : 'Any amount' }}
        @if ($status['expected'] > 0) · <b>{{ $money($status['collected']) }}</b> of {{ $money($status['expected']) }} ({{ $pct }}%) @endif
    </p>
    @if ($status['expected'] > 0)
        <div class="bar" style="margin-bottom: 14px"><div style="width: {{ $pct }}%"></div></div>
    @endif

    <table class="grid">
        <thead>
            <tr><th style="width: 5%">#</th><th>Member</th><th style="width: 16%; text-align: right">Expected</th><th style="width: 16%; text-align: right">Paid</th><th style="width: 16%; text-align: right">Balance</th><th style="width: 12%">Status</th></tr>
        </thead>
        <tbody>
            @foreach ($status['rows'] as $i => $row)
                <tr>
                    <td class="muted">{{ $i + 1 }}</td>
                    <td class="strong">{{ $row['member']->name }}</td>
                    <td style="text-align: right">{{ $row['expected'] > 0 ? $money($row['expected']) : '–' }}</td>
                    <td style="text-align: right" class="strong">{{ $money($row['paid']) }}</td>
                    <td style="text-align: right; {{ $row['balance'] > 0 ? 'color: #b91c1c' : 'color: #94a3b8' }}">{{ $money($row['balance']) }}</td>
                    <td><span class="badge s-{{ $row['status'] === 'n/a' ? 'due' : $row['status'] }}">{{ match ($row['status']) { 'paid' => 'Paid', 'partial' => 'Partial', default => 'Not paid' } }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
