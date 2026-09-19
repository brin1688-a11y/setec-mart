@extends('layouts.app')

@section('title', 'My Orders')

@section('content')

<div class="container py-5" style="max-width: 940px;">

    <div class="mb-4">
        <h1 class="fw-bold mb-1" style="letter-spacing: -.03em;">@lang('site.orders.my_orders')</h1>
        <p class="text-muted mb-0">@lang('site.orders.intro')</p>
    </div>

    {{-- Tabs carry their counts, so you can see there is nothing under
         "Cancelled" without clicking it. --}}
    <div class="o-tabs mb-4">
        @foreach(\App\Http\Controllers\OrderController::FILTERS as $key => $label)
            <a href="{{ route('orders.index', $key === 'all' ? [] : ['filter' => $key]) }}"
               class="o-tab {{ $filter === $key ? 'is-on' : '' }}">
                {{ $label }}
                <span class="o-tab-count">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </div>

    @if($orders->isEmpty())

        <div class="o-empty">
            <div class="o-empty-mark">&#128230;</div>
            <h5 class="fw-bold mb-1">
                {{ $filter === 'all' ? __('site.orders.none_title') : 'Nothing here' }}
            </h5>
            <p class="text-muted mb-3">
                {{ $filter === 'all'
                    ? __('site.orders.none_body')
                    : 'No orders under ' . strtolower(\App\Http\Controllers\OrderController::FILTERS[$filter]) . '.' }}
            </p>
            <a href="{{ route('products.index') }}" class="btn btn-success rounded-pill px-4">
                Start shopping
            </a>
        </div>

    @else

        @foreach($orders as $order)
            @php
                $payment = $order->payment;
                $needsPayment = $payment?->isAwaitingKhqr() ?? false;
                $steps = ['Confirmed', 'Preparing', 'Out for Delivery', 'Delivered'];
                $stepIndex = array_search($order->status, $steps, true);
                $cancelled = $order->status === 'Cancelled';
                $canCancel = $order->isCancellableByCustomer();
                $shown = $order->displayItems();
            @endphp

            <article class="o-card {{ $needsPayment ? 'needs-pay' : '' }}">

                <header class="o-head">
                    <div>
                        <div class="o-id">{{ $order->order_number }}</div>
                        <div class="o-date">
                            {{ $order->created_at->format('D, j M Y') }}
                            <span class="o-dot">&middot;</span>
                            {{ $order->created_at->format('g:ia') }}
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <span class="o-status o-status-{{ $order->statusColor() }}">
                            {{ __('site.order.status.' . $order->status) }}
                        </span>
                    </div>
                </header>

                {{-- What was actually bought — the thing the old page never showed. --}}
                <div class="o-items">
                    @foreach($shown->take(4) as $item)
                        @php $url = $item->product?->imageUrl(); @endphp
                        <div class="o-thumb" title="{{ $item->product_name }} × {{ $item->quantity }}">
                            @if($url)
                                <img src="{{ $url }}" alt="{{ $item->product_name }}">
                            @else
                                <span class="o-thumb-none">&#128722;</span>
                            @endif
                            <span class="o-qty">{{ $item->quantity }}</span>
                        </div>
                    @endforeach

                    @if($shown->count() > 4)
                        <div class="o-thumb o-thumb-more">+{{ $shown->count() - 4 }}</div>
                    @endif

                    <div class="o-names">
                        @php
                            $names = $shown->pluck('product_name');
                            $first = $names->take(2)->implode(', ');
                            $rest = $names->count() - 2;
                            $units = $shown->sum('quantity');
                        @endphp
                        {{ $rest > 0 ? $first.', and '.$rest.' more' : $first }}
                        <div class="o-count">
                            {{ $units }} {{ \Illuminate\Support\Str::plural('item', $units) }}
                        </div>
                    </div>
                </div>

                {{-- Where it is on its way, at a glance. --}}
                @if(! $cancelled && $stepIndex !== false)
                    <div class="o-track">
                        @foreach($steps as $i => $step)
                            <div class="o-step {{ $i <= $stepIndex ? 'is-done' : '' }}">
                                <span class="o-step-dot"></span>
                                <span class="o-step-label">{{ $step }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="o-meta">
                    <div class="o-meta-item">
                        <span class="o-meta-key">Payment</span>
                        <span class="o-meta-val">
                            {{ $payment?->methodLabel() ?? '—' }}
                            @if($payment)
                                <span class="o-pay o-pay-{{ $payment->statusColor() }}">{{ $payment->status }}</span>
                            @endif
                        </span>
                    </div>

                    <div class="o-meta-item">
                        <span class="o-meta-key">Deliver to</span>
                        <span class="o-meta-val">{{ $order->destination() }}</span>
                    </div>

                    <div class="o-meta-item">
                        <span class="o-meta-key">Total</span>
                        <span class="o-meta-val o-total">${{ number_format($order->total, 2) }}</span>
                    </div>
                </div>

                <footer class="o-foot">

                    <span class="{{ $needsPayment ? 'o-warn' : 'o-foot-note' }}">
                        @if($needsPayment)
                            &#9888; This order is waiting for payment.
                        @elseif($order->status === 'Delivered')
                            Delivered to {{ $order->destination() }}
                        @elseif($cancelled)
                            This order was cancelled
                        @elseif(filled($order->province))
                            {{-- Only quote a lead time when we know where it is going. --}}
                            Arriving {{ \App\Support\Cambodia::deliveryEta($order->province) }}
                        @else
                            On its way to {{ $order->destination() }}
                        @endif
                    </span>

                    <div class="d-flex gap-2">
                        @if($canCancel)
                            {{-- An unpaid order is the customer's to call off,
                                 and cancelling puts the stock back sooner than
                                 waiting for the QR to time out. --}}
                            <form method="POST" action="{{ route('orders.cancel', $order) }}"
                                  onsubmit="return confirm('Cancel {{ $order->order_number }}? The items go back in stock.');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                    Cancel
                                </button>
                            </form>
                        @endif

                        @if($order->isFinished())
                            {{-- Clears it from this list only; the shop keeps
                                 the order in its own records. --}}
                            <form method="POST" action="{{ route('orders.hide', $order) }}"
                                  onsubmit="return confirm('Remove {{ $order->order_number }} from your list? The shop still keeps its record.');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill px-3"
                                        title="Remove from my list">Remove</button>
                            </form>
                        @endif

                        <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                            {{ $needsPayment || $canCancel ? 'Details' : 'View details' }}
                        </a>

                        @if($needsPayment)
                            <a href="{{ route('payments.khqr', $order) }}" class="btn btn-sm btn-success rounded-pill px-3">
                                Pay now
                            </a>
                        @endif
                    </div>

                </footer>

            </article>
        @endforeach

        <div class="mt-4">
            {{ $orders->links() }}
        </div>

    @endif

</div>

@push('styles')
<style>
    /* ------------------------------------------------------------- tabs */
    .o-tabs {
        display: flex; flex-wrap: wrap; gap: 6px;
        padding: 5px;
        background: var(--surface); border: 1px solid var(--line);
        border-radius: 999px; box-shadow: var(--sh-1);
        width: fit-content; max-width: 100%;
    }

    .o-tab {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 8px 15px; border-radius: 999px;
        font-size: 14px; font-weight: 600; color: var(--ink-2);
        text-decoration: none; white-space: nowrap;
        transition: background .14s ease, color .14s ease;
    }
    .o-tab:hover { background: var(--surface-2); color: var(--ink); }
    .o-tab.is-on { background: var(--brand); color: #fff; }

    .o-tab-count {
        min-width: 21px; padding: 1px 6px; border-radius: 999px;
        background: var(--surface-2); color: var(--ink-3);
        font-size: 12px; font-weight: 700; text-align: center;
    }
    .o-tab.is-on .o-tab-count { background: rgba(255, 255, 255, .24); color: #fff; }

    /* ------------------------------------------------------------ card */
    .o-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--r-lg);
        box-shadow: var(--sh-1);
        margin-bottom: 16px;
        overflow: hidden;
    }
    .o-card.needs-pay { border-color: #f0d9a8; }

    .o-head {
        display: flex; justify-content: space-between; align-items: flex-start;
        gap: 12px; padding: 18px 22px 14px;
    }

    .o-id { font-weight: 800; font-size: 16px; letter-spacing: -.02em; color: var(--ink); }
    .o-date { font-size: 13px; color: var(--ink-3); margin-top: 2px; }
    .o-dot { margin: 0 4px; }

    .o-status {
        display: inline-flex; align-items: center;
        padding: 5px 13px; border-radius: 999px;
        font-size: 12px; font-weight: 700; white-space: nowrap;
    }
    .o-status-warning   { background: #fdf3e0; color: #97640a; }
    .o-status-info      { background: #e4f3fb; color: #1a6d93; }
    .o-status-primary   { background: #e7edfd; color: #2c4fa8; }
    .o-status-secondary { background: #ecefee; color: #4c5a55; }
    .o-status-success   { background: var(--brand-wash); color: var(--brand-2); }
    .o-status-danger    { background: var(--bad-wash); color: var(--bad); }

    /* ----------------------------------------------------------- items */
    .o-items {
        display: flex; align-items: center; gap: 10px;
        padding: 0 22px 16px;
    }

    .o-thumb {
        position: relative; flex: 0 0 54px;
        width: 54px; height: 54px; border-radius: 13px;
        background: var(--surface-2); border: 1px solid var(--line);
        display: grid; place-items: center; overflow: hidden;
    }
    .o-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .o-thumb-none { font-size: 20px; opacity: .45; }

    .o-thumb-more {
        font-size: 13px; font-weight: 700; color: var(--ink-2);
        background: var(--surface-2);
    }

    /* The quantity rides on the picture, the way a cart badge does. */
    .o-qty {
        position: absolute; right: -5px; bottom: -5px;
        min-width: 21px; height: 21px; padding: 0 5px;
        border-radius: 999px; border: 2px solid var(--surface);
        background: var(--ink); color: #fff;
        font-size: 11px; font-weight: 700;
        display: grid; place-items: center;
    }

    .o-names {
        min-width: 0; margin-left: 6px;
        font-size: 14px; font-weight: 600; color: var(--ink);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .o-count { font-size: 13px; font-weight: 500; color: var(--ink-3); margin-top: 2px; }

    /* ----------------------------------------------------------- track */
    .o-track {
        display: flex; gap: 4px;
        padding: 0 22px 18px;
    }

    .o-step { flex: 1; min-width: 0; }

    .o-step-dot {
        display: block; height: 4px; border-radius: 999px;
        background: var(--line-2);
    }
    .o-step.is-done .o-step-dot { background: var(--brand); }

    .o-step-label {
        display: block; margin-top: 7px;
        font-size: 11px; font-weight: 600; color: var(--ink-3);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .o-step.is-done .o-step-label { color: var(--ink-2); }

    /* ------------------------------------------------------------ meta */
    .o-meta {
        display: flex; flex-wrap: wrap; gap: 8px 32px;
        padding: 14px 22px;
        border-top: 1px solid var(--line);
        background: var(--surface-2);
    }

    .o-meta-item { display: flex; flex-direction: column; gap: 3px; }
    .o-meta-key { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3); }
    .o-meta-val { font-size: 14px; font-weight: 600; color: var(--ink); display: flex; align-items: center; gap: 7px; }
    .o-total { font-size: 16px; font-weight: 800; }

    .o-pay {
        padding: 2px 9px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
    }
    .o-pay-success { background: var(--brand-wash); color: var(--brand-2); }
    .o-pay-warning { background: #fdf3e0; color: #97640a; }
    .o-pay-danger  { background: var(--bad-wash); color: var(--bad); }
    .o-pay-secondary { background: #ecefee; color: #4c5a55; }

    /* ------------------------------------------------------------ foot */
    .o-foot {
        display: flex; flex-wrap: wrap; gap: 10px;
        justify-content: space-between; align-items: center;
        padding: 14px 22px;
        border-top: 1px solid var(--line);
    }

    .o-foot-note { font-size: 13px; color: var(--ink-3); }
    .o-warn { font-size: 13px; font-weight: 600; color: #97640a; }

    /* ----------------------------------------------------------- empty */
    .o-empty {
        text-align: center; padding: 64px 24px;
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); box-shadow: var(--sh-1);
    }
    .o-empty-mark { font-size: 54px; line-height: 1; margin-bottom: 14px; }

    @media (max-width: 575.98px) {
        .o-head, .o-items, .o-track, .o-meta, .o-foot { padding-left: 16px; padding-right: 16px; }
        .o-meta { gap: 12px 22px; }
        .o-step-label { font-size: 10px; }
        .o-foot { flex-direction: column; align-items: stretch; }
        .o-foot .btn, .o-foot > div { width: 100%; }
        .o-foot > div { display: flex; }
        .o-foot > div .btn { flex: 1; }
    }
</style>
@endpush

@endsection
