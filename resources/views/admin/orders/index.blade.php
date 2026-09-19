@extends('layouts.admin')

@section('title', 'Orders')

@section('content')

<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">Orders</h2>
        <p class="text-muted mb-0" style="font-size: 14px;">
            {{ $today['orders_today'] }} {{ \Illuminate\Support\Str::plural('order', $today['orders_today']) }} today
            &middot; ${{ number_format($today['revenue_today'], 2) }} taken
        </p>
    </div>

    <form method="GET" action="{{ route('admin.orders.index') }}" class="d-flex gap-2">
        @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <input type="search" name="q" value="{{ $search }}" class="form-control"
               style="max-width: 260px;" placeholder="Reference, name or phone…">
        <button class="chip-btn" type="submit">Search</button>
        @if($search !== '')
            <a href="{{ route('admin.orders.index', $status ? ['status' => $status] : []) }}" class="chip-btn">Clear</a>
        @endif
    </form>
</div>

{{-- What is waiting on someone. Each tile is a link into that queue, so the
     number is a starting point rather than just a figure. --}}
<div class="bento mb-4">
    @foreach([
        ['Awaiting payment', $today['awaiting_payment'], 'Pending', 'warn'],
        ['To prepare', $today['to_prepare'], 'Confirmed', 'info'],
        ['On the road', $today['on_the_road'], 'Out for Delivery', 'mute'],
    ] as [$label, $value, $linkStatus, $tone])
        <a class="stat-card b-4 o-tile" href="{{ route('admin.orders.index', ['status' => $linkStatus]) }}">
            <div class="k-label">{{ $label }}</div>
            <div class="k-value">{{ $value }}</div>
            <div class="k-sub">
                @if($value === 0)
                    Nothing waiting
                @else
                    {{ \Illuminate\Support\Str::plural('order', $value) }} &rarr;
                @endif
            </div>
        </a>
    @endforeach
</div>

<div class="stat-card">

    {{-- Tabs, not a dropdown: the counts are the point, and they are only
         useful if you can see them without opening anything. --}}
    <div class="o-tabs mb-3">
        @foreach(array_merge(['' => 'All'], array_combine(\App\Models\Order::STATUSES, \App\Models\Order::STATUSES)) as $key => $label)
            <a class="o-tab {{ (string) $status === (string) $key ? 'is-on' : '' }}"
               href="{{ route('admin.orders.index', array_filter(['status' => $key, 'q' => $search ?: null])) }}">
                {{ $label }}
                <span class="o-tab-n">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <div class="table-responsive">
        <table class="table-x" id="ordersTable" data-sortable>
            <thead>
                <tr>
                    <th data-sort="text">Order</th>
                    <th data-sort="text">Customer</th>
                    <th>Items</th>
                    <th data-sort="text">Payment</th>
                    <th data-sort="text">Deliver to</th>
                    <th class="text-end" data-sort="number">Total</th>
                    <th data-sort="text">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    @php
                        $payment = $order->payment;
                        $next = \App\Http\Controllers\Admin\OrderController::TRANSITIONS[$order->status] ?? [];
                        $advance = collect($next)->first(fn ($s) => $s !== 'Cancelled');
                        $units = $order->items->sum('quantity');
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.orders.show', $order) }}" class="o-ref">{{ $order->order_number }}</a>
                            <div class="o-dim">{{ $order->created_at->format('j M, g:ia') }}</div>
                        </td>

                        <td>
                            <div class="fw-semibold">{{ $order->name ?: $order->user?->name ?: '—' }}</div>
                            <div class="o-dim">{{ $order->formattedPhone() ?: '—' }}</div>
                        </td>

                        <td>
                            <div>{{ $units }} {{ \Illuminate\Support\Str::plural('item', $units) }}</div>
                            <div class="o-dim text-truncate" style="max-width: 190px;">
                                {{ $order->items->pluck('product_name')->take(2)->implode(', ') ?: '—' }}
                            </div>
                        </td>

                        <td>
                            <div>{{ $payment?->methodLabel() ?? '—' }}</div>
                            @if($payment)
                                <span class="pill pill-{{ [
                                    'success' => 'good', 'danger' => 'bad', 'secondary' => 'mute',
                                ][$payment->statusColor()] ?? 'warn' }}">{{ $payment->status }}</span>
                            @endif
                        </td>

                        <td>
                            <div>{{ $order->destination() }}</div>
                            <div class="o-dim">{{ $order->district ?: '—' }}</div>
                        </td>

                        <td class="text-end fw-semibold">${{ number_format($order->total, 2) }}</td>

                        <td>
                            <span class="pill pill-{{ [
                                'warning' => 'warn', 'info' => 'info', 'primary' => 'info',
                                'secondary' => 'mute', 'success' => 'good', 'danger' => 'bad',
                            ][$order->statusColor()] ?? 'mute' }}">{{ $order->status }}</span>
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex gap-2">
                                @if($advance)
                                    {{-- The one move this order is most likely
                                         to need, without opening it first. --}}
                                    <form method="POST" action="{{ route('admin.orders.status', $order) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $advance }}">
                                        <button class="chip-btn" type="submit"
                                                title="Move to {{ $advance }}">{{ $advance }}</button>
                                    </form>
                                @endif
                                <a href="{{ route('admin.orders.show', $order) }}" class="chip-btn">Open</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="table-empty">
                                @if($search !== '')
                                    Nothing matches &ldquo;{{ $search }}&rdquo;.
                                @elseif($status)
                                    No orders are {{ strtolower($status) }} right now.
                                @else
                                    No orders yet.
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $orders->links() }}
    </div>

</div>

@push('styles')
<style>
    .o-tile { display: block; text-decoration: none; transition: border-color .15s ease, transform .15s ease; }
    .o-tile:hover { border-color: var(--accent); transform: translateY(-1px); }

    .o-tabs { display: flex; flex-wrap: wrap; gap: 6px; }

    .o-tab {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 7px 13px; border-radius: 999px;
        border: 1px solid var(--line);
        font-size: 13.5px; font-weight: 600;
        color: var(--ink-2); text-decoration: none; white-space: nowrap;
        transition: .15s ease;
    }
    .o-tab:hover { border-color: var(--line-2); color: var(--ink); }
    .o-tab.is-on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }

    .o-tab-n {
        min-width: 20px; padding: 0 6px; border-radius: 999px;
        background: var(--card-2); color: var(--ink-3);
        font-size: 11.5px; font-weight: 700; text-align: center;
    }
    .o-tab.is-on .o-tab-n { background: rgba(255,255,255,.24); color: var(--accent-ink); }

    .o-ref { font-weight: 700; color: var(--ink); text-decoration: none; }
    .o-ref:hover { color: var(--accent); }
    .o-dim { font-size: 12px; color: var(--ink-3); margin-top: 2px; }
</style>
@endpush

@endsection
