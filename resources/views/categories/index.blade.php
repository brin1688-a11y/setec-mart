@extends('layouts.app')

@section('title', __('site.nav.categories'))

@section('content')

@php
    $emoji = fn ($name) => match ($name) {
        'Fruits' => '🍎', 'Vegetables' => '🥬', 'Drinks' => '🥤',
        'Meat' => '🥩', 'Snacks' => '🍪', 'Dairy' => '🥛', default => '🛒',
    };
@endphp

<div class="container py-4 py-lg-5">

    <div class="mb-4">
        <h1 class="display-hero mb-2">@lang('site.categories.title')</h1>
        <p class="lead-sm mb-0">@lang('site.categories.intro')</p>
    </div>

    @if($categories->isEmpty())
        <div class="text-center py-5">
            <div style="font-size: 44px;">&#128722;</div>
            <p class="text-muted mt-2 mb-0">@lang('site.categories.none')</p>
        </div>
    @else
        <div class="row g-3 g-lg-4">
            @foreach($categories as $category)
                <div class="col-6 col-lg-4">
                    <a href="{{ route('categories.show', $category) }}" class="cat-tile">
                        @if($covers[$category->id] ?? null)
                            <img src="{{ $covers[$category->id] }}" alt="" loading="lazy"
                                 onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">
                        @else
                            <div class="emoji">{{ $emoji($category->name) }}</div>
                        @endif

                        <div class="veil"></div>

                        <div class="cap">
                            <b>{{ $category->name }}</b>
                            <span>
                                {{ trans_choice('site.categories.count', $category->products_count, ['count' => $category->products_count]) }}
                            </span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

</div>

@endsection
