@extends('exports.layout')

@section('styles')
    <style>
        .lyric { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 12px; page-break-inside: avoid; }
        .lyric .head { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 8px 8px 0 0; }
        .lyric .head .t { font-size: 12.5px; font-weight: bold; color: #1e1b4b; }
        .lyric .head .meta { font-size: 8px; color: #64748b; margin-top: 2px; }
        .lyric .body { padding: 10px 14px 12px; font-size: 10.5px; line-height: 1.55; white-space: pre-line; color: #27272a; }
        .chip { display: inline-block; background: #eef2ff; color: #3730a3; border-radius: 4px; padding: 1px 5px; font-size: 7.5px; margin-left: 3px; }
    </style>
@endsection

@section('stats')
    <span class="stat"><div class="n">{{ count($rows) }}</div><div class="l">Songs</div></span>
    @foreach ($groups as $language => $items)
        <span class="stat"><div class="n">{{ $items->count() }}</div><div class="l">{{ $language }}</div></span>
    @endforeach
@endsection

@section('content')
    @if (empty($rows))
        <div class="empty">No songs match these filters.</div>
    @else
        @foreach ($groups as $language => $items)
            <h2>{{ $language }} <span class="count">· {{ $items->count() }} {{ Str::plural('song', $items->count()) }}</span></h2>
            <table class="grid">
                <thead>
                    <tr>
                        <th style="width: 4%">#</th>
                        <th>Title</th>
                        <th style="width: 11%">Category</th>
                        <th style="width: 7%">Key</th>
                        <th style="width: 10%">Tempo</th>
                        <th style="width: 7%">Time</th>
                        <th style="width: 20%">Theme</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $i => $row)
                        <tr>
                            <td class="muted">{{ $loop->iteration }}</td>
                            <td>
                                <span class="strong">{{ $row['title'] }}</span>
                                @if ($row['lyrics'] === 'Yes')<span class="chip">lyrics</span>@endif
                                @if ($row['artist'])<div class="muted small">{{ $row['artist'] }}</div>@endif
                            </td>
                            <td><span class="badge b-{{ $row['category_key'] }}">{{ $row['category'] }}</span></td>
                            <td class="strong nowrap">{{ $row['key'] ?: '–' }}</td>
                            <td class="nowrap">{{ $row['tempo'] ?: '–' }}</td>
                            <td>{{ $row['time'] ?: '–' }}</td>
                            <td class="muted">{{ $row['theme'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif

    @if ($lyricSongs->isNotEmpty())
        <div class="page-break"></div>
        <h2 style="margin-top: 0">Lyric sheets <span class="count">· {{ $lyricSongs->count() }} {{ Str::plural('song', $lyricSongs->count()) }}</span></h2>
        @foreach ($lyricSongs as $song)
            <div class="lyric">
                <div class="head">
                    <span class="badge b-{{ $song->category->value }}" style="float: right">{{ $song->category->label() }}</span>
                    <div class="t">{{ $song->title }}</div>
                    <div class="meta">
                        {{ $song->artist ?: 'Unknown' }} &nbsp;·&nbsp; {{ $song->language->label() }}
                        @if ($song->musical_key) &nbsp;·&nbsp; Key <b>{{ $song->musical_key }}</b> @endif
                        @if ($song->tempo_bpm) &nbsp;·&nbsp; {{ $song->tempo_bpm }} bpm @endif
                        @if ($song->time_signature) &nbsp;·&nbsp; {{ $song->time_signature }} @endif
                    </div>
                </div>
                <div class="body">{{ trim($song->lyrics) }}</div>
            </div>
        @endforeach
    @endif
@endsection
