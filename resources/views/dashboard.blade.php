@extends('layouts.app')

@section('title', 'My Account')

@section('content')

@php
    // Our own statuses, shown with the names a customer recognises.
    $timeline = [
        'Pending' => 'Order Placed',
        'Confirmed' => 'Confirmed',
        'Preparing' => 'Packing',
        'Out for Delivery' => 'Out for Delivery',
        'Delivered' => 'Delivered',
    ];
@endphp

<div class="container py-5" style="max-width: 1120px;">

    <div class="mb-4">
        <h1 class="fw-bold mb-1" style="letter-spacing: -.03em;">My Account</h1>
        <p class="text-muted mb-0">Welcome back, {{ $user->name }}.</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success rounded-4">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-warning rounded-4">{{ session('error') }}</div>
    @endif

    <div class="row g-4">

        {{-- Sidebar --}}
        <div class="col-md-3">
            <nav class="db-nav">
                @foreach($tabs as $key => $label)
                    <a href="{{ route('dashboard', $key === 'overview' ? [] : ['tab' => $key]) }}"
                       class="db-link {{ $tab === $key ? 'is-on' : '' }}">
                        {{ $label }}
                        @if($key === 'orders' && $activeOrders)
                            <span class="db-count">{{ $activeOrders }}</span>
                        @endif
                        @if($key === 'vouchers' && $vouchers->count())
                            <span class="db-count">{{ $vouchers->count() }}</span>
                        @endif
                    </a>
                @endforeach

                {{-- Profile has its own page already; link out rather than
                     keep a second copy of the same forms here. --}}
                <a href="{{ route('profile.index') }}" class="db-link">Profile Settings</a>
            </nav>
        </div>

        {{-- Main --}}
        <div class="col-md-9">

            {{-- ------------------------------------------------ overview --}}
            @if($tab === 'overview')

                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <div class="db-card">
                            <div class="db-k">Active orders</div>
                            <div class="db-v">{{ $activeOrders }}</div>
                            <div class="db-s">
                                @if($activeOrders)
                                    <a href="{{ route('dashboard', ['tab' => 'orders']) }}">Track them &rarr;</a>
                                @else
                                    Nothing on its way
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-4">
                        <div class="db-card">
                            <div class="db-k">Loyalty points</div>
                            <div class="db-v">{{ number_format($loyaltyPoints) }}</div>
                            <div class="db-s">1 point per $1 spent</div>
                        </div>
                    </div>

                    <div class="col-sm-4">
                        <div class="db-card">
                            <div class="db-k">Vouchers</div>
                            <div class="db-v">{{ $vouchers->count() }}</div>
                            <div class="db-s">
                                @if($vouchers->count())
                                    <a href="{{ route('dashboard', ['tab' => 'vouchers']) }}">See codes &rarr;</a>
                                @else
                                    None available
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="db-card">
                    <h5 class="fw-bold mb-3">Recent orders</h5>

                    @forelse($orders->take(3) as $order)
                        <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                            <div>
                                <a href="{{ route('orders.show', $order) }}" class="fw-semibold text-decoration-none">
                                    {{ $order->order_number }}
                                </a>
                                <div class="small text-muted">
                                    {{ $order->created_at->format('j M Y') }}
                                    &middot; {{ $order->displayItems()->sum('quantity') }} items
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-{{ $order->statusColor() }}">{{ $order->status }}</span>
                                <div class="fw-bold">${{ number_format($order->total, 2) }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No orders yet.
                            <a href="{{ route('products.index') }}">Start shopping</a>.</p>
                    @endforelse
                </div>

            {{-- -------------------------------------------------- orders --}}
            @elseif($tab === 'orders')

                @forelse($orders as $order)
                    @php
                        $shown = $order->displayItems();
                        $steps = array_keys($timeline);
                        $reached = array_search($order->status, $steps, true);
                        $cancelled = $order->status === 'Cancelled';
                    @endphp

                    <div class="db-card mb-3">

                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                <a href="{{ route('orders.show', $order) }}" class="fw-bold text-decoration-none">
                                    {{ $order->order_number }}
                                </a>
                                <div class="small text-muted">{{ $order->created_at->format('D, j M Y') }}</div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-{{ $order->statusColor() }}">{{ $order->status }}</span>
                                <div class="fw-bold mt-1">${{ number_format($order->total, 2) }}</div>
                            </div>
                        </div>

                        {{-- Thumbnails of what was bought. displayItems() falls
                             back to removed lines so an emptied order is not blank. --}}
                        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                            @foreach($shown->take(5) as $item)
                                <div class="db-thumb" title="{{ $item->product_name }} &times; {{ $item->quantity }}">
                                    @if($url = $item->product?->imageUrl())
                                        <img src="{{ $url }}" alt="{{ $item->product_name }}">
                                    @else
                                        <span>&#128722;</span>
                                    @endif
                                </div>
                            @endforeach

                            <div class="small text-muted ms-1">
                                {{ $shown->pluck('product_name')->take(2)->implode(', ') }}
                                @if($shown->count() > 2)
                                    and {{ $shown->count() - 2 }} more
                                @endif
                            </div>
                        </div>

                        @if(! $cancelled && $reached !== false)
                            <div class="db-track mb-3">
                                @foreach($timeline as $status => $label)
                                    @php $i = array_search($status, $steps, true); @endphp
                                    <div class="db-step {{ $i <= $reached ? 'is-done' : '' }}">
                                        <span class="db-dot"></span>
                                        <span class="db-step-label">{{ $label }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span class="small text-muted">
                                {{ $order->payment?->methodLabel() ?? '—' }}
                                @if($order->payment)
                                    &middot; {{ $order->payment->status }}
                                @endif
                            </span>

                            <div class="d-flex gap-2">
                                <a href="{{ route('orders.show', $order) }}"
                                   class="btn btn-sm btn-outline-secondary rounded-pill px-3">Details</a>

                                {{-- Refills the cart from what is on sale today,
                                     not a copy of the old prices. --}}
                                <form method="POST" action="{{ route('orders.reorder', $order) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-3">
                                        Reorder
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>
                @empty
                    <div class="db-card text-center py-5">
                        <div style="font-size: 44px;">&#128230;</div>
                        <h5 class="fw-bold mt-2">No orders yet</h5>
                        <p class="text-muted">When you place one, it will show up here.</p>
                        <a href="{{ route('products.index') }}" class="btn btn-success rounded-pill px-4">Start shopping</a>
                    </div>
                @endforelse

            {{-- ----------------------------------------------- addresses --}}
            @elseif($tab === 'addresses')

                @foreach($addresses as $address)
                    <div class="db-card mb-3">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="fw-bold">
                                    {{ $address->label }}
                                    @if($address->is_default)
                                        <span class="badge bg-success ms-1">Default</span>
                                    @endif
                                </div>
                                <div class="small text-muted mt-1">
                                    {{ $address->name }} &middot; {{ $address->formattedPhone() }}
                                </div>
                                <div class="mt-1">{{ $address->full() }}</div>
                                <div class="small text-muted mt-1">
                                    Delivery {{ $address->deliveryEta() }}
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                @unless($address->is_default)
                                    <form method="POST" action="{{ route('addresses.default', $address) }}">
                                        @csrf @method('PATCH')
                                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                            Make default
                                        </button>
                                    </form>
                                @endunless

                                <form method="POST" action="{{ route('addresses.destroy', $address) }}"
                                      onsubmit="return confirm('Remove {{ $address->label }}?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger rounded-pill px-3">Remove</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="db-card">
                    <h5 class="fw-bold mb-3">Add an address</h5>

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0 small">
                                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('addresses.store') }}" class="row g-3">
                        @csrf

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Label</label>
                            <input name="label" class="form-control form-control-lg"
                                   value="{{ old('label', 'Home') }}" placeholder="Home, Office…" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Full name</label>
                            <input name="name" class="form-control form-control-lg"
                                   value="{{ old('name', $user->name) }}" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Phone</label>
                            <input name="phone" class="form-control form-control-lg"
                                   value="{{ old('phone', $user->phone) }}" placeholder="012 345 678" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Province</label>
                            <select name="province" class="form-select form-select-lg" required>
                                <option value="">Choose…</option>
                                @foreach($provinces as $value => $label)
                                    <option value="{{ $value }}" {{ old('province') === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">District</label>
                            <input name="district" class="form-control form-control-lg"
                                   value="{{ old('district') }}" placeholder="e.g. Chamkar Mon" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Commune</label>
                            <input name="commune" class="form-control form-control-lg"
                                   value="{{ old('commune') }}" placeholder="e.g. Tonle Bassac" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Village, street &amp; house</label>
                            <textarea name="address" rows="2" class="form-control"
                                      required>{{ old('address') }}</textarea>
                        </div>

                        <div class="col-12 form-check ms-1">
                            <input type="checkbox" name="is_default" value="1" class="form-check-input" id="isDefault">
                            <label class="form-check-label" for="isDefault">Deliver here by default</label>
                        </div>

                        <div class="col-12">
                            <button class="btn btn-success rounded-pill px-4">Save address</button>
                        </div>
                    </form>
                </div>

            {{-- ------------------------------------------------ vouchers --}}
            @else

                @forelse($vouchers as $voucher)
                    <div class="db-card mb-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <div class="db-code">{{ $voucher->code }}</div>
                            <div class="small text-muted mt-1">
                                {{ $voucher->description ?: $voucher->scopeLabel() }}
                            </div>
                            @if($voucher->expires_at)
                                <div class="small text-muted">
                                    Until {{ $voucher->expires_at->format('j M Y') }}
                                </div>
                            @endif
                        </div>

                        <div class="text-end">
                            <div class="db-off">
                                {{ $voucher->type === 'percent'
                                    ? rtrim(rtrim(number_format($voucher->value, 2), '0'), '.').'% off'
                                    : '$'.number_format($voucher->value, 2).' off' }}
                            </div>
                            @if((float) $voucher->min_subtotal > 0)
                                <div class="small text-muted">
                                    On orders over ${{ number_format($voucher->min_subtotal, 2) }}
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="db-card text-center py-5">
                        <div style="font-size: 44px;">&#127915;</div>
                        <h5 class="fw-bold mt-2">No vouchers right now</h5>
                        <p class="text-muted mb-0">Check back — we run offers through the year.</p>
                    </div>
                @endforelse

            @endif

        </div>
    </div>
</div>

@push('styles')
<style>
    .db-nav {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); padding: 8px; box-shadow: var(--sh-1);
        position: sticky; top: 90px;
    }

    .db-link {
        display: flex; align-items: center; justify-content: space-between;
        padding: 11px 14px; border-radius: 12px;
        font-size: 14px; font-weight: 600; color: var(--ink-2);
        text-decoration: none; transition: background .14s ease, color .14s ease;
    }
    .db-link:hover { background: var(--surface-2); color: var(--ink); }
    .db-link.is-on { background: var(--brand); color: #fff; }

    .db-count {
        min-width: 22px; padding: 1px 7px; border-radius: 999px;
        background: var(--surface-2); color: var(--ink-3);
        font-size: 12px; font-weight: 700; text-align: center;
    }
    .db-link.is-on .db-count { background: rgba(255,255,255,.24); color: #fff; }

    .db-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); padding: 20px 22px; box-shadow: var(--sh-1);
    }

    .db-k { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3); }
    .db-v { font-size: 30px; font-weight: 800; letter-spacing: -.03em; line-height: 1.1; margin-top: 6px; }
    .db-s { font-size: 13px; color: var(--ink-3); margin-top: 4px; }
    .db-s a { color: var(--brand); text-decoration: none; font-weight: 600; }

    .db-thumb {
        flex: 0 0 46px; width: 46px; height: 46px;
        border-radius: 12px; overflow: hidden;
        background: var(--surface-2); border: 1px solid var(--line);
        display: grid; place-items: center;
    }
    .db-thumb img { width: 100%; height: 100%; object-fit: cover; }

    .db-track { display: flex; gap: 4px; }
    .db-step { flex: 1; min-width: 0; }
    .db-dot { display: block; height: 4px; border-radius: 999px; background: var(--line-2); }
    .db-step.is-done .db-dot { background: var(--brand); }
    .db-step-label {
        display: block; margin-top: 6px; font-size: 11px; font-weight: 600; color: var(--ink-3);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .db-step.is-done .db-step-label { color: var(--ink-2); }

    .db-code {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 17px; font-weight: 800; letter-spacing: .04em; color: var(--ink);
    }
    .db-off { font-size: 20px; font-weight: 800; color: var(--brand-2); }

    @media (max-width: 767.98px) {
        .db-nav { position: static; display: flex; overflow-x: auto; gap: 4px; }
        .db-link { white-space: nowrap; }
    }
</style>
@endpush

@endsection
