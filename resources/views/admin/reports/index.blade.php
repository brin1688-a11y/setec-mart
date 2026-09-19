@extends('layouts.admin')

@section('title', 'Reports')

@section('content')

@php
    $daily = $view === 'daily';

    // Chart geometry. Bars are capped rather than filling their slot, so the
    // leftover band is air; the gap between neighbours is the surface showing
    // through, not a stroke.
    $chartW = 980;
    $chartH = 230;
    $padL = 52; $padR = 12; $padT = 14; $padB = 30;
    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;

    $peak = max(0.01, collect($rows)->max('revenue'));

    // Round the axis up to something a person would say out loud.
    $step = 10 ** max(0, strlen((string) (int) $peak) - 2);
    $axisTop = max($step, ceil($peak / $step) * $step);

    $band = $plotW / max(1, count($rows));
    $barW = min(24, max(3, $band - 2));   // 2px of surface between neighbours

    $bestIndex = collect($rows)->search(fn ($r) => $r['revenue'] === collect($rows)->max('revenue'));
@endphp

<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">Sales reports</h2>
        <p class="text-muted mb-0" style="font-size: 14px;">
            {{ $daily ? $from->format('F Y') : $from->format('Y') }}
            &middot; {{ $daily ? 'day by day' : 'month by month' }}
        </p>
    </div>

    <form method="GET" action="{{ route('admin.reports.index') }}" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="rp-tabs">
            <a class="rp-tab {{ $daily ? 'is-on' : '' }}"
               href="{{ route('admin.reports.index', ['view' => 'daily', 'month' => $month]) }}">Daily</a>
            <a class="rp-tab {{ $daily ? '' : 'is-on' }}"
               href="{{ route('admin.reports.index', ['view' => 'monthly', 'year' => $year]) }}">Monthly</a>
        </div>

        <input type="hidden" name="view" value="{{ $view }}">

        @if($daily)
            <input type="month" name="month" value="{{ $month }}" class="form-control"
                   style="max-width: 180px;" onchange="this.form.submit()">
        @else
            <select name="year" class="form-select" style="max-width: 140px;" onchange="this.form.submit()">
                @foreach($years as $y)
                    <option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        @endif

        <a href="{{ route('admin.reports.export', request()->query()) }}" class="chip-btn">Export CSV</a>
    </form>
</div>

<div class="bento mb-4">
    @foreach([
        ['Revenue', '$'.number_format($summary['revenue'], 2), $summary['orders'].' paid-for orders'],
        ['Items sold', number_format($summary['items']), 'across every order'],
        ['Average order', '$'.number_format($summary['average'], 2), $summary['cancelled'].' cancelled, not counted'],
        ['Best '.($daily ? 'day' : 'month'), $summary['best_label'] ?? '—', $summary['best_label'] ? '$'.number_format($summary['best_revenue'], 2) : 'nothing sold yet'],
    ] as [$label, $value, $note])
        <div class="stat-card b-3">
            <div class="k-label">{{ $label }}</div>
            <div class="k-value">{{ $value }}</div>
            <div class="k-sub">{{ $note }}</div>
        </div>
    @endforeach
</div>

