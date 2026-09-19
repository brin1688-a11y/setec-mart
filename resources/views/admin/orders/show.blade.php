@extends('layouts.admin')

@section('title', $order->order_number)

@section('content')

@php
    $payment = $order->payment;
    $steps = ['Pending', 'Confirmed', 'Preparing', 'Out for Delivery', 'Delivered'];
    $reached = array_search($order->status, $steps, true);
    $cancelled = $order->status === 'Cancelled';
    $pillFor = fn ($colour) => [
        'warning' => 'warn', 'info' => 'info', 'primary' => 'info',
        'secondary' => 'mute', 'success' => 'good', 'danger' => 'bad',
    ][$colour] ?? 'mute';
@endphp

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <a href="{{ route('admin.orders.index') }}" class="od-back">&larr; All orders</a>

        <h2 class="fw-bold mb-1 mt-2">{{ $order->order_number }}</h2>

        <p class="text-muted mb-0" style="font-size: 14px;">
            Placed {{ $order->created_at->format('D, j M Y \a\t g:ia') }}
            by {{ $order->user?->name ?? 'a guest' }}
        </p>
    </div>

    <div class="text-end">
        <span class="pill pill-{{ $pillFor($order->statusColor()) }}" style="font-size: 13px; padding: 6px 14px;">
            {{ $order->status }}
        </span>
        <div class="od-total mt-2">${{ number_format($order->total, 2) }}</div>
    </div>
</div>

