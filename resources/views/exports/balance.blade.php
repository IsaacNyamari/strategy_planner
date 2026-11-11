@extends('exports.layout')

@php $money = fn ($v) => \App\Contributions\ContributionLedger::money($v); @endphp

@section('styles')
    <style>
        table.summary { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px 4px; }
        table.summary td { border: 1px solid #e2e8f0; border-radius: 6px; padding: 9px 12px; width: 25%; }
        table.summary .k { font-size: 7.5px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; }
        table.summary .v { font-size: 14px; font-weight: bold; color: #1e1b4b; margin-top: 2px; }
        .in { color: #166534; }
        .out { color: #b91c1c; }
        .num { text-align: right; white-space: nowrap; }
        tr.total td { background: #f1f5f9; font-weight: bold; }
        table.two { width: 100%; border-collapse: collapse; }
        table.two > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; border: 0; }
    </style>
@endsection

@section('stats')
    <span class="stat"><div class="n">{{ number_format($summary['closing']) }}</div><div class="l">Balance</div></span>
@endsection

@section('content')
    <table class="summary">
        <tr>
            <td><div class="k">Brought forward</div><div class="v">{{ $money($summary['opening']) }}</div></td>
            <td><div class="k">Money in</div><div class="v in">+ {{ $money($summary['totalIn']) }}</div></td>
            <td><div class="k">Money out</div><div class="v out">− {{ $money($summary['totalOut']) }}</div></td>
            <td><div class="k">Balance now</div><div class="v">{{ $money($summary['closing']) }}</div></td>
        </tr>
    </table>

    <table class="two">
        <tr>
            <td style="padding-right: 8px">
                <h2>Money in</h2>
                <table class="grid">
                    @forelse ($summary['income'] as $line)
                        <tr><td>{{ $line['label'] }}</td><td class="num in">{{ $money($line['amount']) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">Nothing received.</td></tr>
                    @endforelse
                    <tr class="total"><td>Total in</td><td class="num in">{{ $money($summary['totalIn']) }}</td></tr>
                </table>
            </td>
            <td style="padding-left: 8px">
                <h2>Money out</h2>
                <table class="grid">
                    @forelse ($summary['expenses'] as $line)
                        <tr><td>{{ $line['label'] }}</td><td class="num out">{{ $money($line['amount']) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">Nothing spent.</td></tr>
                    @endforelse
                    <tr class="total"><td>Total out</td><td class="num out">{{ $money($summary['totalOut']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin: 10px 0 0" class="small">
        {{ $money($summary['opening']) }} brought forward + {{ $money($summary['totalIn']) }} in − {{ $money($summary['totalOut']) }} out =
        <b>{{ $money($summary['closing']) }}</b> in hand.
    </p>

    @if ($funds)
        <h2>Arising contribution funds <span class="count">· collected vs spent</span></h2>
        <table class="grid">
            <thead><tr><th>Fund</th><th class="num">Collected</th><th class="num">Spent</th><th class="num">Left</th></tr></thead>
            <tbody>
                @foreach ($funds as $f)
                    <tr><td class="strong">{{ $f['title'] }}</td><td class="num">{{ $money($f['collected']) }}</td><td class="num">{{ $money($f['spent']) }}</td><td class="num strong">{{ $money($f['balance']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="page-break">Cash book <span class="count">· every entry, {{ $entries->count() }}</span></h2>
    @if ($entries->isEmpty())
        <div class="empty">No money in or out in this period.</div>
    @else
        <table class="grid">
            <thead><tr><th style="width: 11%">Date</th><th>Details</th><th style="width: 13%" class="num">In</th><th style="width: 13%" class="num">Out</th><th style="width: 14%" class="num">Balance</th></tr></thead>
            <tbody>
                <tr><td></td><td class="muted">Brought forward</td><td></td><td></td><td class="num">{{ number_format($summary['opening']) }}</td></tr>
                @foreach ($entries as $e)
                    <tr>
                        <td class="nowrap">{{ $e['date']->format('j M') }}</td>
                        <td>
                            <span class="strong">{{ $e['description'] }}</span>
                            <div class="small muted">{{ $e['category'] }}{{ $e['party'] ? ' · '.$e['party'] : '' }} · {{ $e['method'] }}{{ $e['reference'] ? ' '.$e['reference'] : '' }}{{ $e['receipt'] ? ' · '.$e['receipt'] : '' }}</div>
                        </td>
                        <td class="num in">{{ $e['in'] ? number_format($e['amount']) : '' }}</td>
                        <td class="num out">{{ $e['in'] ? '' : number_format($e['amount']) }}</td>
                        <td class="num strong">{{ number_format($e['balance']) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td></td><td>Totals</td><td class="num in">{{ number_format($summary['totalIn']) }}</td><td class="num out">{{ number_format($summary['totalOut']) }}</td><td class="num">{{ number_format($summary['closing']) }}</td></tr>
            </tbody>
        </table>
    @endif
@endsection
