@extends('layouts.admin')

@section('title', 'Stock History')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold mb-0">Stock History — {{ $product->name }}</h2>
    <a href="{{ route('admin.inventory.index') }}" class="btn btn-outline-secondary rounded-pill px-4">
        ← Back to Inventory
    </a>
</div>

<div class="stat-card mb-4">
    <div class="d-flex justify-content-between">
        <span class="text-muted">Current Stock</span>
        <span class="fw-bold fs-5">{{ $product->stock }}</span>
    </div>
</div>

<div class="stat-card">

    <table class="table align-middle">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Change</th>
                <th>Stock After</th>
                <th>Reason</th>
                <th>By</th>
            </tr>
        </thead>
        <tbody>
            @forelse($adjustments as $adjustment)
                <tr>
                    <td>{{ $adjustment->created_at->format('M d, Y g:ia') }}</td>
                    <td>
                        <span class="badge bg-{{ $adjustment->typeColor() }}">
                            {{ ucfirst($adjustment->type) }}
                        </span>
                    </td>
                    <td class="fw-semibold {{ $adjustment->quantity_change >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $adjustment->quantity_change >= 0 ? '+' : '' }}{{ $adjustment->quantity_change }}
                    </td>
                    <td>{{ $adjustment->stock_after }}</td>
                    <td class="text-muted small">{{ $adjustment->reason ?: '—' }}</td>
                    <td>{{ $adjustment->user->name }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        No stock adjustments recorded yet.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-3">
        {{ $adjustments->links() }}
    </div>

</div>

@endsection