{{-- Where the order has got to. A cancelled one stops wherever it stopped. --}}
@if(! $cancelled && $reached !== false)
    <div class="stat-card mb-4">
        <div class="od-track">
            @foreach($steps as $i => $step)
                <div class="od-step {{ $i <= $reached ? 'is-done' : '' }} {{ $i === $reached ? 'is-now' : '' }}">
                    <span class="od-dot">{!! $i <= $reached ? '&check;' : '' !!}</span>
                    <span class="od-step-label">{{ $step }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="row g-4">

    <div class="col-lg-7">

        <div class="stat-card mb-4">

            <h5 class="fw-bold mb-3">Items</h5>

            @foreach($order->allItems as $item)
                @php
                    $url = $item->product?->imageUrl();
                    $dropped = $item->trashed();
                @endphp
                <div class="od-line {{ $dropped ? 'is-dropped' : '' }} {{ !$loop->last ? 'has-rule' : '' }}">

                    <div class="od-thumb">
                        @if($url)
                            <img src="{{ $url }}" alt="{{ $item->product_name }}">
                        @else
                            <span class="od-thumb-none">&#128722;</span>
                        @endif
                    </div>

                    <div class="flex-grow-1" style="min-width: 0;">
                        <div class="fw-semibold">
                            @if($item->product_id)
                                <a href="{{ route('admin.products.edit', $item->product_id) }}">{{ $item->product_name }}</a>
                            @else
                                {{ $item->product_name }}
                            @endif
                            @if($dropped)
                                <span class="od-tag">removed by customer</span>
                            @endif
                        </div>
                        <div class="text-muted" style="font-size: 13px;">
                            ${{ number_format($item->price, 2) }} &times; {{ $item->quantity }}
                        </div>
                    </div>

                    <div class="fw-semibold text-end" style="white-space: nowrap;">
                        {{ $dropped ? '—' : '$'.number_format($item->subtotal(), 2) }}
                    </div>

                </div>
            @endforeach

            {{-- The full sum, not just the bottom line: the shop needs to see
                 where a total came from when a customer asks. --}}
            <div class="od-sums">
                <div><span>Subtotal</span><span>${{ number_format($order->subtotal ?? $order->total, 2) }}</span></div>

                @if((float) $order->discount > 0)
                    <div class="od-off">
                        <span>Discount{{ $order->coupon_code ? ' · '.$order->coupon_code : '' }}</span>
                        <span>&minus;${{ number_format($order->discount, 2) }}</span>
                    </div>
                @endif

                <div>
                    <span>
                        Delivery
                        @if($order->province)
                            <span class="text-muted">· {{ \App\Support\Cambodia::zoneLabel($order->province) }}</span>
                        @endif
                    </span>
                    <span>{{ (float) $order->delivery_fee === 0.0 ? 'Free' : '$'.number_format($order->delivery_fee, 2) }}</span>
                </div>

                <div class="od-grand"><span>Total</span><span>${{ number_format($order->total, 2) }}</span></div>
            </div>

        </div>

        <div class="stat-card">

            <h5 class="fw-bold mb-3">Deliver to</h5>

            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="od-key">Name</div>
                    <div>{{ $order->name }}</div>
                </div>

                <div class="col-sm-6">
                    <div class="od-key">Phone</div>
                    {{-- Tap-to-call on a phone, which is how most drivers work. --}}
                    <a href="tel:{{ $order->phone }}">{{ $order->formattedPhone() ?: '—' }}</a>
                </div>

                @if($order->telegram)
                    <div class="col-sm-6">
                        <div class="od-key">Telegram</div>
                        <a href="https://t.me/{{ $order->telegram }}" target="_blank" rel="noopener">
                            &#64;{{ $order->telegram }}
                        </a>
                    </div>
                @endif

                @if($order->province)
                    <div class="col-sm-6">
                        <div class="od-key">Zone</div>
                        <div>{{ \App\Support\Cambodia::zoneLabel($order->province) }}</div>
                        <div class="text-muted" style="font-size: 13px;">
                            {{ \App\Support\Cambodia::deliveryEta($order->province) }}
                            &middot; due {{ \App\Support\Cambodia::estimatedArrival($order->province, $order->created_at)->format('D, j M') }}
                        </div>
                    </div>
                @endif

                <div class="col-12">
                    <div class="od-key">Address</div>
                    <div>{{ $order->fullAddress() }}</div>
                </div>

                @if($order->note)
                    <div class="col-12">
                        <div class="od-key">Note from the customer</div>
                        <div class="od-note">{{ $order->note }}</div>
                    </div>
                @endif
            </div>

        </div>

    </div>

    <div class="col-lg-5">

        <div class="stat-card mb-4">

            <h5 class="fw-bold mb-3">Payment</h5>

            <div class="od-row"><span>Method</span><span>{{ $payment?->methodLabel() ?? '—' }}</span></div>

            <div class="od-row">
                <span>Status</span>
                <span>
                    @if($payment)
                        <span class="pill pill-{{ $pillFor($payment->statusColor()) }}">{{ $payment->status }}</span>
                    @else
                        —
                    @endif
                </span>
            </div>

            <div class="od-row"><span>Amount</span><span>${{ number_format($payment?->amount ?? $order->total, 2) }}</span></div>

            @if($payment?->paid_at)
                <div class="od-row"><span>Paid</span><span>{{ $payment->paid_at->format('j M, g:ia') }}</span></div>
            @endif

            @if($payment?->cutluy_payment_id)
                <div class="od-row">
                    <span>Provider ref</span>
                    <span class="od-mono">{{ $payment->cutluy_payment_id }}</span>
                </div>
            @endif

            @if($payment?->renewals)
                <div class="od-row">
                    <span>QR codes issued</span>
                    <span>{{ $payment->renewals + 1 }}</span>
                </div>
            @endif

        </div>

        <div class="stat-card mb-4">

            <h5 class="fw-bold mb-3">Move it along</h5>

            @if (session('error'))
                <div class="alert alert-warning small">{{ session('error') }}</div>
            @endif

            @if ($errors->has('status'))
                <div class="alert alert-danger small">{{ $errors->first('status') }}</div>
            @endif

            @if(empty($allowedStatuses))
                <p class="text-muted small mb-0">
                    This order is <strong>{{ strtolower($order->status) }}</strong> and can no longer be changed.
                </p>
            @else
                {{-- A button per step it can take, rather than a dropdown to
                     read and then a second click to apply. --}}
                <div class="d-grid gap-2">
                    @foreach($allowedStatuses as $status)
                        <form method="POST" action="{{ route('admin.orders.status', $order) }}"
                            @if($status === 'Cancelled')
                                onsubmit="return confirm('Cancel {{ $order->order_number }}? Its items go back into stock.');"
                            @endif
                        >
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $status }}">
                            <button type="submit"
                                    class="btn w-100 rounded-pill {{ $status === 'Cancelled' ? 'btn-outline-danger' : 'btn-success' }}">
                                {{ $status === 'Cancelled' ? 'Cancel order' : 'Mark as '.$status }}
                            </button>
                        </form>
                    @endforeach
                </div>

                @if(in_array('Cancelled', $allowedStatuses, true))
                    <p class="text-muted small mb-0 mt-3">
                        Cancelling returns every item on this order to stock.
                    </p>
                @endif
            @endif

        </div>

        <div class="stat-card">

            <h5 class="fw-bold mb-3">Customer</h5>

            <div class="od-row"><span>Name</span><span>{{ $order->user?->name ?? '—' }}</span></div>
            <div class="od-row"><span>Email</span><span class="text-truncate" style="max-width: 190px;">{{ $order->user?->email ?? '—' }}</span></div>
            <div class="od-row"><span>Orders placed</span><span>{{ $customerOrders }}</span></div>
            <div class="od-row"><span>Spent with us</span><span>${{ number_format($customerSpend, 2) }}</span></div>

            @if($order->user)
                <a href="{{ route('admin.customers.show', $order->user) }}" class="chip-btn w-100 mt-3">
                    Open customer
                </a>
            @endif

        </div>

    </div>

