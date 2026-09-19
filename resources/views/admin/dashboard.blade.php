@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')

@php
    $chartW = 720; $chartH = 190;
    $padL = 44; $padR = 12; $padT = 14; $padB = 26;

    $maxRevenue = max(1, collect($trend)->max('revenue'));
    $step = $maxRevenue <= 10 ? 2 : ($maxRevenue <= 50 ? 10 : ($maxRevenue <= 200 ? 50 : 100));
    $axisTop = max($step, ceil($maxRevenue / $step) * $step);

    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;
    $count = max(1, count($trend) - 1);

    $points = [];
    foreach ($trend as $i => $d) {
        $points[] = [
            'x' => round($padL + ($i / $count) * $plotW, 2),
            'y' => round($padT + $plotH - ($d['revenue'] / $axisTop) * $plotH, 2),
            'label' => $d['label'], 'revenue' => $d['revenue'], 'orders' => $d['orders'],
        ];
    }

    $linePath = collect($points)->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L') . $p['x'] . ' ' . $p['y'])->implode(' ');
    $areaPath = $linePath . ' L' . end($points)['x'] . ' ' . ($padT + $plotH)
              . ' L' . $points[0]['x'] . ' ' . ($padT + $plotH) . ' Z';

    $split = $paymentSplit;
    $splitTotal = max(1, $split['total']);

    $tone = ['success' => 'good', 'warning' => 'warn', 'danger' => 'bad', 'info' => 'info', 'primary' => 'info'];
@endphp

@section('actions')
    <span class="text-muted d-none d-md-inline" style="font-size:13px;">{{ now()->format('D, j M Y') }}</span>
@endsection

{{-- What needs doing, before any numbers --}}
@if(count($needsAttention))
    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach($needsAttention as $item)
            <a href="{{ $item['url'] }}" class="chip-btn" style="height:34px;text-decoration:none;">
                <span class="pill pill-{{ $item['tone'] === 'warning' ? 'warn' : ($item['tone'] === 'danger' ? 'bad' : 'mute') }}"
                      style="padding:1px 8px;">{{ $item['count'] }}</span>
                <span style="font-weight:500;color:var(--ink);">{{ $item['label'] }}</span>
                <span style="color:var(--ink-3);">&rarr;</span>
            </a>
        @endforeach
    </div>
@endif

