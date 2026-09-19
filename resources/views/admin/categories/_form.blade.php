@csrf

@if(isset($category))
    @method('PATCH')
@endif

<div class="mb-3">
    <label class="form-label fw-semibold">Category Name</label>
    <input
        type="text"
        name="name"
        class="form-control"
        value="{{ old('name', $category->name ?? '') }}"
        required
    >
</div>

<div class="mb-4">
    <label class="form-label fw-semibold">Description</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $category->description ?? '') }}</textarea>
</div>

<button type="submit" class="btn btn-success rounded-pill px-4">
    {{ isset($category) ? 'Update Category' : 'Create Category' }}
</button>
<a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary rounded-pill px-4">
    Cancel
</a>