</div>

@push('styles')
<style>
    .od-back { font-size: 13px; font-weight: 600; }
    .od-total { font-size: 26px; font-weight: 800; letter-spacing: -.03em; }

    /* ------------------------------------------------------------ track */
    .od-track { display: flex; gap: 6px; }
    .od-step { flex: 1; min-width: 0; text-align: center; }

    .od-dot {
        display: grid; place-items: center;
        width: 26px; height: 26px; margin: 0 auto 8px;
        border-radius: 50%;
        border: 2px solid var(--line-2); background: var(--card);
        color: transparent; font-size: 13px; font-weight: 800;
    }
    .od-step.is-done .od-dot { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
    .od-step.is-now .od-dot { box-shadow: 0 0 0 4px var(--accent-w, rgba(15,157,92,.18)); }

    .od-step-label {
        display: block; font-size: 12px; font-weight: 600; color: var(--ink-3);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .od-step.is-done .od-step-label { color: var(--ink); }

    /* ------------------------------------------------------------ items */
    .od-line { display: flex; align-items: center; gap: 14px; padding: 12px 0; }
    .od-line.has-rule { border-bottom: 1px solid var(--line); }
    .od-line.is-dropped { opacity: .55; }
    .od-line.is-dropped a, .od-line.is-dropped .text-end { text-decoration: line-through; }

    .od-thumb {
        flex: 0 0 52px; width: 52px; height: 52px;
        border-radius: 13px; overflow: hidden;
        background: var(--card-2); border: 1px solid var(--line);
        display: grid; place-items: center;
    }
    .od-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .od-thumb-none { font-size: 19px; opacity: .45; }

    .od-tag {
        display: inline-block; margin-left: 6px;
        padding: 1px 8px; border-radius: 999px;
        background: var(--card-2); color: var(--ink-3);
        font-size: 11px; font-weight: 700; text-decoration: none;
    }

    /* ------------------------------------------------------------- sums */
    .od-sums { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line); }
    .od-sums > div {
        display: flex; justify-content: space-between; gap: 12px;
        font-size: 14px; color: var(--ink-2); padding: 4px 0;
    }
    .od-sums .od-off { color: var(--good); }
    .od-sums .od-grand {
        margin-top: 8px; padding-top: 12px; border-top: 1px solid var(--line);
        font-size: 17px; font-weight: 800; color: var(--ink);
    }

    /* ------------------------------------------------------------- rows */
    .od-row {
        display: flex; justify-content: space-between; gap: 12px;
        padding: 7px 0; font-size: 14px;
    }
    .od-row > span:first-child { color: var(--ink-2); }
    .od-row > span:last-child { font-weight: 600; text-align: right; }

    .od-key {
        font-size: 11px; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--ink-3); margin-bottom: 3px;
    }

    .od-note {
        padding: 10px 12px; border-radius: 12px;
        background: var(--card-2); font-size: 14px;
    }

    .od-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
</style>
@endpush

@endsection
