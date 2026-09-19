@extends('layouts.app')

@section('content')

<div class="container py-5">

    <div class="mb-5">
        <h1 class="fw-bold">🛒 Your Cart</h1>
        <p class="text-muted">@lang('site.cart.intro')</p>
    </div>

    @if($cart->items->isEmpty())

        <div class="text-center py-5 bg-white rounded-4 shadow-sm">
            <div style="font-size: 70px;">🛒</div>
            <h4 class="mt-3">@lang('site.cart.empty_title')</h4>
            <p class="text-muted">@lang('site.cart.empty_body')</p>
            <a href="{{ route('products.index') }}" class="btn btn-success rounded-pill px-4 mt-2">
                Shop Products
            </a>
        </div>

    @else

        <div class="row g-4">

            <!-- CART ITEMS -->
            <div class="col-lg-8">

                <div class="bg-white shadow-sm rounded-4 p-4">

                    @foreach($cart->items as $item)

                        <div class="cart-row py-3 {{ !$loop->last ? 'border-bottom' : '' }}">

                            <div class="cart-row-main">

                                {{-- The real photograph, the way every other
                                     page shows it; the category mark is only
                                     the fallback for a product with none. --}}
                                <a href="{{ route('products.show', $item->product) }}" class="cart-thumb">
                                    @if($url = $item->product->imageUrl())
                                        <img src="{{ $url }}" alt="{{ $item->product->name }}" loading="lazy"
                                             onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">
                                    @else
                                        <span class="cart-thumb-mark">
                                            @switch($item->product->category->name ?? '')
                                                @case('Fruits') &#127822; @break
                                                @case('Vegetables') &#129382; @break
                                                @case('Drinks') &#129380; @break
                                                @case('Meat') &#129385; @break
                                                @case('Snacks') &#127850; @break
                                                @case('Dairy') &#129371; @break
                                                @default &#128722;
                                            @endswitch
                                        </span>
                                    @endif
                                </a>

                                <div>
                                    <h6 class="mb-1">
                                        <a href="{{ route('products.show', $item->product) }}" class="text-decoration-none text-dark">
                                            {{ $item->product->name }}
                                        </a>
                                    </h6>
                                    <small class="text-muted">
                                        @if($item->product->isOnSale())
                                            <span class="text-success fw-semibold">${{ number_format($item->unitPrice(), 2) }}</span>
                                            <s class="text-muted">${{ number_format($item->product->price, 2) }}</s> each
                                        @else
                                            ${{ number_format($item->unitPrice(), 2) }} each
                                        @endif
                                    </small>
                                </div>

                            </div>

                            <div class="cart-row-actions">

                                <form method="POST" action="{{ route('cart.update', $item) }}" class="d-flex align-items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <input
                                        type="number"
                                        name="quantity"
                                        value="{{ $item->quantity }}"
                                        min="1"
                                        class="form-control form-control-sm"
                                        style="width: 70px;"
                                    >
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        Update
                                    </button>
                                </form>

                                <span class="fw-bold" style="min-width: 70px;">
                                    ${{ number_format($item->subtotal(), 2) }}
                                </span>

                                <form method="POST" action="{{ route('cart.remove', $item) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        Remove
                                    </button>
                                </form>

                            </div>

                        </div>

                    @endforeach

                </div>

            </div>

            <!-- SUMMARY -->
            <div class="col-lg-4">

                <div class="bg-white shadow-sm rounded-4 p-4">

                    <h5 class="fw-bold mb-4">@lang('site.cart.summary')</h5>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Items ({{ $cart->totalItems() }})</span>
                        <span>${{ number_format($cart->totalPrice(), 2) }}</span>
                    </div>

                    <div class="d-flex justify-content-between mb-3 pb-3 border-bottom">
                        <span class="text-muted">Delivery</span>
                        <span>@lang('site.cart.calculated_at_checkout')</span>
                    </div>

                    <div class="d-flex justify-content-between mb-4">
                        <span class="fw-bold">Total</span>
                        <span class="price">${{ number_format($cart->totalPrice(), 2) }}</span>
                    </div>

                    <a href="{{ route('checkout.index') }}" class="btn btn-success btn-lg w-100 rounded-pill">
                        Proceed to Checkout
                    </a>

                </div>

            </div>

        </div>

    @endif

</div>

@endsection