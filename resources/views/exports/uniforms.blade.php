@extends('exports.layout')

@section('styles')
    <style>
        table.cards { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px 6px; }
        table.cards td { vertical-align: top; width: 33.3%; padding: 0; }
        .card { border: 1px solid #e2e8f0; border-left: 4px solid #6366f1; border-radius: 6px; padding: 9px 11px; page-break-inside: avoid; }
        .card .name { font-size: 11px; font-weight: bold; color: #1e1b4b; }
        .card .uses { float: right; font-size: 7.5px; color: #4338ca; background: #eef2ff; border-radius: 8px; padding: 1px 6px; }
        .card table { width: 100%; margin-top: 5px; }
        .card table td { padding: 2px 0; border: 0; font-size: 8.5px; vertical-align: top; }
        .card .lbl { color: #64748b; width: 44px; font-size: 7.5px; text-transform: uppercase; letter-spacing: .4px; }
        .card .note { margin-top: 5px; font-size: 7.5px; color: #64748b; font-style: italic; }
    </style>
@endsection

@section('stats')
    <span class="stat"><div class="n">{{ count($rows) }}</div><div class="l">Services</div></span>
    <span class="stat"><div class="n">{{ $uniforms->count() }}</div><div class="l">Uniforms</div></span>
@endsection

@section('content')
    @if (empty($rows))
        <div class="empty">No services have been scheduled for {{ $subtitle }} yet.</div>
    @else
        @if ($uniforms->isNotEmpty())
            <h2>What to wear <span class="count">· {{ $uniforms->count() }} {{ Str::plural('set', $uniforms->count()) }} this month</span></h2>
            @foreach ($uniforms->chunk(3) as $chunk)
                <table class="cards">
                    <tr>
                        @foreach ($chunk as $u)
                            <td>
                                <div class="card">
                                    <span class="uses">{{ $u->uses }}× this month</span>
                                    <div class="name">{{ $u->name }}</div>
                                    <table>
                                        <tr><td class="lbl">Top</td><td>{{ $u->top }}</td></tr>
                                        <tr><td class="lbl">Ladies</td><td>{{ $u->ladies_bottom }}</td></tr>
                                        <tr><td class="lbl">Men</td><td>{{ $u->men_bottom }}</td></tr>
                                        @if ($u->colors)<tr><td class="lbl">Colours</td><td>{{ $u->colors }}</td></tr>@endif
                                    </table>
                                    @if ($u->notes)<div class="note">{{ $u->notes }}</div>@endif
                                </div>
                            </td>
                        @endforeach
                        @for ($i = $chunk->count(); $i < 3; $i++)<td></td>@endfor
                    </tr>
                </table>
                <div style="height: 8px"></div>
            @endforeach
        @endif

        @php $cols = [['7%', 'Date'], ['20%', 'Service'], ['15%', 'Uniform'], ['24%', 'Top'], ['18%', 'Ladies (dress/skirt)'], ['16%', 'Men (trousers)']]; @endphp
        <div style="page-break-inside: avoid">
            <h2>Timetable</h2>
            <table class="grid fixed">
                <tr>@foreach ($cols as [$w, $label])<th style="width: {{ $w }}">{{ $label }}</th>@endforeach</tr>
            </table>
        </div>
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
                            <td>
                                @if ($row['uniform_set'])
                                    <span class="strong">{{ $row['uniform'] }}</span>
                                @else
                                    <span class="badge b-muted">Not set</span>
                                @endif
                            </td>
                            <td>{{ $row['top'] }}</td>
                            <td>{{ $row['ladies'] }}</td>
                            <td>{{ $row['men'] }}</td>
                        </tr>
                    @endforeach
            </table>
            </div>
        @endforeach
    @endif
@endsection
