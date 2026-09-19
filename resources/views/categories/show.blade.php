@extends('layouts.app')

@section('title', $category->name)

@section('content')

<div class="container py-4 py-lg-5">

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small mb-0">
            <li class="breadcrumb-item"><a href="{{ route('home') }}">@lang('site.nav.home')</a></li>
            <li class="breadcrumb-item"><a href="{{ route('categories.index') }}">@lang('site.nav.categories')</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $category->name }}</li>
        </ol>
    </nav>

    <div class="section-head">
        <div>
            <h1 class="display-hero mb-1" style="font-size: clamp(26px, 4vw, 38px);">{{ $category->name }}</h1>
            <p class="lead-sm mb-0">
                {{ trans_choice('site.categories.count', $products->total(), ['count' => $products->total()]) }}
                @if($category->description) · {{ $category->description }} @endif
            </p>
        </div>
    </div>

    {{-- Jump straight to another category without going back --}}
    <div class="chip-row mb-4">
        <a href="{{ route('products.index') }}" class="chip">@lang('site.categories.all')</a>
        @foreach($categories as $c)
            <a href="{{ route('categories.show', $c) }}" class="chip {{ $c->id === $category->id ? 'on' : '' }}">
                {{ $c->name }}
            </a>
        @endforeach
    </div>

    <form method="GET" class="row g-2 mb-4">
        <div class="col-sm-7 col-lg-5">
            <input type="search" name="search" value="{{ request('search') }}"
                   class="form-control" placeholder="{{ __('site.categories.search') }}">
        </div>
        <div class="col-sm-3 col-lg-3">
            <select name="sort" class="form-select" onchange="this.form.submit()">
                <option value="">@lang('site.categories.sort_new')</option>
                <option value="price_asc" @selected(request('sort') === 'price_asc')>@lang('site.categories.sort_cheap')</option>
                <option value="price_desc" @selected(request('sort') === 'price_desc')>@lang('site.categories.sort_dear')</option>
                <option value="name" @selected(request('sort') === 'name')>@lang('site.categories.sort_name')</option>
            </select>
        </div>
        <div class="col-sm-2">
            <button class="btn btn-success w-100">@lang('site.categories.apply')</button>
        </div>
    </form>

    @if($products->isEmpty())
        <div class="text-center py-5">
            <div style="font-size: 42px;">&#128269;</div>
            <h6 class="fw-bold mt-2">@lang('site.categories.empty_title')</h6>
            <p class="text-muted small mb-3">@lang('site.categories.empty_body')</p>
            <a href="{{ route('products.index') }}" class="btn btn-success px-4">@lang('site.categories.browse_all')</a>
        </div>
    @else
        <div class="row g-3">
            @foreach($products as $product)
                <div class="col-6 col-lg-3">
                    @include('products._card', ['product' => $product])
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $products->links() }}</div>
    @endif

</div>

@endsection