<div class="bento">

    {{-- HERO: the one number the shop is judged on --}}
    <div class="card card-hero b-5">
        <div class="k-label">Revenue · last 30 days</div>
        <div class="k-value k-value-xl">${{ number_format($kpis['revenue_30'], 2) }}</div>

        <div class="d-flex align-items-center gap-2 mt-2">
            @if($kpis['revenue_30_delta'] !== null)
                <span class="trend {{ $kpis['revenue_30_delta'] >= 0 ? 'trend-up' : 'trend-down' }}">
                    {!! $kpis['revenue_30_delta'] >= 0 ? '&#9650;' : '&#9660;' !!} {{ abs($kpis['revenue_30_delta']) }}%
                </span>
                <span class="k-sub mt-0">vs previous 30 days</span>
            @else
                <span class="k-sub mt-0">All time ${{ number_format($kpis['revenue_total'], 2) }}</span>
            @endif
        </div>

        <div class="d-flex gap-4 mt-4 pt-3" style="border-top:1px solid rgba(255,255,255,.22);">
            <div>
                <div class="k-label">Today</div>
                <div style="font-size:19px;font-weight:700;">${{ number_format($kpis['revenue_today'], 2) }}</div>
            </div>
            <div>
                <div class="k-label">Orders today</div>
                <div style="font-size:19px;font-weight:700;">{{ $kpis['orders_today'] }}</div>
            </div>
            <div>
                <div class="k-label">Avg order</div>
                <div style="font-size:19px;font-weight:700;">${{ number_format($kpis['avg_order'], 2) }}</div>
            </div>
        </div>
    </div>

    {{-- Revenue trend --}}
    <div class="card b-7">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <div class="sec-title">Revenue trend</div>
                <div class="k-sub mt-1">Last 14 days · cancelled orders excluded</div>
            </div>
            <button type="button" class="chip-btn" style="height:30px;font-size:12.5px;" id="toggleTrendTable">Table</button>
        </div>

        <div id="trendChartWrap">
            <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" width="100%" height="{{ $chartH }}"
                 role="img" aria-label="Daily revenue over the last 14 days" id="trendChart" style="overflow:visible;">
                <defs>
                    <linearGradient id="revFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--accent)" stop-opacity=".26"/>
                        <stop offset="100%" stop-color="var(--accent)" stop-opacity="0"/>
                    </linearGradient>
                </defs>

                @for($g = 0; $g <= 2; $g++)
                    @php $gy = $padT + ($plotH / 2) * $g; @endphp
                    <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $chartW - $padR }}" y2="{{ $gy }}"
                          stroke="var(--line)" stroke-width="1"/>
                    <text x="{{ $padL - 8 }}" y="{{ $gy + 4 }}" text-anchor="end" font-size="10.5" fill="var(--ink-3)">
                        ${{ number_format($axisTop - ($axisTop / 2) * $g, 0) }}
                    </text>
                @endfor

                <path d="{{ $areaPath }}" fill="url(#revFill)"/>
                <path d="{{ $linePath }}" fill="none" stroke="var(--accent)" stroke-width="2.5"
                      stroke-linejoin="round" stroke-linecap="round"/>

                @foreach($points as $p)
                    <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="3.5"
                            fill="var(--accent)" stroke="var(--card)" stroke-width="2"
                            opacity="{{ $p['revenue'] > 0 ? 1 : 0.3 }}"/>
                @endforeach

                @foreach([0, intdiv(count($points) - 1, 2), count($points) - 1] as $i)
                    <text x="{{ $points[$i]['x'] }}" y="{{ $chartH - 6 }}"
                          text-anchor="{{ $i === 0 ? 'start' : ($i === count($points) - 1 ? 'end' : 'middle') }}"
                          font-size="10.5" fill="var(--ink-3)">{{ $points[$i]['label'] }}</text>
                @endforeach

                <line id="trendCrosshair" y1="{{ $padT }}" y2="{{ $padT + $plotH }}"
                      stroke="var(--accent)" stroke-width="1" stroke-dasharray="3 3" opacity="0"/>
            </svg>
            <div id="trendTooltip" class="chart-tooltip" hidden></div>
        </div>

        <div id="trendTable" class="table-responsive mt-2" hidden>
            <table class="table-x">
                <thead><tr><th>Day</th><th class="text-end">Revenue</th><th class="text-end">Orders</th></tr></thead>
                <tbody>
                    @foreach($trend as $d)
                        <tr>
                            <td>{{ $d['label'] }}</td>
                            <td class="text-end">${{ number_format($d['revenue'], 2) }}</td>
                            <td class="text-end">{{ $d['orders'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Small metrics --}}
    <div class="card b-3">
        <div class="k-label">Orders · 30 days</div>
        <div class="k-value">{{ number_format($kpis['orders_30']) }}</div>
        @if($kpis['orders_30_delta'] !== null)
            <div class="trend {{ $kpis['orders_30_delta'] >= 0 ? 'trend-up' : 'trend-down' }} mt-2">
                {!! $kpis['orders_30_delta'] >= 0 ? '&#9650;' : '&#9660;' !!} {{ abs($kpis['orders_30_delta']) }}%
            </div>
        @else
            <div class="k-sub">{{ number_format($kpis['orders_total']) }} all time</div>
        @endif
    </div>

    <div class="card b-3">
        <div class="k-label">Customers</div>
        <div class="k-value">{{ number_format($kpis['customers']) }}</div>
        <div class="k-sub">+{{ $kpis['new_customers_30'] }} in 30 days</div>
    </div>

    <div class="card b-3">
        <div class="k-label">Products</div>
        <div class="k-value">{{ number_format($kpis['products']) }}</div>
        <div class="k-sub">
            @if($outOfStockCount > 0)
                <span style="color:var(--bad);font-weight:700;">{{ $outOfStockCount }} out of stock</span>
            @else
                All in stock
            @endif
        </div>
    </div>

    <div class="card b-3">
        <div class="k-label">KHQR awaiting</div>
        <div class="k-value">{{ $split['khqr_awaiting'] }}</div>
        <div class="k-sub">{{ $split['khqr_paid'] }} paid</div>
    </div>

    {{-- Payment split --}}
    <div class="card b-4">
        <div class="sec-title mb-1">How customers pay</div>
        <div class="k-sub mb-3 mt-0">{{ $split['total'] }} orders</div>

        @php
            $segments = [
                ['label' => 'KHQR', 'count' => $split['khqr'], 'color' => 'var(--accent)'],
                ['label' => 'Cash on Delivery', 'count' => $split['cod'], 'color' => 'var(--info)'],
                ['label' => 'Other', 'count' => $split['legacy'], 'color' => 'var(--ink-3)'],
            ];
        @endphp

        <div class="split-bar mb-3">
            @foreach($segments as $seg)
                @if($seg['count'] > 0)
                    <div class="split-seg" style="width: {{ round($seg['count'] / $splitTotal * 100, 2) }}%; background: {{ $seg['color'] }};"></div>
                @endif
            @endforeach
        </div>

        @foreach($segments as $seg)
            @if($seg['count'] > 0)
                <div class="d-flex align-items-center justify-content-between py-1">
                    <span class="d-flex align-items-center gap-2">
                        <span class="legend-swatch" style="background: {{ $seg['color'] }};"></span>
                        <span style="font-size:13px;">{{ $seg['label'] }}</span>
                    </span>
                    <span class="k-sub mt-0">{{ $seg['count'] }} · {{ round($seg['count'] / $splitTotal * 100) }}%</span>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Order pipeline --}}
    <div class="card b-4">
        <div class="sec-title mb-3">Orders by status</div>
        @php $maxStatus = max(1, collect($statusBreakdown)->max('count')); @endphp

        @foreach($statusBreakdown as $row)
            <div class="mb-2">
                <div class="d-flex justify-content-between mb-1" style="font-size:13px;">
                    <span>{{ __('site.order.status.' . $row['status']) }}</span>
                    <span class="text-muted">{{ $row['count'] }}</span>
                </div>
                <div class="track">
                    <div class="track-fill" data-tone="{{ $row['color'] }}"
                         style="width: {{ $row['count'] > 0 ? max(2, round($row['count'] / $maxStatus * 100)) : 0 }}%;"></div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Best sellers --}}
    <div class="card b-4">
        <div class="sec-title mb-3">Best sellers</div>

        @if($topProducts->isEmpty())
            <div class="k-sub">No sales yet.</div>
        @else
            @php $maxUnits = max(1, $topProducts->max('units')); @endphp
            @foreach($topProducts as $p)
                <div class="mb-2">
                    <div class="d-flex justify-content-between mb-1" style="font-size:13px;">
                        <span class="text-truncate pe-2">{{ $p->product_name }}</span>
                        <span class="text-muted text-nowrap">{{ $p->units }} · ${{ number_format($p->revenue, 2) }}</span>
                    </div>
                    <div class="track">
                        <div class="track-fill" style="width: {{ round($p->units / $maxUnits * 100) }}%;"></div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- Recent orders --}}
    <div class="card b-8">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="sec-title">Recent orders</div>
            <a href="{{ route('admin.orders.index') }}" style="font-size:13px;">View all &rarr;</a>
        </div>

        @if($recentOrders->isEmpty())
            <div class="table-empty">No orders yet.</div>
        @else
            <div class="table-responsive">
                <table class="table-x">
                    <thead>
                        <tr><th>Order</th><th>Customer</th><th>Payment</th><th>Status</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach($recentOrders as $order)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.orders.show', $order) }}" style="font-weight:700;">{{ $order->order_number }}</a>
                                    <div class="k-sub mt-0">{{ $order->created_at->diffForHumans(short: true) }}</div>
                                </td>
                                <td class="text-truncate" style="max-width:150px;">{{ $order->user->name }}</td>
                                <td>
                                    <div style="font-size:13px;">{{ $order->payment?->methodLabel() ?? '—' }}</div>
                                    @if($order->payment)
                                        <span class="pill pill-{{ $tone[$order->payment->statusColor()] ?? 'mute' }}">
                                            {{ $order->payment->status }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="pill pill-{{ $tone[$order->statusColor()] ?? 'mute' }}">
                                        {{ __('site.order.status.' . $order->status) }}
                                    </span>
                                </td>
                                <td class="text-end" style="font-weight:700;">${{ number_format($order->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Stock --}}
    <div class="card b-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="sec-title">Stock alerts</div>
            <a href="{{ route('admin.inventory.index') }}" style="font-size:13px;">Inventory &rarr;</a>
        </div>

        @if($lowStock->isEmpty())
            <div class="k-sub">Everything is well stocked.</div>
        @else
            @foreach($lowStock as $product)
                <div class="d-flex justify-content-between align-items-center py-2"
                     style="{{ !$loop->last ? 'border-bottom:1px solid var(--line);' : '' }}">
                    <span class="text-truncate pe-2" style="font-size:13px;">{{ $product->name }}</span>
                    <span class="pill pill-{{ $product->stock <= 0 ? 'bad' : ($product->stock <= 5 ? 'warn' : 'mute') }}">
                        {{ $product->stock <= 0 ? 'Out' : $product->stock . ' left' }}
                    </span>
                </div>
            @endforeach
        @endif
    </div>

</div>

<script>
(function () {
    const svg = document.getElementById('trendChart');
    const tooltip = document.getElementById('trendTooltip');
    const crosshair = document.getElementById('trendCrosshair');
    const points = @json($points);
    if (!svg || !points.length) return;

    function nearest(x) {
        let best = points[0], dist = Infinity;
        points.forEach(p => { const d = Math.abs(p.x - x); if (d < dist) { dist = d; best = p; } });
        return best;
    }

    svg.addEventListener('mousemove', function (e) {
        const box = svg.getBoundingClientRect();
        const p = nearest(((e.clientX - box.left) / box.width) * {{ $chartW }});
        const scale = box.width / {{ $chartW }};

        crosshair.setAttribute('x1', p.x);
        crosshair.setAttribute('x2', p.x);
        crosshair.setAttribute('opacity', '1');

        tooltip.hidden = false;
        tooltip.innerHTML = '<div class="tt-title">' + p.label + '</div>'
            + '<div class="tt-row"><span>Revenue</span><strong>$' + p.revenue.toFixed(2) + '</strong></div>'
            + '<div class="tt-row"><span>Orders</span><strong>' + p.orders + '</strong></div>';

        tooltip.style.left = Math.min(Math.max(p.x * scale - tooltip.offsetWidth / 2, 0), box.width - tooltip.offsetWidth) + 'px';
        tooltip.style.top = Math.max(0, p.y * scale - tooltip.offsetHeight - 12) + 'px';
    });

    svg.addEventListener('mouseleave', function () {
        crosshair.setAttribute('opacity', '0');
        tooltip.hidden = true;
    });

    const toggle = document.getElementById('toggleTrendTable');
    const table = document.getElementById('trendTable');
    const wrap = document.getElementById('trendChartWrap');

    toggle.addEventListener('click', function () {
        const showTable = table.hidden;
        table.hidden = !showTable;
        wrap.hidden = showTable;
        toggle.textContent = showTable ? 'Chart' : 'Table';
    });
})();
</script>

@endsection
