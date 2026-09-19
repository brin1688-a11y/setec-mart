@extends('layouts.admin')

@section('title', 'Inventory')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold mb-0">Inventory Management</h2>
</div>

@if($lowStockCount > 0)
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span>⚠️ {{ $lowStockCount }} product{{ $lowStockCount > 1 ? 's are' : ' is' }} running low on stock (10 or fewer units).</span>
        <a href="{{ route('admin.inventory.index', ['filter' => 'low_stock']) }}" class="btn btn-sm btn-outline-dark">
            View Low Stock
        </a>
    </div>
@endif

<div class="stat-card mb-4">
    <form method="GET" action="{{ route('admin.inventory.index') }}" class="d-flex gap-2">
        <input
            type="text"
            name="search"
            class="form-control"
            placeholder="Search products..."
            value="{{ request('search') }}"
        >
        <select name="filter" class="form-select" style="max-width: 200px;">
            <option value="">All Products</option>
            <option value="low_stock" {{ request('filter') === 'low_stock' ? 'selected' : '' }}>Low Stock Only</option>
        </select>
        <button type="submit" class="btn btn-outline-success">Filter</button>
    </form>
</div>

<div class="stat-card">

    <div class="d-flex justify-content-end mb-3">
        <input type="search" class="form-control" style="max-width: 280px;"
               data-filters="inventoryTable" placeholder="Search inventory..." aria-label="Search inventory...">
    </div>

    <table class="table-x" id="inventoryTable" data-sortable>
        <thead>
            <tr>
                <th data-sort="text">Name</th>
                <th data-sort="text">Category</th>
                <th data-sort="number">Current Stock</th>
                <th data-sort="text">Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $product)
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->category->name }}</td>
                    <td>
                        @if($product->stock <= 10)
                            <span class="badge bg-danger">{{ $product->stock }} left</span>
                        @else
                            {{ $product->stock }}
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-{{ $product->status ? 'success' : 'secondary' }}">
                            {{ $product->status ? 'Active' : 'Hidden' }}
                        </span>
                    </td>
                    <td class="text-end">
                        <button
                            type="button"
                            class="btn btn-sm btn-success"
                            data-bs-toggle="modal"
                            data-bs-target="#adjustStockModal"
                            data-product-id="{{ $product->id }}"
                            data-product-name="{{ $product->name }}"
                            data-current-stock="{{ $product->stock }}"
                            data-action-url="{{ route('admin.inventory.adjust', $product) }}"
                        >
                            Adjust Stock
                        </button>
                        <a href="{{ route('admin.inventory.history', $product) }}" class="btn btn-sm btn-outline-secondary">
                            History
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        No products found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="table-empty" id="inventoryTable-empty" hidden>No rows match that search.</div>

    <div class="mt-3">
        {{ $products->links() }}
    </div>

</div>

<!-- ADJUST STOCK MODAL -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <form method="POST" id="adjustStockForm">
                @csrf
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Adjust Stock — <span id="modalProductName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">

                    <p class="text-muted small mb-3">Current stock: <strong id="modalCurrentStock"></strong></p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Adjustment Type</label>
                        <select name="type" class="form-select" required>
                            <option value="restock">Restock (add stock)</option>
                            <option value="correction">Correction (fix count)</option>
                            <option value="damage">Damage / Loss (remove stock)</option>
                            <option value="return">Customer Return (add stock)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity Change</label>
                        <input type="number" name="quantity_change" class="form-control" placeholder="e.g. 50 or -5" required>
                        <div class="form-text">Use a positive number to add stock, negative to remove.</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold">Reason (optional)</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="e.g. Weekly restock from supplier"></textarea>
                    </div>

                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('adjustStockModal');
    modal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const productName = button.getAttribute('data-product-name');
        const currentStock = button.getAttribute('data-current-stock');
        const actionUrl = button.getAttribute('data-action-url');

        document.getElementById('modalProductName').textContent = productName;
        document.getElementById('modalCurrentStock').textContent = currentStock;
        document.getElementById('adjustStockForm').setAttribute('action', actionUrl);
    });
});
</script>

@endsection