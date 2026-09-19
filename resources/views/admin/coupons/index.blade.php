@extends('layouts.admin')

@section('title', 'Coupons')

@section('content')

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">Coupons</h2>
        <p class="text-muted mb-0">Discount codes customers can enter at checkout.</p>
    </div>
    <a href="{{ route('admin.coupons.create') }}" class="btn btn-success rounded-pill px-4">
        + New Coupon
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-warning">{{ session('error') }}</div>
@endif

<div class="stat-card">

    @if($coupons->isEmpty())

        <div class="text-center py-5">
            <div style="font-size: 42px;">&#127991;</div>
            <h6 class="fw-bold mt-2">No coupons yet</h6>
            <p class="text-muted small mb-3">Create a code to run your first promotion.</p>
            <a href="{{ route('admin.coupons.create') }}" class="btn btn-success rounded-pill px-4">
                Create a coupon
            </a>
        </div>

    @else

        <div class="d-flex justify-content-end mb-3">
            <input type="search" class="form-control" style="max-width: 280px;"
                   data-filters="couponsTable" placeholder="Search coupons..." aria-label="Search coupons">
        </div>

        <div class="table-responsive">
            <table class="table-x" id="couponsTable" data-sortable>
                <thead>
                    <tr>
                        <th data-sort="text">Code</th>
                        <th data-sort="text">Discount</th>
                        <th>Applies to</th>
                        <th>Conditions</th>
                        <th data-sort="number">Used</th>
                        <th data-sort="number">Given away</th>
                        <th data-sort="text">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($coupons as $coupon)
                        @php $stat = $savings[$coupon->code] ?? null; @endphp
                        <tr>
                            <td>
                                <span class="fw-bold">{{ $coupon->code }}</span>
                                @if($coupon->description)
                                    <div class="text-muted" style="font-size: 12px;">{{ $coupon->description }}</div>
                                @endif
                            </td>

                            <td class="fw-semibold">{{ $coupon->label() }}</td>

                            <td class="small">
                                @if($coupon->appliesToEverything())
                                    <span class="text-muted">All products</span>
                                @else
                                    @foreach($coupon->categories as $c)
                                        <span class="badge bg-light text-dark border">{{ $c->name }}</span>
                                    @endforeach
                                    @if($coupon->products->isNotEmpty())
                                        <span class="badge bg-light text-dark border"
                                              title="{{ $coupon->products->pluck('name')->implode(', ') }}">
                                            {{ $coupon->products->count() }} product(s)
                                        </span>
                                    @endif
                                @endif
                            </td>

                            <td class="small text-muted">
                                @if((float) $coupon->min_subtotal > 0)
                                    <div>Min ${{ number_format($coupon->min_subtotal, 2) }}</div>
                                @endif
                                @if($coupon->max_discount)
                                    <div>Cap ${{ number_format($coupon->max_discount, 2) }}</div>
                                @endif
                                @if($coupon->starts_at)
                                    <div>From {{ $coupon->starts_at->format('M j, Y') }}</div>
                                @endif
                                @if($coupon->expires_at)
                                    <div>Until {{ $coupon->expires_at->format('M j, Y') }}</div>
                                @endif
                                @if(! (float) $coupon->min_subtotal && ! $coupon->max_discount && ! $coupon->starts_at && ! $coupon->expires_at)
                                    <span>&mdash;</span>
                                @endif
                            </td>

                            <td>
                                {{ $coupon->used_count }}@if($coupon->max_uses)<span class="text-muted"> / {{ $coupon->max_uses }}</span>@endif
                            </td>

                            <td>
                                @if($stat)
                                    <span class="fw-semibold">${{ number_format($stat->total, 2) }}</span>
                                    <div class="text-muted" style="font-size: 12px;">{{ $stat->orders }} orders</div>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>

                            <td>
                                <span class="pill pill-{{ ['success'=>'good','info'=>'info','warning'=>'warn','secondary'=>'mute'][$coupon->statusColor()] ?? 'mute' }}">{{ $coupon->statusLabel() }}</span>
                            </td>

                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a href="{{ route('admin.coupons.edit', $coupon) }}"
                                       class="btn btn-sm btn-outline-secondary">Edit</a>

                                    <form method="POST" action="{{ route('admin.coupons.toggle', $coupon) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-outline-{{ $coupon->active ? 'warning' : 'success' }}">
                                            {{ $coupon->active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>

                                    @if($coupon->used_count === 0)
                                        <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}"
                                              onsubmit="return confirm('Delete {{ $coupon->code }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="table-empty" id="couponsTable-empty" hidden>No coupons match that search.</div>
        </div>

        <div class="mt-3">
            {{ $coupons->links() }}
        </div>

    @endif

</div>

@endsection