<div class="stat-card mb-4">
    <div class="sec-title mb-1">Revenue by {{ $daily ? 'day' : 'month' }}</div>
    <p class="text-muted mb-3" style="font-size: 13px;">
        Cancelled orders are excluded. Hover a bar for the figures.
    </p>

    @if($summary['revenue'] <= 0)
        <div class="table-empty">Nothing was sold in this period.</div>
    @else
        <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" width="100%" height="{{ $chartH }}"
             role="img" aria-label="Revenue by {{ $daily ? 'day' : 'month' }} for {{ $daily ? $from->format('F Y') : $from->format('Y') }}"
             class="rp-chart">

            {{-- Gridlines: hairline, solid, one step off the surface. --}}
            @for($i = 0; $i <= 4; $i++)
                @php
                    $value = $axisTop * (1 - $i / 4);
                    $gy = $padT + ($plotH * $i / 4);
                @endphp
                <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $chartW - $padR }}" y2="{{ $gy }}"
                      class="rp-grid"/>
                <text x="{{ $padL - 8 }}" y="{{ $gy + 4 }}" text-anchor="end" class="rp-axis">
                    ${{ number_format($value, $axisTop < 10 ? 2 : 0) }}
                </text>
            @endfor

            @foreach($rows as $i => $row)
                @php
                    $h = $row['revenue'] > 0 ? max(2, ($row['revenue'] / $axisTop) * $plotH) : 0;
                    $x = $padL + ($band * $i) + (($band - $barW) / 2);
                    $y = $padT + $plotH - $h;
                @endphp

                @if($h > 0)
                    {{-- Rounded at the data end, square on the baseline. --}}
                    <path class="rp-bar"
                          d="M{{ $x }},{{ $padT + $plotH }}
                             V{{ $y + min(4, $h) }}
                             a4,4 0 0 1 4,-4
                             h{{ max(0, $barW - 8) }}
                             a4,4 0 0 1 4,4
                             V{{ $padT + $plotH }} Z"/>
                @endif

                {{-- A hit target the width of the whole band, so a 3px bar is
                     still reachable with a mouse. --}}
                <rect x="{{ $padL + $band * $i }}" y="{{ $padT }}" width="{{ $band }}" height="{{ $plotH }}"
                      fill="transparent" class="rp-hit">
                    <title>{{ $row['label'] }} — ${{ number_format($row['revenue'], 2) }}, {{ $row['orders'] }} {{ Str::plural('order', $row['orders']) }}, {{ $row['items'] }} {{ Str::plural('item', $row['items']) }}</title>
                </rect>
            @endforeach

            {{-- One direct label, on the peak. Every bar labelled would be
                 unreadable; the table below carries the rest. --}}
            @if($bestIndex !== false && $summary['best_revenue'] > 0)
                @php
                    $bh = ($rows[$bestIndex]['revenue'] / $axisTop) * $plotH;
                    $bx = $padL + ($band * $bestIndex) + ($band / 2);
                    $by = $padT + $plotH - $bh - 7;
                @endphp
                <text x="{{ $bx }}" y="{{ max($padT + 8, $by) }}" text-anchor="middle" class="rp-peak">
                    ${{ number_format($rows[$bestIndex]['revenue'], 2) }}
                </text>
            @endif

            {{-- Sparse x labels: every bar's name will not fit on a month. --}}
            @foreach($rows as $i => $row)
                @php $every = $daily ? (count($rows) > 20 ? 5 : 2) : 1; @endphp
                @if($i % $every === 0)
                    <text x="{{ $padL + ($band * $i) + ($band / 2) }}" y="{{ $chartH - 10 }}"
                          text-anchor="middle" class="rp-axis">
                        {{ $daily ? explode(' ', $row['label'])[1] : substr($row['label'], 0, 3) }}
                    </text>
                @endif
            @endforeach
        </svg>
    @endif
</div>

<div class="row g-4">

    <div class="col-lg-8">
        <div class="stat-card">
            <div class="sec-title mb-3">Every {{ $daily ? 'day' : 'month' }}</div>

            <div class="table-responsive">
                <table class="table-x">
                    <thead>
                        <tr>
                            <th>{{ $daily ? 'Day' : 'Month' }}</th>
                            <th class="text-end">Orders</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Delivery</th>
                            <th class="text-end">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="{{ $row['orders'] === 0 ? 'rp-quiet' : '' }}">
                                <td>
                                    {{ $row['label'] }}
                                    @if($row['cancelled'] > 0)
                                        <span class="pill pill-mute">{{ $row['cancelled'] }} cancelled</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row['orders'] ?: '—' }}</td>
                                <td class="text-end">{{ $row['items'] ?: '—' }}</td>
                                <td class="text-end">{{ $row['subtotal'] > 0 ? '$'.number_format($row['subtotal'], 2) : '—' }}</td>
                                <td class="text-end">{{ $row['discount'] > 0 ? '−$'.number_format($row['discount'], 2) : '—' }}</td>
                                <td class="text-end">{{ $row['delivery'] > 0 ? '$'.number_format($row['delivery'], 2) : '—' }}</td>
                                <td class="text-end fw-semibold">{{ $row['revenue'] > 0 ? '$'.number_format($row['revenue'], 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="rp-total">
                            <td>Total</td>
                            <td class="text-end">{{ $summary['orders'] }}</td>
                            <td class="text-end">{{ $summary['items'] }}</td>
                            <td class="text-end">${{ number_format($summary['subtotal'], 2) }}</td>
                            <td class="text-end">−${{ number_format($summary['discount'], 2) }}</td>
                            <td class="text-end">${{ number_format($summary['delivery'], 2) }}</td>
                            <td class="text-end">${{ number_format($summary['revenue'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="stat-card">
            <div class="sec-title mb-3">What sold</div>

            @forelse($topProducts as $product)
                <div class="rp-prod">
                    <div class="min-w-0">
                        <div class="rp-prod-name">{{ $product->name }}</div>
                        <div class="rp-prod-sub">{{ $product->units }} {{ Str::plural('unit', $product->units) }}</div>
                    </div>
                    <div class="rp-prod-money">${{ number_format($product->takings, 2) }}</div>
                </div>
            @empty
                <div class="table-empty">Nothing sold in this period.</div>
            @endforelse
        </div>
    </div>

</div>

@push('styles')
<style>
    .rp-tabs { display: inline-flex; gap: 6px; }

    .rp-tab {
        padding: 7px 15px; border-radius: 999px;
        border: 1px solid var(--line);
        font-size: 13.5px; font-weight: 600;
        color: var(--ink-2); text-decoration: none; white-space: nowrap;
    }
    .rp-tab:hover { border-color: var(--line-2); color: var(--ink); }
    .rp-tab.is-on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }

    /* Marks carry the colour; every piece of text stays on an ink token. */
    .rp-bar { fill: var(--accent); }
    .rp-hit { cursor: default; }
    .rp-hit:hover + .rp-bar, .rp-bar:hover { fill: var(--accent); filter: brightness(.9); }

    .rp-grid { stroke: var(--line); stroke-width: 1; }
    .rp-axis { fill: var(--ink-3); font-size: 11px; }
    .rp-peak { fill: var(--ink-2); font-size: 11.5px; font-weight: 700; }

    .rp-chart { overflow: visible; display: block; }

    .rp-quiet td { color: var(--ink-3); }
    .rp-total td {
        border-top: 1px solid var(--line-2);
        font-weight: 700; color: var(--ink);
    }

    .rp-prod {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; padding: 10px 0; border-top: 1px solid var(--line);
    }
    .rp-prod:first-child { border-top: 0; }
    .rp-prod-name { font-size: 14px; font-weight: 600; color: var(--ink); }
    .rp-prod-sub { font-size: 12px; color: var(--ink-3); margin-top: 2px; }
    .rp-prod-money { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }

    .min-w-0 { min-width: 0; }
</style>
@endpush

@endsection
