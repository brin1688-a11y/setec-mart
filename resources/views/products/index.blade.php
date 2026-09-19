@extends('layouts.app')

@section('title', 'Products')

@section('content')

@php
    $selectedCategory = request('category')
        ? $categories->firstWhere('id', (int) request('category'))
        : null;

    $hasFilters = $search !== '' || $selectedCategory || $sort !== 'recommended';
@endphp

<div class="container py-4 py-lg-5">

    <div class="section-head mb-3">
        <div>
            <h1 class="display-hero mb-1" style="font-size: clamp(26px, 4vw, 38px);">
                {{ $selectedCategory?->name ?? 'All products' }}
            </h1>
            <p class="lead-sm mb-0">
                {{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}
                @if($search !== '')
                    for &ldquo;{{ $search }}&rdquo;
                @endif
                &middot; fresh to your door across Cambodia
            </p>
        </div>
    </div>

    {{-- Categories as chips rather than a dropdown: one tap instead of
         open-pick-apply, and you can see everything on offer at once. --}}
    <div class="chip-row mb-3">
        <a href="{{ route('products.index', array_filter(['search' => $search ?: null, 'sort' => $sort !== 'recommended' ? $sort : null])) }}"
           class="chip {{ $selectedCategory ? '' : 'on' }}">All</a>

        @foreach($categories as $category)
            <a href="{{ route('products.index', array_filter(['category' => $category->id, 'search' => $search ?: null, 'sort' => $sort !== 'recommended' ? $sort : null])) }}"
               class="chip {{ $selectedCategory?->id === $category->id ? 'on' : '' }}">
                {{ $category->name }}
            </a>
        @endforeach
    </div>

    {{-- Search and order, on one slim line. --}}
    <form method="GET" action="{{ route('products.index') }}" class="cat-bar mb-4">
        @if($selectedCategory)
            <input type="hidden" name="category" value="{{ $selectedCategory->id }}">
        @endif

        <div class="cat-search">
            <span class="ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="m20 20-3.6-3.6"></path>
                </svg>
            </span>
            <input type="search" name="search" value="{{ $search }}"
                   placeholder="Search products…" aria-label="Search products">
        </div>

        <select name="sort" class="form-select" aria-label="Sort products" onchange="this.form.submit()">
            @foreach($sorts as $key => $label)
                <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-success rounded-pill px-4">Search</button>

        @if($hasFilters)
            <a href="{{ route('products.index') }}" class="cat-clear">Clear</a>
        @endif
    </form>

    @if($products->isEmpty())

        <div class="cat-empty">
            <div class="cat-empty-mark">&#128269;</div>
            <h5 class="fw-bold mb-1">Nothing here</h5>
            <p class="text-muted mb-3">
                @if($search !== '')
                    We could not find anything for &ldquo;{{ $search }}&rdquo;.
                @else
                    This part of the shop is empty just now.
                @endif
            </p>
            <a href="{{ route('products.index') }}" class="btn btn-success rounded-pill px-4">
                Browse everything
            </a>
        </div>

    @else

        {{-- The shared card, so the catalogue, the category pages and search
             all look the same and gain the same badges. --}}
        <div class="row g-3 g-lg-4">
            @foreach($products as $product)
                <div class="col-6 col-lg-3">
                    @include('products._card', ['product' => $product])
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $products->links() }}
        </div>

    @endif

</div>

@push('styles')
<style>
    .cat-bar {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 12px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--r-lg);
        box-shadow: var(--sh-1);
    }

    .cat-search { position: relative; flex: 1 1 260px; min-width: 0; }

    .cat-search input {
        width: 100%; height: 44px;
        padding: 0 16px 0 42px;
        border: 1px solid var(--line-2); border-radius: 999px;
        background: var(--surface-2); color: var(--ink);
        font-size: 15px; font-family: inherit;
        transition: background .14s ease, border-color .14s ease, box-shadow .14s ease;
    }
    .cat-search input:focus {
        outline: 0; background: var(--surface);
        border-color: var(--brand); box-shadow: 0 0 0 3px var(--ring);
    }

    .cat-search .ico {
        position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
        color: var(--ink-3); pointer-events: none;
        display: grid; place-items: center;
        transition: color .14s ease;
    }
    .cat-search .ico svg { width: 17px; height: 17px; display: block; }
    .cat-search:focus-within .ico { color: var(--brand); }

    .cat-bar .form-select {
        height: 44px; width: auto; min-width: 190px;
        border-radius: 999px; border-color: var(--line-2);
        font-size: 14.5px; font-weight: 600;
    }

    .cat-bar .btn { height: 44px; }

    .cat-clear {
        font-size: 14px; font-weight: 600; color: var(--ink-3);
        text-decoration: none; padding: 0 6px;
    }
    .cat-clear:hover { color: var(--bad); }

    .cat-empty {
        text-align: center; padding: 64px 24px;
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); box-shadow: var(--sh-1);
    }
    .cat-empty-mark { font-size: 46px; line-height: 1; margin-bottom: 12px; }

    @media (max-width: 575.98px) {
        .cat-bar { gap: 8px; }
        .cat-bar .form-select { flex: 1 1 auto; min-width: 0; }
        .cat-bar .btn { flex: 1 1 auto; }
    }
</style>
@endpush

@endsection
