@extends('exports.layout')

@section('stats')
    <span class="stat"><div class="n">{{ $stats['services'] }}</div><div class="l">Services</div></span>
    @if ($stats['specials'])
        <span class="stat"><div class="n">{{ $stats['specials'] }}</div><div class="l">Special</div></span>
    @endif
    <span class="stat"><div class="n">{{ $stats['ministers'] }}</div><div class="l">Ministers</div></span>
@endsection

@section('styles')
    <style>
        td.lead-praise { border-left: 3px solid #f59e0b; }
        td.lead-worship { border-left: 3px solid #8b5cf6; }
    </style>
@endsection

@section('content')
    @if (empty($rows))
        <div class="empty">No services have been rostered for {{ $subtitle }} yet.</div>
    @else
        @php $cols = [['7%', 'Date'], ['17%', 'Service'], ['14%', 'Praise leader'], ['14%', 'Worship leader'], ['25%', 'Backups'], ['11%', 'Uniform'], ['12%', 'Songs']]; @endphp
        <table class="grid fixed">
            <tr>@foreach ($cols as [$w, $label])<th style="width: {{ $w }}">{{ $label }}</th>@endforeach</tr>
        </table>
        @foreach (collect($rows)->groupBy('week') as $week => $services)
            <div class="week-block">
            <div class="week">{{ $week }}</div>
            <table class="grid fixed">
                <tr class="sizer">@foreach ($cols as [$w])<td style="width: {{ $w }}"></td>@endforeach</tr>
                    @foreach ($services as $row)
                        <tr @class(['special' => $row['type'] === 'special'])>
                            <td>
                                <div class="tile">
                                    <div class="m">{{ $row['month_short'] }}</div>
                                    <div class="d">{{ $row['day_num'] }}</div>
                                    <div class="w">{{ substr($row['day'], 0, 3) }}</div>
                                </div>
                            </td>
                            <td>
                                <span class="badge b-{{ $row['type'] }}">{{ $row['type_label'] }}</span>
                                <div class="strong" style="margin-top: 3px">{{ $row['service'] }}</div>
                                <div class="muted small">{{ $row['time'] }}</div>
                            </td>
                            <td class="lead-praise">
                                <span class="strong">{{ $row['praise_lead'] }}</span>
                                @if ($row['praise_part'])<div class="muted small">{{ $row['praise_part'] }}</div>@endif
                            </td>
                            <td class="lead-worship">
                                <span class="strong">{{ $row['worship_lead'] }}</span>
                                @if ($row['worship_part'])<div class="muted small">{{ $row['worship_part'] }}</div>@endif
                            </td>
                            <td>{{ str_replace(', ', '  ·  ', $row['backups']) }}</td>
                            <td>{{ $row['uniform'] }}</td>
                            <td class="muted small">{{ $row['songs'] }}</td>
                        </tr>
                    @endforeach
            </table>
            </div>
        @endforeach

        @if (! empty($summary))
            @php $max = max(array_column($summary, 'total')) ?: 1; @endphp
            <div style="page-break-inside: avoid">
                <h2>Duties this month <span class="count">· {{ count($summary) }} ministers</span></h2>
                <table class="grid" style="width: 70%">
                    <thead>
                        <tr><th>Minister</th><th>Part</th><th style="width: 9%">Praise</th><th style="width: 9%">Worship</th><th style="width: 9%">Backups</th><th style="width: 30%">Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($summary as $s)
                            <tr>
                                <td class="strong">{{ $s['name'] }}</td>
                                <td class="muted">{{ $s['part'] }}</td>
                                <td>@if ($s['praise'])<span class="badge b-praise">{{ $s['praise'] }}</span>@else<span class="muted">–</span>@endif</td>
                                <td>@if ($s['worship'])<span class="badge b-worship">{{ $s['worship'] }}</span>@else<span class="muted">–</span>@endif</td>
                                <td>{{ $s['backups'] }}</td>
                                <td>
                                    <div style="background: #e0e7ff; border-radius: 3px; height: 7px; width: 80%; display: inline-block; vertical-align: middle">
                                        <div style="background: #6366f1; border-radius: 3px; height: 7px; width: {{ round($s['total'] / $max * 100) }}%"></div>
                                    </div>
                                    <span class="strong" style="padding-left: 4px">{{ $s['total'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
