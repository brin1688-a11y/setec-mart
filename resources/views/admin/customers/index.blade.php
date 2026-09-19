@extends('layouts.admin')

@section('title', 'Customers')

@section('content')

<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">Customers</h2>
        <p class="text-muted mb-0" style="font-size: 14px;">
            {{ number_format($summary['total']) }} registered
            &middot; {{ number_format($summary['with_orders']) }} have ordered
        </p>
    </div>

    <form method="GET" action="{{ route('admin.customers.index') }}" class="d-flex gap-2">
        @if($sort !== 'recent')<input type="hidden" name="sort" value="{{ $sort }}">@endif
        <input type="search" name="q" value="{{ $search }}" class="form-control"
               style="max-width: 250px;" placeholder="Name, email or phone…">
        <button class="chip-btn" type="submit">Search</button>
        @if($search !== '')
            <a href="{{ route('admin.customers.index', $sort !== 'recent' ? ['sort' => $sort] : []) }}"
               class="chip-btn">Clear</a>
        @endif
    </form>
</div>

<div class="bento mb-4">
    @foreach([
        ['Customers', number_format($summary['total']), 'registered accounts'],
        ['New this month', number_format($summary['new_this_month']), 'signed up since the 1st'],
        ['Have ordered', number_format($summary['with_orders']), 'at least one order'],
        ['Revenue', '$'.number_format($summary['revenue'], 2), 'cancelled orders excluded'],
    ] as [$label, $value, $note])
        <div class="stat-card b-3">
            <div class="k-label">{{ $label }}</div>
            <div class="k-value">{{ $value }}</div>
            <div class="k-sub">{{ $note }}</div>
        </div>
    @endforeach
</div>

<div class="stat-card">

    <div class="c-tabs mb-3">
        @foreach($sorts as $key => $label)
            <a class="c-tab {{ $sort === $key ? 'is-on' : '' }}"
               href="{{ route('admin.customers.index', array_filter(['sort' => $key, 'q' => $search ?: null])) }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="table-responsive">
        <table class="table-x">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th class="text-end">Orders</th>
                    <th class="text-end">Spent</th>
                    <th>Last order</th>
                    <th>Joined</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    <tr>
                        <td>
                            <a href="{{ route('admin.customers.show', $customer) }}" class="c-name">
                                {{ $customer->name }}
                            </a>
                            <div class="c-dim">{{ $customer->email }}</div>
                        </td>

                        <td>
                            @if($customer->phone)
                                {{-- Tap to call: most of this is done from a phone. --}}
                                <a href="tel:{{ $customer->phone }}">{{ $customer->formattedPhone() }}</a>
                            @else
                                <span class="c-dim">No phone</span>
                            @endif

                            @if($customer->telegram)
                                <div class="c-dim">&#64;{{ $customer->telegram }}</div>
                            @endif
                        </td>

                        <td class="text-end">{{ $customer->orders_count }}</td>

                        <td class="text-end fw-semibold">${{ number_format((float) $customer->spend, 2) }}</td>

                        <td>
                            @if($customer->last_order_at)
                                {{ \Illuminate\Support\Carbon::parse($customer->last_order_at)->diffForHumans() }}
                            @else
                                <span class="c-dim">Never</span>
                            @endif
                        </td>

                        <td>{{ $customer->created_at->format('j M Y') }}</td>

                        <td class="text-end">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="chip-btn">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="table-empty">
                                @if($search !== '')
                                    Nobody matches &ldquo;{{ $search }}&rdquo;.
                                @else
                                    No customers yet.
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $customers->links() }}
    </div>

</div>

@push('styles')
<style>
    .c-tabs { display: flex; flex-wrap: wrap; gap: 6px; }

    .c-tab {
        padding: 7px 13px; border-radius: 999px;
        border: 1px solid var(--line);
        font-size: 13.5px; font-weight: 600;
        color: var(--ink-2); text-decoration: none; white-space: nowrap;
        transition: .15s ease;
    }
    .c-tab:hover { border-color: var(--line-2); color: var(--ink); }
    .c-tab.is-on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }

    .c-name { font-weight: 700; color: var(--ink); text-decoration: none; }
    .c-name:hover { color: var(--accent); }
    .c-dim { font-size: 12px; color: var(--ink-3); margin-top: 2px; }
</style>
@endpush

@endsection
