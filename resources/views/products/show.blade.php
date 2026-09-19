@extends('layouts.app')

@section('content')

@php
    $emoji = match ($product->category->name) {
        'Fruits' => '🍎',
        'Vegetables' => '🥬',
        'Drinks' => '🥤',
        'Meat' => '🥩',
        'Snacks' => '🍪',
        'Dairy' => '🥛',
        default => '🛒',
    };

    $freeOver = \App\Support\Cambodia::bestFreeThreshold();
    $cheapestFee = \App\Support\Cambodia::cheapestFee();
@endphp

<style>
    .pd-gallery {
        background: #fff;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 6px 24px rgba(0, 0, 0, .06);
        aspect-ratio: 1 / 1;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 120px;
    }

    .pd-gallery img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform .35s ease;
        /* A slow remote image hangs rather than errors, so onerror never
           fires — a tinted backdrop keeps that from looking broken. */
        background: #f4f7f5;
    }

    .pd-gallery:hover img { transform: scale(1.04); }

    .pd-thumbs {
        display: flex; gap: 10px; margin-top: 12px;
        overflow-x: auto; padding-bottom: 4px;
    }

    .pd-thumb {
        flex: 0 0 74px; width: 74px; height: 74px;
        border-radius: 12px; overflow: hidden;
        border: 2px solid transparent;
        background: #f4f7f5; padding: 0;
        transition: border-color .15s ease, transform .15s ease;
    }

    .pd-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .pd-thumb:hover { transform: translateY(-2px); }
    .pd-thumb.on { border-color: #198754; }

    .pd-buybox {
        background: #fff;
        border-radius: 20px;
        padding: 28px;
        box-shadow: 0 6px 24px rgba(0, 0, 0, .06);
    }

    @media (min-width: 992px) {
        .pd-buybox { position: sticky; top: 24px; }
    }

    .pd-price {
        font-size: 38px;
        font-weight: 700;
        color: #198754;
        line-height: 1;
        letter-spacing: -.02em;
    }

    /* A stepper beats a bare number input on a phone. */
    .qty {
        display: inline-flex;
        align-items: center;
        border: 1px solid #dee2e6;
        border-radius: 999px;
        overflow: hidden;
        background: #fff;
    }

    .qty button {
        width: 44px;
        height: 46px;
        border: 0;
        background: transparent;
        font-size: 20px;
        font-weight: 600;
        color: #198754;
        line-height: 1;
    }

    .qty button:disabled { color: #c9d2cd; }
    .qty button:not(:disabled):hover { background: #eef7f1; }

    .qty input {
        width: 56px;
        height: 46px;
        border: 0;
        text-align: center;
        font-size: 17px;
        font-weight: 600;
        -moz-appearance: textfield;
    }

    .qty input::-webkit-outer-spin-button,
    .qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .qty input:focus { outline: none; }

    .pd-fact {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        padding: 10px 0;
        font-size: 14px;
    }

    .pd-fact + .pd-fact { border-top: 1px solid #f0f3f1; }
    .pd-fact-icon { flex: 0 0 20px; font-size: 16px; line-height: 1.35; }

    .stock-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 5px 14px;
        font-size: 13px;
        font-weight: 600;
    }

    .stock-in { background: #e8f3ec; color: #146c43; }
    .stock-low { background: #fff4e0; color: #9a6a00; }
    .stock-out { background: #fdeaec; color: #b02a37; }

    .related-card {
        background: #fff;
        border-radius: 16px;
        overflow: hidden;
        height: 100%;
        box-shadow: 0 4px 14px rgba(0, 0, 0, .05);
        transition: transform .2s ease, box-shadow .2s ease;
        display: block;
        text-decoration: none;
        color: inherit;
    }

    .related-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 26px rgba(0, 0, 0, .09);
        color: inherit;
    }

    .related-card img {
        width: 100%;
        height: 150px;
        object-fit: cover;
        background: #f4f7f5;
    }
</style>

<div class="container py-4 py-lg-5">

    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb small mb-0">
            <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-decoration-none">@lang('site.nav.home')</a></li>
            <li class="breadcrumb-item"><a href="{{ route('products.index') }}" class="text-decoration-none">@lang('site.nav.products')</a></li>
            <li class="breadcrumb-item">
                <a href="{{ route('products.index', ['category' => $product->category_id]) }}" class="text-decoration-none">
                    {{ $product->category->name }}
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
        </ol>
    </nav>

    <div class="row g-4 g-lg-5">

        <!-- GALLERY -->
        <div class="col-lg-6">
            @php $shots = $product->imageUrls(); @endphp

            <div class="pd-gallery">
                @if($shots->isNotEmpty())
                    <img src="{{ $shots->first() }}" alt="{{ $product->name }}" id="pdMainImage"
                         onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">
                @else
                    {{ $emoji }}
                @endif
            </div>

            @if($shots->count() > 1)
                <div class="pd-thumbs" role="tablist" aria-label="Product images">
                    @foreach($shots as $i => $shot)
                        <button type="button" class="pd-thumb {{ $i === 0 ? 'on' : '' }}"
                                data-full="{{ $shot }}" role="tab"
                                aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                                aria-label="Image {{ $i + 1 }} of {{ $shots->count() }}">
                            <img src="{{ $shot }}" alt="" loading="lazy"
                                 onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- BUY BOX -->
        <div class="col-lg-6">
            <div class="pd-buybox">

                <a href="{{ route('products.index', ['category' => $product->category_id]) }}"
                   class="text-success fw-semibold small text-decoration-none">
                    {{ $product->category->name }}
                </a>

                <h1 class="fw-bold mt-2 mb-3" style="font-size: 30px;">{{ $product->name }}</h1>

                <div class="pd-price mb-2">
                    @if($product->isOnSale())
                        ${{ number_format($product->effectivePrice(), 2) }}
                        <s class="pd-was">${{ number_format($product->price, 2) }}</s>
                        <span class="pd-off">&minus;{{ $product->discountPercent() }}%</span>
                    @else
                        ${{ number_format($product->price, 2) }}
                    @endif
                </div>
                <div class="text-muted small mb-3">@lang('site.product.price_per_item')</div>

                @if($product->stock <= 0)
                    <div class="stock-pill stock-out mb-3">@lang('site.product.out_of_stock')</div>
                @elseif($product->stock <= 5)
                    <div class="stock-pill stock-low mb-3">
                        @lang('site.product.low_stock', ['count' => $product->stock])
                    </div>
                @else
                    <div class="stock-pill stock-in mb-3">
                        &#10003; @lang('site.product.in_stock', ['count' => $product->stock])
                    </div>
                @endif

                @if($product->description)
                    <p class="text-muted mb-4">{{ $product->description }}</p>
                @endif

                @auth
                    @if(! Auth::user()->canShop())

                        {{-- Staff browse the shop; buying is for customers. --}}
                        <a href="{{ route('admin.products.edit', $product) }}"
                           class="btn btn-outline-secondary btn-lg rounded-pill w-100">
                            Edit this product
                        </a>
                        <p class="text-muted small text-center mt-2 mb-0">
                            Admin accounts cannot place orders.
                        </p>

                    @elseif($product->stock > 0)

                        <form method="POST" action="{{ route('cart.add', $product) }}" id="buyForm">
                            @csrf

                            <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                                <div class="qty">
                                    <button type="button" id="qtyMinus" aria-label="Decrease quantity">−</button>
                                    <input type="number" name="quantity" id="qtyInput"
                                           value="1" min="1" max="{{ $product->stock }}"
                                           inputmode="numeric" aria-label="Quantity">
                                    <button type="button" id="qtyPlus" aria-label="Increase quantity">+</button>
                                </div>

                                <div class="text-muted small">
                                    @lang('site.product.subtotal')
                                    <span class="fw-bold text-dark fs-6 ms-1" id="lineTotal">
                                        ${{ number_format($product->effectivePrice(), 2) }}
                                    </span>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-sm-flex">
                                <button type="submit" name="action" value="buy_now"
                                        class="btn btn-success btn-lg rounded-pill px-4 flex-fill">
                                    @lang('site.product.buy_now')
                                </button>

                                <button type="submit"
                                        class="btn btn-outline-success btn-lg rounded-pill px-4 flex-fill">
                                    @lang('site.product.add_to_cart')
                                </button>
                            </div>
                        </form>

                    @else

                        <button class="btn btn-secondary btn-lg rounded-pill w-100" disabled>
                            @lang('site.product.out_of_stock')
                        </button>
                        <p class="text-muted small text-center mt-2 mb-0">
                            @lang('site.product.restock_soon')
                        </p>

                    @endif
                @else
                    <a href="{{ route('login') }}" class="btn btn-success btn-lg rounded-pill w-100">
                        @lang('site.product.login_to_buy')
                    </a>
                    <p class="text-muted small text-center mt-2 mb-0">
                        @lang('site.product.new_here') <a href="{{ route('register') }}" class="text-decoration-none">@lang('site.product.create_account')</a>
                    </p>
                @endauth

                <!-- What the customer wants to know before buying -->
                <div class="mt-4 pt-3 border-top">
                    <div class="pd-fact">
                        <span class="pd-fact-icon">🛵</span>
                        <span>
                            @lang('site.product.delivery_from', ['price' => '$' . number_format($cheapestFee, 2)])
                            @if($freeOver)
                                — <strong>@lang('site.product.free_over', ['amount' => '$' . number_format($freeOver, 2)])</strong>
                            @endif
                        </span>
                    </div>
                    <div class="pd-fact">
                        <span class="pd-fact-icon">📱</span>
                        <span>@lang('site.product.pay_with')</span>
                    </div>
                    <div class="pd-fact">
                        <span class="pd-fact-icon">🥬</span>
                        <span>@lang('site.product.freshness')</span>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- RELATED -->
    @if($related->isNotEmpty())
        <div class="mt-5 pt-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">@lang('site.product.more_in', ['category' => $product->category->name])</h5>
                <a href="{{ route('products.index', ['category' => $product->category_id]) }}"
                   class="small text-decoration-none">@lang('site.product.view_all') &rarr;</a>
            </div>

            <div class="row g-3">
                @foreach($related as $item)
                    <div class="col-6 col-lg-3">
                        <a href="{{ route('products.show', $item) }}" class="related-card">
                            @if($item->imageUrl())
                                <img src="{{ $item->imageUrl() }}" alt="{{ $item->name }}" loading="lazy"
                                     onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">
                            @else
                                <div class="d-flex align-items-center justify-content-center"
                                     style="height: 150px; font-size: 48px; background: #f4f7f5;">{{ $emoji }}</div>
                            @endif

                            <div class="p-3">
                                <div class="fw-semibold text-truncate">{{ $item->name }}</div>
                                <div class="text-success fw-bold mt-1">${{ number_format($item->price, 2) }}</div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</div>

<script>
// The gallery is for everyone, signed in or not, so this sits outside the
// signed-in-only block below.
document.addEventListener('DOMContentLoaded', function () {
    const main = document.getElementById('pdMainImage');
    const thumbs = document.querySelectorAll('.pd-thumb');

    if (!main || !thumbs.length) return;

    thumbs.forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            main.src = thumb.dataset.full;
            thumbs.forEach(function (t) {
                t.classList.toggle('on', t === thumb);
                t.setAttribute('aria-selected', t === thumb ? 'true' : 'false');
            });
        });
    });
});
</script>

@auth
    @if($product->stock > 0)
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const input = document.getElementById('qtyInput');
            const minus = document.getElementById('qtyMinus');
            const plus = document.getElementById('qtyPlus');
            const lineTotal = document.getElementById('lineTotal');

            const price = {{ $product->effectivePrice() }};
            const max = {{ $product->stock }};

            function clamp(n) {
                if (isNaN(n) || n < 1) return 1;
                return Math.min(n, max);
            }

            function update() {
                const qty = clamp(parseInt(input.value, 10));
                input.value = qty;
                lineTotal.textContent = '$' + (qty * price).toFixed(2);

                // Stop the buttons offering a quantity we cannot fulfil.
                minus.disabled = qty <= 1;
                plus.disabled = qty >= max;
            }

            minus.addEventListener('click', () => { input.value = clamp(parseInt(input.value, 10) - 1); update(); });
            plus.addEventListener('click', () => { input.value = clamp(parseInt(input.value, 10) + 1); update(); });
            input.addEventListener('input', update);
            input.addEventListener('blur', update);

            update();
        });
        </script>
    @endif
@endauth

@endsection
