@extends('exports.layout')

@php $money = fn ($v) => \App\Contributions\ContributionLedger::money($v); @endphp

@section('styles')
    <style>
        .receipt { border: 1px solid #c7d2fe; border-radius: 8px; padding: 14px 18px; margin-bottom: 14px; page-break-inside: avoid; }
        .receipt table { width: 100%; border-collapse: collapse; }
        .receipt td { padding: 3px 0; vertical-align: top; }
        .receipt .no { font-size: 13px; font-weight: bold; color: #1e1b4b; }
        .receipt .k { color: #64748b; width: 32%; }
        .receipt .amount { font-size: 20px; font-weight: bold; color: #166534; text-align: right; }
        .receipt .paid { display: inline-block; border: 1.5px solid #16a34a; color: #16a34a; border-radius: 4px; padding: 1px 8px; font-size: 9px; font-weight: bold; letter-spacing: 1px; }
        .receipt .thanks { margin-top: 8px; padding-top: 6px; border-top: 1px dashed #cbd5e1; color: #64748b; font-size: 8px; }
    </style>
@endsection

@section('stats')
    @if ($payments->count() > 1)
        <span class="stat"><div class="n">{{ $payments->count() }}</div><div class="l">Receipts</div></span>
        <span class="stat"><div class="n">{{ number_format($payments->sum('amount')) }}</div><div class="l">{{ \App\Contributions\ContributionLedger::currency() }}</div></span>
    @endif
@endsection

@section('content')
    @foreach ($payments as $p)
        <div class="receipt">
            <table>
                <tr>
                    <td><div class="no">Receipt {{ \App\Finance\BalanceSheet::receiptNumber($p) }}</div><div class="muted">{{ $p->paid_on->format('l, j F Y') }}</div></td>
                    <td class="amount">{{ $money($p->amount) }}<br><span class="paid">PAID</span></td>
                </tr>
            </table>
            <table style="margin-top: 8px">
                <tr><td class="k">Received from</td><td class="strong">{{ $p->member->name ?? 'Member' }}</td></tr>
                <tr><td class="k">For</td><td>{{ $p->type->label() }} — {{ $p->forLabel() }}</td></tr>
                <tr><td class="k">Paid by</td><td>{{ $p->method->label() }}{{ $p->reference ? ' · Ref '.$p->reference : '' }}</td></tr>
                @if ($p->notes)
                    <tr><td class="k">Notes</td><td>{{ $p->notes }}</td></tr>
                @endif
            </table>
            <div class="thanks">Thank you for supporting {{ config('worship.team_name') }}. This receipt was generated from the team's records.</div>
        </div>
    @endforeach
@endsection
