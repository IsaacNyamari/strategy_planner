@extends('exports.layout')

@php
    $money = fn ($v) => \App\Contributions\ContributionLedger::money($v);
    $m = $st['member'];
    $label = ['paid' => 'Paid', 'partial' => 'Partly paid', 'due' => 'Due', 'upcoming' => 'Upcoming', 'n/a' => '–'];
@endphp

@section('styles')
    <style>
        .s-paid { background: #dcfce7; color: #166534; }
        .s-partial { background: #fef3c7; color: #92400e; }
        .s-due { background: #fee2e2; color: #b91c1c; }
        .s-upcoming { background: #f8fafc; color: #94a3b8; }
        .s-na { background: #fff; color: #cbd5e1; }
        table.months { width: 100%; border-collapse: separate; border-spacing: 3px; margin: 0 -3px; }
        table.months td { text-align: center; border-radius: 4px; padding: 5px 0; border: 0; }
        table.months .mn { font-size: 7.5px; text-transform: uppercase; letter-spacing: .5px; }
        table.months .amt { font-size: 9.5px; font-weight: bold; margin-top: 1px; }
        table.summary { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px 4px; }
        table.summary td { border: 1px solid #e2e8f0; border-radius: 6px; padding: 9px 12px; width: 33%; }
        table.summary .k { font-size: 7.5px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; }
        table.summary .v { font-size: 15px; font-weight: bold; color: #1e1b4b; margin-top: 2px; }
    </style>
@endsection

@section('stats')
    <span class="stat"><div class="n">{{ $m->status->label() }}</div><div class="l">Status</div></span>
@endsection

@section('content')
    <p class="muted" style="margin: 0 0 10px">
        {{ $m->part ?: 'Member' }} · joined {{ $m->joined_at?->format('j M Y') ?? '–' }}{{ $m->phone ? ' · '.$m->phoneDisplay() : '' }}
    </p>

    <table class="summary">
        <tr>
            <td><div class="k">Expected in {{ $st['year'] }} (to date)</div><div class="v">{{ $money($st['totals']['expected']) }}</div></td>
            <td><div class="k">Paid</div><div class="v" style="color: #166534">{{ $money($st['totals']['paid']) }}</div></td>
            <td><div class="k">Outstanding</div><div class="v" style="{{ $st['totals']['balance'] > 0 ? 'color: #b91c1c' : '' }}">{{ $money($st['totals']['balance']) }}</div></td>
        </tr>
    </table>

    <h2>Monthly contributions <span class="count">· {{ $money($st['rate']->monthly_amount) }} per month</span></h2>
    <table class="months">
        <tr>
            @foreach ($st['months'] as $mn => $cell)
                @php $s = $cell['status'] === 'n/a' ? 'na' : $cell['status']; @endphp
                <td class="s-{{ $s }}">
                    <div class="mn">{{ \App\Contributions\ContributionLedger::monthName($mn, 'M') }}</div>
                    <div class="amt">{{ $cell['paid'] > 0 ? number_format($cell['paid']) : ($cell['status'] === 'due' ? 'Due' : '–') }}</div>
                </td>
            @endforeach
        </tr>
    </table>
    <p class="small muted" style="margin: 6px 0 0">
        {{ $money($st['monthly']['paid']) }} paid · {{ $money($st['monthly']['arrears']) }} in arrears ·
        Registration fee {{ $st['year'] }}: <b>{{ $label[$st['registration']['status']] }}</b> ({{ $money($st['registration']['paid']) }}{{ $st['registration']['fee'] ? ' of '.$money($st['registration']['fee']) : '' }})
    </p>

    @if ($st['specials']->isNotEmpty())
        <h2>Arising contributions</h2>
        <table class="grid">
            <thead><tr><th>Contribution</th><th style="width: 18%; text-align: right">Expected</th><th style="width: 18%; text-align: right">Paid</th><th style="width: 15%">Status</th></tr></thead>
            <tbody>
                @foreach ($st['specials'] as $row)
                    @php $s = $row['status'] === 'n/a' ? 'na' : $row['status']; @endphp
                    <tr>
                        <td class="strong">{{ $row['contribution']->title }}</td>
                        <td style="text-align: right">{{ $row['expected'] > 0 ? $money($row['expected']) : 'Any amount' }}</td>
                        <td style="text-align: right">{{ $money($row['paid']) }}</td>
                        <td><span class="badge s-{{ $s }}">{{ $label[$row['status']] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Payments received in {{ $st['year'] }}</h2>
    @if ($st['payments']->isEmpty())
        <div class="empty">No payments recorded.</div>
    @else
        <table class="grid">
            <thead><tr><th style="width: 16%">Date</th><th>For</th><th style="width: 14%">Method</th><th style="width: 18%">Reference</th><th style="width: 16%; text-align: right">Amount</th></tr></thead>
            <tbody>
                @foreach ($st['payments'] as $p)
                    <tr>
                        <td class="nowrap">{{ $p->paid_on->format('j M Y') }}</td>
                        <td>{{ $p->forLabel() }}</td>
                        <td>{{ $p->method->label() }}</td>
                        <td class="muted">{{ $p->reference ?: '–' }}</td>
                        <td style="text-align: right" class="strong">{{ $money($p->amount) }}</td>
                    </tr>
                @endforeach
                <tr><td colspan="4" class="strong" style="text-align: right; background: #f1f5f9">Total</td><td class="strong" style="text-align: right; background: #f1f5f9">{{ $money($st['totals']['paid']) }}</td></tr>
            </tbody>
        </table>
    @endif
@endsection
