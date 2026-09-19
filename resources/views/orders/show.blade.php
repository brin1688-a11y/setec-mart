@extends('layouts.app')

@section('title', $order->order_number)

@section('content')

@php
    $editable = $order->isCancellableByCustomer();
    $cancelled = $order->status === 'Cancelled';
    $payment = $order->payment;

    $steps = ['Pending', 'Confirmed', 'Preparing', 'Out for Delivery', 'Delivered'];
    $atIndex = array_search($order->status, $steps, true);

    $live = $order->allItems->reject(fn ($item) => $item->trashed());
    $units = (int) $live->sum('quantity');
    $discount = (float) $order->discount;
@endphp

<div class="container py-4 py-lg-5" style="max-width: 1060px;">

    <a href="{{ route('orders.index') }}" class="od-back">
        <span aria-hidden="true">&larr;</span> @lang('site.orders.back')
    </a>

    {{-- The header answers the three questions a customer opens this page with:
         which order, where has it got to, and how much was it. --}}
    <section class="od-hero {{ $cancelled ? 'is-off' : '' }}">

        <div class="od-hero-top">
            <div class="min-w-0">
                <div class="od-eyebrow">@lang('site.orders.order')</div>
                <h1 class="od-id">{{ $order->order_number }}</h1>
                <div class="od-when">
                    {{ $order->created_at->format('D, j M Y') }}
                    <span class="od-dot">&middot;</span>
                    {{ $order->created_at->format('g:ia') }}
                    <span class="od-dot">&middot;</span>
                    {{ $units }} {{ \Illuminate\Support\Str::plural('item', $units) }}
                </div>
            </div>

            <div class="od-hero-side">
                <span class="od-st od-st-{{ $order->statusColor() }}">
                    {{ __('site.order.status.' . $order->status) }}
                </span>
                <div class="od-hero-total">${{ number_format($order->total, 2) }}</div>
            </div>
        </div>

        @if($cancelled)
            <div class="od-off">
                <span aria-hidden="true">&#10005;</span>
                This order was cancelled. Nothing was charged and the items went back on the shelf.
            </div>
        @elseif($atIndex !== false)
            {{-- One rail instead of a tick list: the distance travelled is
                 visible without reading every line. --}}
            <div class="od-rail">
                @foreach($steps as $i => $step)
                    <div class="od-node {{ $i < $atIndex ? 'is-done' : '' }} {{ $i === $atIndex ? 'is-now' : '' }}">
                        <span class="od-node-dot"></span>
                        <span class="od-node-label">{{ $step }}</span>
                    </div>
                @endforeach
            </div>
        @endif

    </section>

    <div class="row g-4 mt-0">

        <div class="col-lg-7">

            <section class="od-card mb-4">

                <div class="od-card-head">
                    <h2 class="od-h">@lang('site.orders.items')</h2>
                    @if($editable)
                        <span class="od-hint">You can still change this order</span>
                    @endif
                </div>

                <div class="od-lines">
                    @foreach($order->allItems as $item)
                        @php
                            $url = $item->product?->imageUrl();
                            $dropped = $item->trashed();
                        @endphp

                        <div class="od-line {{ $dropped ? 'is-dropped' : '' }}">

                            <div class="od-thumb">
                                @if($url)
                                    <img src="{{ $url }}" alt="{{ $item->product_name }}" loading="lazy">
                                @else
                                    <span class="od-thumb-none" aria-hidden="true">&#128722;</span>
                                @endif
                            </div>

                            <div class="min-w-0 flex-grow-1">
                                @if($item->product_id)
                                    <a href="{{ route('products.show', $item->product_id) }}" class="od-line-name">
                                        {{ $item->product_name }}
                                    </a>
                                @else
                                    <span class="od-line-name">{{ $item->product_name }}</span>
                                @endif

                                <div class="od-line-sub">
                                    ${{ number_format($item->price, 2) }} &times; {{ $item->quantity }}
                                    @if($dropped)
                                        <span class="od-tag">removed</span>
                                    @endif
                                </div>
                            </div>

                            <div class="od-line-sum">
                                {{ $dropped ? '—' : '$'.number_format($item->subtotal(), 2) }}
                            </div>

                            @if($editable && ! $dropped)
                                {{-- Removing the last item leaves nothing to
                                     deliver, so warn that it ends the order. --}}
                                <form method="POST" action="{{ route('orders.items.remove', [$order, $item]) }}"
                                      onsubmit="return confirm(@js(
                                          $order->items->count() === 1
                                              ? 'Remove '.$item->product_name.'? That is the last item, so the order will be cancelled.'
                                              : 'Remove '.$item->product_name.' from this order?'
                                      ));">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="od-x" title="Remove {{ $item->product_name }}"
                                            aria-label="Remove {{ $item->product_name }}">&times;</button>
                                </form>
                            @endif

                        </div>
                    @endforeach
                </div>

            </section>

            <section class="od-card">

                <div class="od-card-head">
                    <h2 class="od-h">@lang('site.orders.delivery_information')</h2>
                </div>

                <dl class="od-facts">
                    <div class="od-fact">
                        <dt>@lang('site.orders.name')</dt>
                        <dd>{{ $order->name }}</dd>
                    </div>

                    <div class="od-fact">
                        <dt>@lang('site.orders.phone')</dt>
                        <dd>{{ $order->formattedPhone() }}</dd>
                    </div>

                    @if($order->province)
                        <div class="od-fact">
                            <dt>Province</dt>
                            <dd>{{ $order->province }}</dd>
                        </div>
                        <div class="od-fact">
                            <dt>District</dt>
                            <dd>{{ $order->district ?: '—' }}</dd>
                        </div>
                        <div class="od-fact">
                            <dt>Commune</dt>
                            <dd>{{ $order->commune ?: '—' }}</dd>
                        </div>
                    @endif

                    @if($order->telegram)
                        <div class="od-fact">
                            <dt>Telegram</dt>
                            <dd>
                                <a href="https://t.me/{{ $order->telegram }}" target="_blank" rel="noopener"
                                   class="od-link">&#64;{{ $order->telegram }}</a>
                            </dd>
                        </div>
                    @endif

                    <div class="od-fact od-fact-wide">
                        <dt>Village, street &amp; house</dt>
                        <dd>{{ $order->address }}</dd>
                    </div>

                    @if($order->note)
                        <div class="od-fact od-fact-wide">
                            <dt>@lang('site.orders.note')</dt>
                            <dd>{{ $order->note }}</dd>
                        </div>
                    @endif
                </dl>

            </section>

        </div>

        <div class="col-lg-5">
            <div class="od-stick">

                <section class="od-card mb-4">

                    <div class="od-card-head">
                        <h2 class="od-h">Summary</h2>
                    </div>

                    @if($order->province && ! $cancelled)
                        <div class="od-eta">
                            <span class="od-eta-mark" aria-hidden="true">&#128666;</span>
                            <div>
                                <div class="od-eta-title">
                                    {{ $order->status === 'Delivered' ? 'Delivered' : 'Arriving '.$eta }}
                                </div>
                                @if($order->status !== 'Delivered')
                                    <div class="od-eta-sub">Estimated {{ $arrival->format('D, j M') }}</div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="od-sum">
                        <div class="od-sum-row">
                            <span>@lang('site.orders.subtotal')</span>
                            <span>${{ number_format($order->subtotal ?? $order->total, 2) }}</span>
                        </div>

                        {{-- Without this line the arithmetic does not add up for
                             anyone who used a coupon. --}}
                        @if($discount > 0)
                            <div class="od-sum-row is-off">
                                <span>
                                    Discount
                                    @if($order->coupon_code)
                                        <span class="od-code">{{ $order->coupon_code }}</span>
                                    @endif
                                </span>
                                <span>&minus;${{ number_format($discount, 2) }}</span>
                            </div>
                        @endif

                        <div class="od-sum-row">
                            <span>
                                @lang('site.orders.delivery')
                                @if($order->province)
                                    <span class="od-zone">{{ \App\Support\Cambodia::zoneLabel($order->province) }}</span>
                                @endif
                            </span>
                            <span>
                                @if((float) $order->delivery_fee === 0.0)
                                    <span class="od-free">@lang('site.orders.free')</span>
                                @else
                                    ${{ number_format($order->delivery_fee, 2) }}
                                @endif
                            </span>
                        </div>

                        <div class="od-sum-total">
                            <span>@lang('site.orders.total')</span>
                            <span>${{ number_format($order->total, 2) }}</span>
                        </div>
                    </div>

                </section>

                <section class="od-card">

                    <div class="od-card-head">
                        <h2 class="od-h">@lang('site.orders.payment')</h2>
                    </div>

                    <div class="od-sum">
                        <div class="od-sum-row">
                            <span>@lang('site.orders.method')</span>
                            <span class="od-strong">{{ $payment?->methodLabel() ?? '—' }}</span>
                        </div>

                        <div class="od-sum-row">
                            <span>@lang('site.orders.status')</span>
                            <span class="od-st od-st-sm od-st-{{ $payment?->statusColor() ?? 'secondary' }}">
                                {{ $payment?->status ?? 'Unpaid' }}
                            </span>
                        </div>
                    </div>

                    @if($payment?->isAwaitingKhqr())
                        <a href="{{ route('payments.khqr', $order) }}" class="btn btn-success rounded-pill w-100 mt-3">
                            @lang('site.orders.pay_with_khqr')
                        </a>
                    @endif

                    @if($editable)
                        {{-- Nothing has been paid and the shop has not started
                             picking, so this is still the customer's to call off. --}}
                        <form method="POST" action="{{ route('orders.cancel', $order) }}"
                              onsubmit="return confirm('Cancel {{ $order->order_number }}? The items go back in stock.');">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger rounded-pill w-100 mt-2">
                                Cancel this order
                            </button>
                        </form>
                        <p class="od-note">You can cancel until the shop starts preparing it.</p>
                    @endif

                    @if($order->isFinished())
                        <form method="POST" action="{{ route('orders.hide', $order) }}"
                              onsubmit="return confirm('Remove {{ $order->order_number }} from your list? The shop still keeps its record.');">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary rounded-pill w-100 mt-2">
                                Remove from my list
                            </button>
                        </form>
                    @endif

                </section>

            </div>
        </div>

    </div>

</div>

@push('styles')
<style>
    .min-w-0 { min-width: 0; }

    .od-back {
        display: inline-block; margin-bottom: 14px;
        font-size: 14px; font-weight: 600;
        color: var(--ink-3); text-decoration: none;
    }
    .od-back:hover { color: var(--brand-2); }

    /* --------------------------------------------------------------- hero */
    .od-hero {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--r-lg);
        box-shadow: var(--sh-1);
        padding: 22px 24px;
    }

    .od-hero-top {
        display: flex; justify-content: space-between;
        align-items: flex-start; gap: 16px; flex-wrap: wrap;
    }

    .od-eyebrow {
        font-size: 11px; font-weight: 800; letter-spacing: .14em;
        text-transform: uppercase; color: var(--ink-3);
    }

    .od-id {
        margin: 3px 0 4px;
        font-size: clamp(22px, 3.4vw, 30px); font-weight: 800;
        letter-spacing: -.02em; color: var(--ink);
        font-variant-numeric: tabular-nums;
    }

    .od-when { font-size: 13.5px; color: var(--ink-2); }
    .od-dot { color: var(--ink-3); margin: 0 3px; }

    .od-hero-side { text-align: right; }
    .od-hero-total {
        margin-top: 8px;
        font-size: 24px; font-weight: 800; letter-spacing: -.02em;
        color: var(--brand-2); font-variant-numeric: tabular-nums;
    }

    .od-st {
        display: inline-flex; align-items: center;
        padding: 6px 14px; border-radius: 999px;
        font-size: 12.5px; font-weight: 700; white-space: nowrap;
    }
    .od-st-sm { padding: 4px 11px; font-size: 11.5px; }

    .od-st-warning   { background: #fdf3e0; color: #97640a; }
    .od-st-info      { background: #e4f3fb; color: #1a6d93; }
    .od-st-primary   { background: #e7edfd; color: #2c4fa8; }
    .od-st-secondary { background: #ecefee; color: #4c5a55; }
    .od-st-success   { background: var(--brand-wash); color: var(--brand-2); }
    .od-st-danger    { background: var(--bad-wash); color: var(--bad); }
    .od-st-light     { background: var(--surface-2); color: var(--ink-2); }

    /* --------------------------------------------------------------- rail */
    .od-rail { display: flex; margin-top: 26px; }

    .od-node {
        position: relative;
        flex: 1 1 0; min-width: 0; text-align: center;
    }

    /* Each step draws the length of rail behind it, so the line is coloured
       by the same flag that colours the dot — no percentage to keep in step. */
    .od-node::before {
        content: ''; position: absolute; z-index: 1;
        top: 7px; right: 50%; width: 100%; height: 3px;
        border-radius: 3px; background: var(--line-2);
    }
    .od-node:first-child::before { display: none; }
    .od-node.is-done::before, .od-node.is-now::before { background: var(--brand); }

    .od-node-dot {
        position: relative; z-index: 2;
        display: block; width: 17px; height: 17px; margin: 0 auto;
        border-radius: 50%;
        background: var(--surface); border: 3px solid var(--line-2);
        box-shadow: 0 0 0 4px var(--surface);
    }
    .od-node.is-done .od-node-dot { background: var(--brand); border-color: var(--brand); }
    .od-node.is-now  .od-node-dot {
        background: var(--brand); border-color: var(--brand);
        box-shadow: 0 0 0 4px var(--surface), 0 0 0 8px var(--ring);
    }

    .od-node-label {
        display: block; margin-top: 9px;
        font-size: 11.5px; font-weight: 600; line-height: 1.25;
        color: var(--ink-3);
    }
    .od-node.is-done .od-node-label { color: var(--ink-2); }
    .od-node.is-now  .od-node-label { color: var(--brand-2); font-weight: 800; }

    .od-off {
        display: flex; align-items: center; gap: 10px;
        margin-top: 18px; padding: 12px 14px;
        border-radius: var(--r-sm);
        background: var(--bad-wash); color: var(--bad);
        font-size: 13.5px; font-weight: 600;
    }

    /* -------------------------------------------------------------- cards */
    .od-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--r-lg);
        box-shadow: var(--sh-1);
        padding: 20px 22px;
    }

    .od-card-head {
        display: flex; justify-content: space-between; align-items: baseline;
        gap: 12px; margin-bottom: 14px;
    }
    .od-h { font-size: 16px; font-weight: 800; color: var(--ink); margin: 0; }
    .od-hint { font-size: 12.5px; color: var(--ink-3); }

    .od-stick { position: sticky; top: 92px; }

    /* -------------------------------------------------------------- lines */
    .od-line {
        display: flex; align-items: center; gap: 13px;
        padding: 13px 0; border-top: 1px solid var(--line);
    }
    .od-lines .od-line:first-child { border-top: 0; padding-top: 2px; }

    .od-thumb {
        flex: 0 0 58px; width: 58px; height: 58px;
        border-radius: 15px; overflow: hidden;
        background: var(--surface-2); border: 1px solid var(--line);
        display: grid; place-items: center;
    }
    .od-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .od-thumb-none { opacity: .45; font-size: 20px; }

    .od-line-name {
        display: block; font-weight: 700; font-size: 14.5px;
        color: var(--ink); text-decoration: none;
    }
    a.od-line-name:hover { color: var(--brand-2); }

    .od-line-sub { margin-top: 3px; font-size: 12.5px; color: var(--ink-3); }

    .od-line-sum {
        font-weight: 700; font-size: 14.5px; color: var(--ink);
        font-variant-numeric: tabular-nums; white-space: nowrap;
    }

    /* A line the customer took off: still on the record, plainly not charged. */
    .od-line.is-dropped { opacity: .5; }
    .od-line.is-dropped .od-line-name { text-decoration: line-through; }

    .od-tag {
        display: inline-block; margin-left: 6px;
        padding: 1px 8px; border-radius: 999px;
        background: var(--surface-2); color: var(--ink-3);
        font-size: 11px; font-weight: 700;
    }

    .od-x {
        flex: 0 0 30px; width: 30px; height: 30px;
        border-radius: 50%; border: 1px solid var(--line-2);
        background: transparent; color: var(--ink-3);
        font-size: 18px; line-height: 1;
        display: grid; place-items: center; cursor: pointer;
        transition: background .14s ease, color .14s ease, border-color .14s ease;
    }
    .od-x:hover { background: var(--bad-wash); color: var(--bad); border-color: var(--bad); }

    /* -------------------------------------------------------------- facts */
    .od-facts {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px 18px; margin: 0;
    }
    .od-fact-wide { grid-column: 1 / -1; }
    .od-fact dt {
        font-size: 11.5px; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; color: var(--ink-3); margin-bottom: 2px;
    }
    .od-fact dd { margin: 0; font-size: 14.5px; color: var(--ink); }
    .od-link { color: var(--brand-2); text-decoration: none; font-weight: 600; }

    /* ------------------------------------------------------------ summary */
    .od-eta {
        display: flex; align-items: center; gap: 11px;
        padding: 12px 14px; margin-bottom: 14px;
        border-radius: var(--r-sm);
        background: var(--brand-wash);
    }
    .od-eta-mark { font-size: 20px; line-height: 1; }
    .od-eta-title { font-size: 14px; font-weight: 800; color: var(--brand-2); }
    .od-eta-sub { font-size: 12.5px; color: var(--ink-2); }

    .od-sum-row {
        display: flex; justify-content: space-between; align-items: center;
        gap: 12px; padding: 6px 0;
        font-size: 14px; color: var(--ink-2);
        font-variant-numeric: tabular-nums;
    }
    .od-sum-row.is-off { color: var(--brand-2); font-weight: 600; }
    .od-strong { color: var(--ink); font-weight: 600; }
    .od-free { color: var(--brand-2); font-weight: 700; }

    .od-code, .od-zone {
        display: inline-block; margin-left: 5px;
        padding: 1px 8px; border-radius: 999px;
        background: var(--surface-2); color: var(--ink-3);
        font-size: 11px; font-weight: 700;
    }

    .od-sum-total {
        display: flex; justify-content: space-between; align-items: baseline;
        margin-top: 10px; padding-top: 12px;
        border-top: 1px solid var(--line);
        font-size: 15px; font-weight: 800; color: var(--ink);
        font-variant-numeric: tabular-nums;
    }
    .od-sum-total span:last-child { font-size: 22px; color: var(--brand-2); letter-spacing: -.02em; }

    .od-note { margin: 9px 0 0; font-size: 12px; color: var(--ink-3); text-align: center; }

    @media (max-width: 575.98px) {
        .od-hero { padding: 18px; }
        .od-hero-side { text-align: left; }
        .od-facts { grid-template-columns: 1fr; }

        /* Five labels will not sit side by side on a phone without colliding,
           so the rail stands up instead of shrinking the words. */
        .od-rail { display: block; }

        .od-node {
            display: flex; align-items: center; gap: 12px;
            text-align: left; padding-bottom: 16px;
        }
        .od-node:last-child { padding-bottom: 0; }

        /* Dot is 17px tall and each row adds 16px, so consecutive dot centres
           sit 33px apart — the length this connector has to cover. */
        .od-node::before {
            top: -25px; right: auto; left: 7px;
            width: 3px; height: 33px;
        }

        .od-node-dot { margin: 0; flex: 0 0 17px; }
        .od-node-label { margin-top: 0; font-size: 13px; }
    }
</style>
@endpush

@endsection
