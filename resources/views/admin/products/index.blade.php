@extends('layouts.admin')

@section('title', 'Products')

@section('content')

<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">Products</h2>
        <p class="text-muted mb-0" style="font-size: 14px;">
            {{ number_format($summary['products']) }} in the catalogue
            &middot; {{ number_format($summary['visible']) }} on sale to customers
        </p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <form method="GET" action="{{ route('admin.products.index') }}" class="d-flex gap-2">
            @if($filter !== '')<input type="hidden" name="filter" value="{{ $filter }}">@endif
            @if($sort !== 'recent')<input type="hidden" name="sort" value="{{ $sort }}">@endif
            <input type="search" name="search" value="{{ $search }}" class="form-control"
                   style="max-width: 220px;" placeholder="Product name…">
            <button class="chip-btn" type="submit">Search</button>
        </form>

        <a href="{{ route('admin.products.create') }}" class="btn btn-success rounded-pill px-4">
            + New product
        </a>
    </div>
</div>

<div class="bento mb-4">
    @foreach([
        ['In the catalogue', number_format($summary['products']), number_format($summary['visible']).' visible'],
        ['Stock value', '$'.number_format($summary['stock_value'], 2), 'at current prices'],
        ['On promotion', number_format($counts['on_sale']), 'running right now'],
        ['Needs restocking', number_format($summary['needs_attention']), 'low or out of stock'],
    ] as [$label, $value, $note])
        <div class="stat-card b-3">
            <div class="k-label">{{ $label }}</div>
            <div class="k-value">{{ $value }}</div>
            <div class="k-sub">{{ $note }}</div>
        </div>
    @endforeach
</div>

<div class="stat-card">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div class="pr-tabs">
            @foreach($filters as $key => $label)
                <a class="pr-tab {{ $filter === $key ? 'is-on' : '' }}"
                   href="{{ route('admin.products.index', array_filter(['filter' => $key, 'search' => $search ?: null, 'sort' => $sort !== 'recent' ? $sort : null])) }}">
                    {{ $label }}
                    <span class="pr-tab-n">{{ $counts[$key] ?? 0 }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.products.index') }}">
            @if($filter !== '')<input type="hidden" name="filter" value="{{ $filter }}">@endif
            @if($search !== '')<input type="hidden" name="search" value="{{ $search }}">@endif
            <select name="sort" class="form-select" style="min-width: 190px;" onchange="this.form.submit()">
                @foreach($sorts as $key => $label)
                    <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-x">
            <thead>
                <tr>
                    <th colspan="2">Product</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th class="text-end">Sold</th>
                    <th>Visibility</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                    <tr>
                        <td style="width: 60px;">
                            <div class="pr-thumb">
                                @if($url = $product->imageUrl())
                                    <img src="{{ $url }}" alt="" loading="lazy">
                                @else
                                    <span>&#128722;</span>
                                @endif
                            </div>
                        </td>

                        <td>
                            <a href="{{ route('admin.products.edit', $product) }}" class="pr-name">
                                {{ $product->name }}
                            </a>
                            <div class="pr-dim">
                                {{ $product->category->name ?? 'Uncategorised' }}
                                @if($product->position > 0)
                                    &middot; <span class="pill pill-info">Pinned #{{ $product->position }}</span>
                                @endif
                            </div>
                        </td>

                        <td>
                            @if($product->isOnSale())
                                <span class="fw-semibold">${{ number_format($product->effectivePrice(), 2) }}</span>
                                <div class="pr-dim">
                                    <s>${{ number_format($product->price, 2) }}</s>
                                    <span class="pill pill-bad">&minus;{{ $product->discountPercent() }}%</span>
                                </div>
                            @elseif($product->saleIsScheduled())
                                <span class="fw-semibold">${{ number_format($product->price, 2) }}</span>
                                <div class="pr-dim">
                                    <span class="pill pill-info">Promo {{ $product->sale_starts_at->format('j M') }}</span>
                                </div>
                            @else
                                <span class="fw-semibold">${{ number_format($product->price, 2) }}</span>
                            @endif
                        </td>

                        <td>
                            @if($product->stock <= 0)
                                <span class="pill pill-bad">Out of stock</span>
                            @elseif($product->stock <= \App\Http\Controllers\Admin\ProductController::LOW_STOCK)
                                <span class="pill pill-warn">{{ $product->stock }} left</span>
                            @else
                                {{ $product->stock }}
                            @endif
                        </td>

                        <td class="text-end">{{ (int) $product->units_sold }}</td>

                        <td>
                            {{-- One click to take something off the shelf, rather
                                 than opening the whole edit form for it. --}}
                            <form method="POST" action="{{ route('admin.products.toggle', $product) }}">
                                @csrf @method('PATCH')
                                <button type="submit"
                                        class="pill {{ $product->status ? 'pill-good' : 'pill-mute' }} pr-toggle"
                                        title="{{ $product->status ? 'Hide from the shop' : 'Show in the shop' }}">
                                    {{ $product->status ? 'Visible' : 'Hidden' }}
                                </button>
                            </form>
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex gap-2">
                                <a href="{{ route('admin.products.edit', $product) }}" class="chip-btn">Edit</a>

                                <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                                      onsubmit="return confirm('Delete {{ $product->name }}? This cannot be undone.');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="chip-btn pr-delete">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="table-empty">
                                @if($search !== '')
                                    Nothing matches &ldquo;{{ $search }}&rdquo;.
                                @elseif($filter !== '')
                                    Nothing under {{ strtolower($filters[$filter]) }}.
                                @else
                                    No products yet.
                                    <a href="{{ route('admin.products.create') }}">Add the first one</a>.
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $products->links() }}
    </div>

</div>

@push('styles')
<style>
    .pr-tabs { display: flex; flex-wrap: wrap; gap: 6px; }

    .pr-tab {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 7px 13px; border-radius: 999px;
        border: 1px solid var(--line);
        font-size: 13.5px; font-weight: 600;
        color: var(--ink-2); text-decoration: none; white-space: nowrap;
        transition: .15s ease;
    }
    .pr-tab:hover { border-color: var(--line-2); color: var(--ink); }
    .pr-tab.is-on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }

    .pr-tab-n {
        min-width: 20px; padding: 0 6px; border-radius: 999px;
        background: var(--card-2); color: var(--ink-3);
        font-size: 11.5px; font-weight: 700; text-align: center;
    }
    .pr-tab.is-on .pr-tab-n { background: rgba(255,255,255,.24); color: var(--accent-ink); }

    .pr-thumb {
        width: 46px; height: 46px; border-radius: 11px; overflow: hidden;
        background: var(--card-2); border: 1px solid var(--line);
        display: grid; place-items: center; font-size: 18px;
    }
    .pr-thumb img { width: 100%; height: 100%; object-fit: cover; }

    .pr-name { font-weight: 700; color: var(--ink); text-decoration: none; }
    .pr-name:hover { color: var(--accent); }
    .pr-dim { font-size: 12px; color: var(--ink-3); margin-top: 3px; }

    .pr-toggle { border: 0; cursor: pointer; font-family: inherit; }
    .pr-toggle:hover { filter: brightness(.94); }

    .pr-delete { color: var(--bad); }
    .pr-delete:hover { border-color: var(--bad); background: var(--bad-w); }
</style>
@endpush

@endsection
