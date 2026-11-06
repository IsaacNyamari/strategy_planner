@extends('exports.layout')

@section('styles')
    <style>
        .b-probation { background: #fef3c7; color: #92400e; }
        .b-active { background: #dcfce7; color: #166534; }
        .b-inactive { background: #f1f5f9; color: #64748b; }
    </style>
@endsection

@section('stats')
    <span class="stat"><div class="n">{{ $stats['total'] }}</div><div class="l">Members</div></span>
    @if ($stats['probation'])
        <span class="stat"><div class="n">{{ $stats['probation'] }}</div><div class="l">Probation</div></span>
    @endif
    <span class="stat"><div class="n">{{ $stats['female'] }}</div><div class="l">Ladies</div></span>
    <span class="stat"><div class="n">{{ $stats['male'] }}</div><div class="l">Men</div></span>
@endsection

@section('content')
    @if (empty($rows))
        <div class="empty">No members match this list.</div>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th style="width: 3%">#</th>
                    <th style="width: 18%">Name</th>
                    <th style="width: 12%">Part</th>
                    <th style="width: 13%">Phone</th>
                    <th style="width: 18%">Email</th>
                    <th style="width: 11%">Can lead</th>
                    <th style="width: 10%">Status</th>
                    <th style="width: 8%">Joined</th>
                    <th style="width: 9%">Probation ends</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr>
                        <td class="muted">{{ $i + 1 }}</td>
                        <td><span class="strong">{{ $row['name'] }}</span><div class="muted small">{{ $row['gender'] }}</div></td>
                        <td>{{ $row['part'] ?: '–' }}</td>
                        <td class="nowrap">{{ $row['phone'] ?: '–' }}</td>
                        <td class="small">{{ $row['email'] ?: '–' }}</td>
                        <td>
                            @if (str_contains($row['leads'], 'Praise'))<span class="badge b-praise">Praise</span>@endif
                            @if (str_contains(strtolower($row['leads']), 'worship'))<span class="badge b-worship">Worship</span>@endif
                            @if (! $row['leads'])<span class="muted">–</span>@endif
                        </td>
                        <td><span class="badge b-{{ $row['status_key'] }}">{{ $row['status'] }}</span></td>
                        <td class="nowrap small">{{ $row['joined'] }}</td>
                        <td class="nowrap small">{{ $row['probation_ends'] ?: '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
