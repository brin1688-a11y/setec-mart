@extends('layouts.admin')

@section('title', 'Categories')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold mb-0">Categories</h2>
    <a href="{{ route('admin.categories.create') }}" class="btn btn-success rounded-pill px-4">
        + Add Category
    </a>
</div>

<div class="stat-card">

    <div class="d-flex justify-content-end mb-3">
        <input type="search" class="form-control" style="max-width: 280px;"
               data-filters="categoriesTable" placeholder="Search categories..." aria-label="Search categories...">
    </div>

    <table class="table-x" id="categoriesTable" data-sortable>
        <thead>
            <tr>
                <th data-sort="text">Name</th>
                <th data-sort="text">Description</th>
                <th data-sort="number">Products</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($categories as $category)
                <tr>
                    <td>{{ $category->name }}</td>
                    <td>{{ $category->description }}</td>
                    <td>{{ $category->products_count }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-outline-success">
                            Edit
                        </a>
                        <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="d-inline" onsubmit="return confirm('Delete this category?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">
                        No categories yet.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="table-empty" id="categoriesTable-empty" hidden>No rows match that search.</div>

</div>

@endsection