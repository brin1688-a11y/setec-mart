@extends('layouts.app')

@section('content')

<style>
    .hero-photos {
        position: relative;
    }
    .hero-photo {
        border-radius: 16px;
        object-fit: cover;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
        background: #fff;
    }
    .hero-photo-cart {
        width: 130px;
        height: 130px;
        transform: rotate(-6deg);
        margin-top: 30px;
    }
    .hero-photo-veg {
        width: 160px;
        height: 160px;
        transform: rotate(3deg);
    }
    .hero-photo-apple {
        width: 130px;
        height: 130px;
        transform: rotate(-3deg);
        margin-top: 30px;
    }

    .category-photo-wrap {
        width: 72px;
        height: 72px;
        margin: 0 auto;
        border-radius: 50%;
        overflow: hidden;
        background: #f1f8f4;
    }
    .category-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
</style>

<!-- HERO -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center">

            <div class="col-lg-7">
                <span class="badge bg-light text-success px-3 py-2 rounded-pill mb-3">
                    🥗 Fresh • Fast • Easy
                </span>

                <h1>
                    Fresh Groceries<br>
                    Delivered to Your Door
                </h1>

                <p class="lead mt-4">
                    {{ config('app.name') }} brings fresh groceries to your door across Cambodia. Browse the aisles, pay by KHQR or cash on delivery, and get same-day delivery in Phnom Penh.
                </p>

                <a href="{{ route('products.index') }}"
                    class="btn btn-light btn-lg rounded-pill px-5 mt-3">
                        Shop Now →
                </a>
            </div>

            <div class="col-lg-5 text-center mt-5 mt-lg-0">
                <div class="hero-photos d-flex justify-content-center align-items-center gap-3">
                    <img src="{{ asset('images/hero/cart.png') }}"
                         alt="Shopping cart"
                         class="hero-photo hero-photo-cart">

                    <img src="{{ asset('images/hero/vegetables.png') }}"
                         alt="Fresh vegetables"
                         class="hero-photo hero-photo-veg">

                    <img src="{{ asset('images/hero/apple.png') }}"
                         alt="Fresh apple"
                         class="hero-photo hero-photo-apple">
                </div>
            </div>

        </div>
    </div>
</section>


<!-- CATEGORIES -->
<section class="container py-5">

    <div class="text-center mb-5">
        <h2 class="fw-bold">Shop by Category</h2>
        <p class="text-muted">
            Everything fresh and delicious in one place
        </p>
    </div>

    <div class="row g-4">

        @foreach($categories as $category)

            <div class="col-6 col-md-4 col-lg-2">

                <div class="category-card shadow-sm">

                    @php
                        $categoryFile = strtolower(str_replace(' ', '-', $category->name)) . '.jpg';
                        $categoryPath = public_path('images/categories/' . $categoryFile);
                    @endphp

                    <div class="category-photo-wrap">
                        <img src="{{ file_exists($categoryPath)
                                        ? asset('images/categories/' . $categoryFile)
                                        : asset('images/categories/default.jpg') }}"
                             alt="{{ $category->name }}"
                             class="category-photo">
                    </div>

                    <h6 class="mt-3 mb-0">
                        {{ $category->name }}
                    </h6>

                </div>

            </div>

        @endforeach

    </div>

</section>


<!-- PRODUCTS -->
<section class="container py-5">

    <div class="d-flex justify-content-between align-items-center mb-5">

        <div>
            <h2 class="fw-bold mb-1">
                {{ $hasSales ? 'Popular Products' : 'New In' }}
            </h2>

            <p class="text-muted mb-0">
                {{ $hasSales
                    ? 'What our customers are buying most'
                    : 'Fresh on the shelves this week' }}
            </p>
        </div>

        <a href="{{ route('products.index') }}"
            class="btn btn-outline-success rounded-pill px-4">
                View All →
        </a>

    </div>


    <div class="row g-4">

        @foreach($products as $product)

            <div class="col-sm-6 col-md-4 col-lg-3">

                <div class="product-card shadow-sm">

                    @if($product->isOnSale())
                        <span class="p-flag">&minus;{{ $product->discountPercent() }}%</span>
                    @endif

                    <a href="{{ route('products.show', $product) }}" class="product-image text-decoration-none p-0 overflow-hidden">
                        @php
                            $fallbackFile = strtolower(str_replace(' ', '-', $product->category->name)) . '.jpg';
                            $fallbackPath = public_path('images/categories/' . $fallbackFile);
                            $fallbackUrl = file_exists($fallbackPath)
                                ? asset('images/categories/' . $fallbackFile)
                                : asset('images/categories/default.jpg');
                        @endphp

                        <img src="{{ $product->imageUrl() ?: $fallbackUrl }}"
                             alt="{{ $product->name }}"
                             class="w-100 h-100"
                             style="object-fit: cover;" loading="lazy"
                             onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">
                    </a>

                    <div class="p-4">

                        <small class="text-success">
                            {{ $product->category->name }}
                        </small>

                        <h5 class="mt-2">
                            <a href="{{ route('products.show', $product) }}" class="text-decoration-none text-dark">
                                {{ $product->name }}
                            </a>
                        </h5>

                        <p class="text-muted small">
                            {{ $product->description }}
                        </p>

                        <div class="d-flex justify-content-between align-items-center">

                            <span class="price">
                                @if($product->isOnSale())
                <span class="p-sale">${{ number_format($product->effectivePrice(), 2) }}</span>
                <s class="p-was">${{ number_format($product->price, 2) }}</s>
            @else
                ${{ number_format($product->price, 2) }}
            @endif
                            </span>

                            @auth
                                {{-- Staff browse the shop but never buy from it. --}}
                                @if(Auth::user()->canShop())
                                    <form method="POST" action="{{ route('cart.add', $product) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-success rounded-circle">
                                            +
                                        </button>
                                    </form>
                                @endif
                            @else
                                <a href="{{ route('login') }}" class="btn btn-success rounded-circle">
                                    +
                                </a>
                            @endauth

                        </div>

                    </div>

                </div>

            </div>

        @endforeach

    </div>

</section>

@endsection