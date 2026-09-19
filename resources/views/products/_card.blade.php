{{-- One product card, shared by the catalogue, category pages and search. --}}
@php
    $cardEmoji = match ($product->category->name ?? '') {
        'Fruits' => '🍎', 'Vegetables' => '🥬', 'Drinks' => '🥤',
        'Meat' => '🥩', 'Snacks' => '🍪', 'Dairy' => '🥛', default => '🛒',
    };
@endphp

<article class="p-card h-100">
    <a href="{{ route('products.show', $product) }}" class="p-card-media">
        @if($product->imageUrl())
            <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" loading="lazy"
                 onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">
        @else
            <span class="p-card-emoji">{{ $cardEmoji }}</span>
        @endif

        {{-- A live promotion is the thing worth seeing while scrolling, so it
             wins the corner over a low-stock note. --}}
        @if($product->isOnSale())
            <span class="p-flag">&minus;{{ $product->discountPercent() }}%</span>
        @endif

        @if($product->stock <= 0)
            <span class="p-card-flag flag-out">@lang('site.product.out_of_stock')</span>
        @elseif($product->stock <= 5)
            <span class="p-card-flag flag-low">{{ $product->stock }} left</span>
        @endif
    </a>

    <div class="p-card-body">
        <a href="{{ route('categories.show', $product->category_id) }}" class="p-card-cat">
            {{ $product->category->name ?? '' }}
        </a>

        <a href="{{ route('products.show', $product) }}" class="p-card-name">{{ $product->name }}</a>

        <div class="p-card-foot">
            <span class="p-card-price">@if($product->isOnSale())
                <span class="p-sale">${{ number_format($product->effectivePrice(), 2) }}</span>
                <s class="p-was">${{ number_format($product->price, 2) }}</s>
            @else
                ${{ number_format($product->price, 2) }}
            @endif</span>

            @auth
                @if($product->stock > 0 && Auth::user()->canShop())
                    <form method="POST" action="{{ route('cart.add', $product) }}">
                        @csrf
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="p-card-add" aria-label="@lang('site.product.add_to_cart')"
                                title="@lang('site.product.add_to_cart')">+</button>
                    </form>
                @endif
            @else
                <a href="{{ route('login') }}" class="p-card-add" aria-label="@lang('site.product.login_to_buy')"
                   title="@lang('site.product.login_to_buy')">+</a>
            @endauth
        </div>
    </div>
</article>
