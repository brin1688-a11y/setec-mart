@extends('layouts.admin')

@section('title', $customer->name)

@section('content')

@php
    $pillFor = fn ($colour) => [
        'warning' => 'warn', 'info' => 'info', 'primary' => 'info',
        'secondary' => 'mute', 'success' => 'good', 'danger' => 'bad',
    ][$colour] ?? 'mute';
@endphp

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <a href="{{ route('admin.customers.index') }}" class="cd-back">&larr; All customers</a>
        <h2 class="fw-bold mb-1 mt-2">{{ $customer->name }}</h2>
        <p class="text-muted mb-0" style="font-size: 14px;">
            Customer since {{ $customer->created_at->format('j M Y') }}
            @if($activeOrders)
                &middot; <span class="pill pill-warn">{{ $activeOrders }} on the way</span>
            @endif
        </p>
    </div>

    <div class="d-flex gap-2">
        @if($customer->phone)
            <a href="tel:{{ $customer->phone }}" class="chip-btn">Call</a>
        @endif
        @if($customer->telegram)
            <a href="https://t.me/{{ $customer->telegram }}" target="_blank" rel="noopener" class="chip-btn">Telegram</a>
        @endif
        <a href="mailto:{{ $customer->email }}" class="chip-btn">Email</a>
    </div>
</div>

<div class="bento mb-4">
    @foreach([
        ['Orders', number_format($orderCount), 'all time'],
        ['Spent', '$'.number_format($spend, 2), 'cancelled excluded'],
        ['Average order', '$'.number_format($averageOrder, 2), 'per order'],
        ['On the way', number_format($activeOrders), 'not yet delivered'],
    ] as [$label, $value, $note])
        <div class="stat-card b-3">
            <div class="k-label">{{ $label }}</div>
            <div class="k-value">{{ $value }}</div>
            <div class="k-sub">{{ $note }}</div>
        </div>
    @endforeach
</div>

<div class="row g-4">

    <div class="col-lg-7">

        <div class="stat-card mb-4">
            <h5 class="fw-bold mb-3">Orders</h5>

            <div class="table-responsive">
                <table class="table-x">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Placed</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customer->orders as $order)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.orders.show', $order) }}" class="cd-ref">
                                        {{ $order->order_number }}
                                    </a>
                                    <div class="cd-dim">
                                        {{ $order->items->sum('quantity') }} items
                                    </div>
                                </td>
                                <td>{{ $order->created_at->format('j M Y') }}</td>
                                <td>
                                    {{ $order->payment?->methodLabel() ?? '—' }}
                                    @if($order->payment)
                                        <div class="cd-dim">{{ $order->payment->status }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="pill pill-{{ $pillFor($order->statusColor()) }}">
                                        {{ $order->status }}
                                    </span>
                                </td>
                                <td class="text-end fw-semibold">${{ number_format($order->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="table-empty">This customer has not ordered yet.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($favourites->isNotEmpty())
            <div class="stat-card">
                <h5 class="fw-bold mb-3">Buys most often</h5>

                @foreach($favourites as $favourite)
                    <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                        <span>{{ $favourite->product_name }}</span>
                        <span class="fw-semibold">{{ $favourite->units }} bought</span>
                    </div>
                @endforeach
            </div>
        @endif

    </div>

    <div class="col-lg-5">

        <div class="stat-card mb-4">
            <h5 class="fw-bold mb-3">Contact</h5>

            <div class="cd-row"><span>Email</span><span class="text-truncate" style="max-width: 190px;">{{ $customer->email }}</span></div>
            <div class="cd-row">
                <span>Phone</span>
                <span>
                    @if($customer->phone)
                        <a href="tel:{{ $customer->phone }}">{{ $customer->formattedPhone() }}</a>
                    @else — @endif
                </span>
            </div>
            <div class="cd-row">
                <span>Telegram</span>
                <span>
                    @if($customer->telegram)
                        <a href="https://t.me/{{ $customer->telegram }}" target="_blank" rel="noopener">&#64;{{ $customer->telegram }}</a>
                    @else — @endif
                </span>
            </div>
            <div class="cd-row"><span>Joined</span><span>{{ $customer->created_at->format('j M Y') }}</span></div>
        </div>

        <div class="stat-card">
            <h5 class="fw-bold mb-3">Delivery addresses</h5>

            @forelse($customer->addresses as $address)
                <div class="py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div class="fw-semibold">
                        {{ $address->label }}
                        @if($address->is_default)
                            <span class="pill pill-good">Default</span>
                        @endif
                    </div>
                    <div class="cd-dim">{{ $address->name }} &middot; {{ $address->formattedPhone() }}</div>
                    <div style="font-size: 14px;">{{ $address->full() }}</div>
                </div>
            @empty
                {{-- Older accounts kept a single address on the user row. --}}
                @if($customer->address)
                    <div style="font-size: 14px;">{{ $customer->address }}</div>
                    <div class="cd-dim">{{ collect([$customer->commune, $customer->district, $customer->province])->filter()->implode(', ') }}</div>
                @else
                    <p class="text-muted small mb-0">None saved.</p>
                @endif
            @endforelse
        </div>

    </div>

</div>

@push('styles')
<style>
    .cd-back { font-size: 13px; font-weight: 600; }
    .cd-ref { font-weight: 700; color: var(--ink); text-decoration: none; }
    .cd-ref:hover { color: var(--accent); }
    .cd-dim { font-size: 12px; color: var(--ink-3); margin-top: 2px; }

    .cd-row {
        display: flex; justify-content: space-between; gap: 12px;
        padding: 7px 0; font-size: 14px;
    }
    .cd-row > span:first-child { color: var(--ink-2); }
    .cd-row > span:last-child { font-weight: 600; text-align: right; }
</style>
@endpush

@endsection
