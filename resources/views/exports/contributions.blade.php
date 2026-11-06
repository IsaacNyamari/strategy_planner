@extends('exports.layout')

@php
    $cur = \App\Contributions\ContributionLedger::currency();
    $money = fn ($v) => \App\Contributions\ContributionLedger::money($v);
    $rate = $grid['rate'];
@endphp

@section('styles')
    <style>
        table.grid td, table.grid th { padding: 4px 3px; }
        table.grid th { text-align: center; }
        table.grid th.left, table.grid td.left { text-align: left; padding-left: 7px; }
        td.c { text-align: center; font-size: 8px; }
        td.num { text-align: right; padding-right: 7px; }
        .s-paid { background: #dcfce7; color: #166534; font-weight: bold; }
        .s-partial { background: #fef3c7; color: #92400e; }
        .s-due { background: #fee2e2; color: #b91c1c; }
        .s-upcoming { color: #cbd5e1; }
        .s-na { color: #e2e8f0; }
        tfoot td { background: #f1f5f9; font-weight: bold; font-size: 8px; border-top: 1.5px solid #cbd5e1; }
        .legend span { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 7.5px; margin-right: 4px; }
    </style>
@endsection

@section('stats')
    <span class="stat"><div class="n">{{ number_format($totals['total']) }}</div><div class="l">{{ $cur }} received</div></span>
    <span class="stat"><div class="n">{{ number_format($grid['totals']['arrears']) }}</div><div class="l">{{ $cur }} arrears</div></span>
    <span class="stat"><div class="n">{{ count($grid['rows']) }}</div><div class="l">Members</div></span>
@endsection

@section('content')
    <table style="width: 100%; margin-bottom: 8px">
        <tr>
            <td style="border: 0; padding: 0" class="muted">
                Monthly contribution <b>{{ $money($rate->monthly_amount) }}</b> · Registration fee <b>{{ $money($rate->registration_fee) }}</b> (once a year) · amounts in {{ $cur }}
            </td>
            <td style="border: 0; padding: 0; text-align: right" class="legend">
                <span class="s-paid">✓ paid</span><span class="s-partial">partial</span><span class="s-due">• due</span><span class="s-upcoming" style="border: 1px solid #e2e8f0">upcoming</span>
            </td>
        </tr>
    </table>

    @if (empty($grid['rows']))
        <div class="empty">No current members.</div>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th class="left" style="width: 16%">Member</th>
                    <th style="width: 6%">Reg.</th>
                    @foreach (range(1, 12) as $m)<th>{{ \App\Contributions\ContributionLedger::monthName($m, 'M') }}</th>@endforeach
                    <th style="width: 8%">Paid</th>
                    <th style="width: 8%">Arrears</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($grid['rows'] as $row)
                    @php $reg = $row['registration_status']; @endphp
                    <tr>
                        <td class="left strong">{{ $row['member']->name }}</td>
                        <td class="c s-{{ $reg === 'n/a' ? 'na' : $reg }}">{{ match ($reg) { 'paid' => '✓', 'partial' => number_format($row['registration_paid']), 'due' => '•', default => '' } }}</td>
                        @foreach ($row['months'] as $cell)
                            @php $s = $cell['status']; @endphp
                            <td class="c s-{{ $s === 'n/a' ? 'na' : $s }}">{{ match ($s) { 'paid' => '✓', 'partial' => number_format($cell['paid']), 'due' => '•', default => '' } }}</td>
                        @endforeach
                        <td class="num">{{ number_format($row['paid']) }}</td>
                        <td class="num" style="{{ $row['arrears'] > 0 ? 'color: #b91c1c; font-weight: bold' : 'color: #94a3b8' }}">{{ number_format($row['arrears']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="left">Total</td>
                    <td class="c">{{ number_format($grid['totals']['registration_paid']) }}</td>
                    @foreach ($grid['monthTotals'] as $t)<td class="c">{{ $t ? number_format($t) : '' }}</td>@endforeach
                    <td class="num">{{ number_format($grid['totals']['paid']) }}</td>
                    <td class="num" style="color: #b91c1c">{{ number_format($grid['totals']['arrears']) }}</td>
                </tr>
            </tfoot>
        </table>

        <div style="page-break-inside: avoid">
            <h2>Received in {{ $year }} by type</h2>
            <table class="grid" style="width: 50%">
                <thead><tr><th class="left">Type</th><th style="text-align: right; padding-right: 7px">Amount</th></tr></thead>
                <tbody>
                    @foreach (\App\Enums\PaymentType::cases() as $t)
                        <tr><td class="left">{{ $t->label() }}</td><td class="num">{{ $money($totals['byType'][$t->value] ?? 0) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td class="left">Total</td><td class="num">{{ $money($totals['total']) }}</td></tr></tfoot>
            </table>
        </div>
    @endif
@endsection
