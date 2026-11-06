<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} - {{ $subtitle }}</title>
    <style>
        @page { margin: 26px 30px 42px; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 9.5px; color: #27272a; line-height: 1.35; }

        /* Banner */
        .banner { width: 100%; background: #312e81; border-radius: 8px; border-collapse: separate; margin-bottom: 16px; }
        .banner td { padding: 14px 18px; vertical-align: middle; border: 0; background: transparent; }
        .banner .team { font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; color: #a5b4fc; }
        .banner .title { font-size: 21px; font-weight: bold; color: #ffffff; margin-top: 2px; }
        .banner .subtitle { font-size: 11px; color: #c7d2fe; margin-top: 1px; }
        .banner .right { text-align: right; }
        .stat { display: inline-block; background: #4338ca; border-radius: 6px; padding: 5px 9px; margin-left: 5px; text-align: center; min-width: 46px; }
        .stat .n { font-size: 15px; font-weight: bold; color: #fff; }
        .stat .l { font-size: 7px; text-transform: uppercase; letter-spacing: .6px; color: #c7d2fe; }

        /* Section headings */
        h2 { font-size: 12px; color: #1e1b4b; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #e0e7ff; }
        h2 .count { color: #818cf8; font-weight: normal; }

        /* Tables */
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #f1f5f9; color: #475569; text-align: left; font-size: 7.5px; text-transform: uppercase; letter-spacing: .6px; padding: 6px 7px; border-bottom: 1.5px solid #cbd5e1; }
        table.grid td { padding: 5px 7px; border-bottom: 1px solid #eef2f7; vertical-align: middle; }
        table.grid tr { page-break-inside: avoid; }
        table.fixed { table-layout: fixed; }
        tr.sizer td { padding: 0; border: 0; height: 0; line-height: 0; font-size: 0; }
        tr.special td { background: #fff7f8; }

        /* A week = heading + its services, never split across pages */
        .week-block { page-break-inside: avoid; }
        .week { background: #eef2ff; color: #3730a3; font-weight: bold; font-size: 8px; letter-spacing: .8px; text-transform: uppercase; padding: 4px 7px; margin-top: 2px; }

        /* Calendar date tile */
        .tile { width: 38px; border: 1px solid #c7d2fe; border-radius: 5px; text-align: center; overflow: hidden; }
        .tile .m { background: #4338ca; color: #fff; font-size: 6.5px; text-transform: uppercase; letter-spacing: .8px; padding: 1px 0; }
        .tile .d { font-size: 14px; font-weight: bold; color: #1e1b4b; padding-top: 1px; }
        .tile .w { font-size: 6.5px; color: #64748b; padding-bottom: 2px; }

        /* Badges */
        .badge { display: inline-block; padding: 1.5px 6px; border-radius: 8px; font-size: 7px; font-weight: bold; letter-spacing: .3px; }
        .b-sunday { background: #e0e7ff; color: #3730a3; }
        .b-wednesday { background: #ccfbf1; color: #115e59; }
        .b-friday { background: #fef3c7; color: #92400e; }
        .b-special { background: #ffe4e6; color: #9f1239; }
        .b-praise { background: #fef3c7; color: #92400e; }
        .b-worship { background: #ede9fe; color: #5b21b6; }
        .b-hymn { background: #d1fae5; color: #065f46; }
        .b-muted { background: #f1f5f9; color: #475569; }

        .strong { font-weight: bold; color: #18181b; }
        .muted { color: #64748b; }
        .small { font-size: 8px; }
        .nowrap { white-space: nowrap; }
        .star { color: #6366f1; }
        .empty { padding: 40px; text-align: center; color: #94a3b8; border: 1.5px dashed #cbd5e1; border-radius: 8px; font-size: 11px; }
        .page-break { page-break-before: always; }

        .footer { position: fixed; bottom: -28px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 5px; }
    </style>
    @yield('styles')
</head>
<body>
    <div class="footer">{{ config('worship.team_name') }} &nbsp;·&nbsp; {{ $title }} &nbsp;·&nbsp; {{ $subtitle }} &nbsp;·&nbsp; Generated {{ now()->format('j M Y, H:i') }}</div>

    <table class="banner">
        <tr>
            <td>
                <div class="team">{{ config('worship.team_name') }}</div>
                <div class="title">{{ $title }}</div>
                <div class="subtitle">{{ $subtitle }}</div>
            </td>
            <td class="right">@yield('stats')</td>
        </tr>
    </table>

    @yield('content')
</body>
</html>
