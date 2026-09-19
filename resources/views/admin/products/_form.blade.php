@csrf

@if(isset($product))
    @method('PATCH')
@endif

<div class="row g-3">

    <div class="col-md-6">
        <label class="form-label fw-semibold">Product Name</label>
        <input
            type="text"
            name="name"
            class="form-control"
            value="{{ old('name', $product->name ?? '') }}"
            required
        >
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Category</label>
        <select name="category_id" class="form-select" required>
            <option value="">Select category</option>
            @foreach($categories as $category)
                <option
                    value="{{ $category->id }}"
                    {{ old('category_id', $product->category_id ?? '') == $category->id ? 'selected' : '' }}
                >
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">Description</label>
        <textarea name="description" class="form-control" rows="3">{{ old('description', $product->description ?? '') }}</textarea>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">Price ($)</label>
        <input
            type="number"
            step="0.01"
            min="0"
            name="price"
            class="form-control"
            value="{{ old('price', $product->price ?? '') }}"
            required
        >
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">Stock</label>
        <input
            type="number"
            min="0"
            name="stock"
            class="form-control"
            value="{{ old('stock', $product->stock ?? 0) }}"
            required
        >
    </div>

    {{-- ---------------------------------------------------- promotion --}}
    <div class="col-12">
        <hr class="my-2">
        <label class="form-label fw-semibold mb-1">Promotion</label>
        <p class="text-muted small mb-0">
            Set a promotion price and the storefront shows it struck through
            against the normal price, with a discount badge on the card. Leave
            it empty for no promotion. The dates are optional: no start means
            it runs from now, no end means until you clear the price.
        </p>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">Promotion price ($)</label>
        <input
            type="number"
            step="0.01"
            min="0"
            name="sale_price"
            class="form-control @error('sale_price') is-invalid @enderror"
            value="{{ old('sale_price', isset($product) && $product->sale_price !== null ? $product->sale_price : '') }}"
            placeholder="No promotion"
        >
        @error('sale_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">Starts</label>
        <input
            type="datetime-local"
            name="sale_starts_at"
            class="form-control @error('sale_starts_at') is-invalid @enderror"
            value="{{ old('sale_starts_at', isset($product) ? $product->sale_starts_at?->format('Y-m-d\TH:i') : null) }}"
        >
        @error('sale_starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">Ends</label>
        <input
            type="datetime-local"
            name="sale_ends_at"
            class="form-control @error('sale_ends_at') is-invalid @enderror"
            value="{{ old('sale_ends_at', isset($product) ? $product->sale_ends_at?->format('Y-m-d\TH:i') : null) }}"
        >
        @error('sale_ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @isset($product)
        <div class="col-12">
            <div class="pf-state">
                @if($product->isOnSale())
                    <span class="pill pill-good">Running now</span>
                    Customers pay <strong>${{ number_format($product->effectivePrice(), 2) }}</strong>
                    instead of ${{ number_format($product->price, 2) }}
                    &mdash; {{ $product->discountPercent() }}% off.
                @elseif($product->saleIsScheduled())
                    <span class="pill pill-info">Scheduled</span>
                    Starts {{ $product->sale_starts_at->format('D, j M Y 	 g:ia') }}.
                @elseif($product->sale_price !== null)
                    <span class="pill pill-mute">Not running</span>
                    The promotion has ended or is not cheaper than the normal price.
                @else
                    <span class="pill pill-mute">No promotion</span>
                    This product sells at its normal price.
                @endif
            </div>
        </div>
    @endisset

    {{-- ----------------------------------------------------- position --}}
    <div class="col-md-4">
        <label class="form-label fw-semibold">Show first (position)</label>
        <input
            type="number"
            min="0"
            max="9999"
            name="position"
            class="form-control @error('position') is-invalid @enderror"
            value="{{ old('position', $product->position ?? 0) }}"
        >
        @error('position')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <small class="text-muted">
            Lower comes first on the storefront. Leave at 0 to let it sit with
            the rest, newest first.
        </small>
    </div>

    <div class="col-12">
        <hr class="my-2">
        <label class="form-label fw-semibold mb-1">Images</label>
        <p class="text-muted small">
            Add as many as you like. The first one is what shows on the product
            card and in search — drag to reorder.
        </p>
    </div>

    @if(isset($product) && $product->exists && $product->images->isNotEmpty())
        <div class="col-12">
            <div class="gallery-grid" id="galleryGrid">
                @foreach($product->images as $image)
                    <div class="gallery-item" draggable="true" data-id="{{ $image->id }}">
                        <img src="{{ $image->url() }}" alt=""
                             onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';">

                        <span class="gallery-badge">{{ $loop->first ? 'Main' : $loop->iteration }}</span>

                        <label class="gallery-remove" title="Remove this image">
                            <input type="checkbox" name="remove_images[]" value="{{ $image->id }}">
                            <span>&times;</span>
                        </label>
                    </div>
                @endforeach
            </div>

            <input type="hidden" name="image_order" id="imageOrder"
                   value="{{ $product->images->pluck('id')->implode(',') }}">

            <div class="form-text">Ticked images are deleted when you save.</div>
        </div>
    @endif

    <div class="col-md-6">
        <label class="form-label fw-semibold">Add images</label>
        <input type="file" name="gallery_files[]" class="form-control" accept="image/*" multiple
               data-preview="filePreview">
        <small class="text-muted">JPG, PNG, GIF or WEBP. Up to 10 at a time, max 4MB each.</small>

        {{-- Shown straight from the chosen files, before anything is uploaded,
             so a wrong picture is caught here rather than on the storefront. --}}
        <div class="img-preview" id="filePreview" hidden></div>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Or paste image URLs</label>
        <textarea name="gallery_urls" class="form-control" rows="3"
                  placeholder="https://example.com/one.jpg&#10;https://example.com/two.jpg"
                  data-preview="urlPreview">{{ old('gallery_urls') }}</textarea>
        <small class="text-muted">One per line.</small>

        <div class="img-preview" id="urlPreview" hidden></div>
    </div>

    <div class="col-12 form-check ms-1">
        <input
            type="checkbox"
            name="status"
            value="1"
            class="form-check-input"
            id="status"
            {{ old('status', $product->status ?? true) ? 'checked' : '' }}
        >
        <label class="form-check-label" for="status">
            Visible to customers
        </label>
    </div>

</div>

@push('styles')
<style>
    .img-preview {
        display: flex; flex-wrap: wrap; gap: 8px;
        margin-top: 10px;
    }

    .img-preview figure {
        position: relative; margin: 0;
        width: 74px; height: 74px;
        border-radius: 12px; overflow: hidden;
        background: var(--card-2); border: 1px solid var(--line);
    }
    .img-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }

    /* A file that will not load is worth saying out loud, not showing blank. */
    .img-preview figure.is-bad {
        display: grid; place-items: center;
        border-color: var(--bad); color: var(--bad);
        font-size: 11px; font-weight: 700; text-align: center; padding: 4px;
    }

    .img-preview figcaption {
        position: absolute; left: 0; right: 0; bottom: 0;
        padding: 2px 5px;
        background: rgba(0,0,0,.55); color: #fff;
        font-size: 10px; font-weight: 700;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .pf-state {
        display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
        padding: 12px 14px; border-radius: 14px;
        background: var(--card-2); font-size: 14px; color: var(--ink-2);
    }
    .pf-state strong { color: var(--ink); }
</style>
@endpush


@push('scripts')
<script>
/**
 * Show a picture the moment it is chosen.
 *
 * Files are read straight from the browser with object URLs, so nothing is
 * uploaded to find out whether it is the right image; pasted URLs are simply
 * loaded. Either way a mistake is caught here rather than on the storefront.
 */
(function () {
    var MAX_BYTES = 4 * 1024 * 1024;

    function frame(src, caption, bad) {
        var figure = document.createElement('figure');

        if (bad) {
            figure.className = 'is-bad';
            figure.textContent = bad;
            figure.title = caption;
            return figure;
        }

        var img = document.createElement('img');
        img.src = src;
        img.alt = '';
        // A pasted URL that does not resolve should say so, not sit blank.
        img.onerror = function () {
            figure.className = 'is-bad';
            figure.textContent = 'Will not load';
        };

        var label = document.createElement('figcaption');
        label.textContent = caption;

        figure.appendChild(img);
        figure.appendChild(label);
        figure.title = caption;

        return figure;
    }

    function show(box, frames) {
        box.innerHTML = '';
        frames.forEach(function (f) { box.appendChild(f); });
        box.hidden = frames.length === 0;
    }

    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        var box = document.getElementById(input.dataset.preview);
        if (!box) return;

        input.addEventListener('change', function () {
            var frames = Array.prototype.map.call(input.files || [], function (file) {
                var tooBig = file.size > MAX_BYTES ? 'Over 4MB' : null;
                return frame(tooBig ? null : URL.createObjectURL(file), file.name, tooBig);
            });

            show(box, frames);
        });
    });

    document.querySelectorAll('textarea[data-preview]').forEach(function (area) {
        var box = document.getElementById(area.dataset.preview);
        if (!box) return;

        var timer = null;

        function draw() {
            var frames = area.value
                .split('\n')
                .map(function (line) { return line.trim(); })
                .filter(function (line) { return /^https?:\/\//i.test(line); })
                .slice(0, 10)
                .map(function (url) { return frame(url, url.split('/').pop() || url); });

            show(box, frames);
        }

        area.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(draw, 400);
        });

        if (area.value.trim()) draw();
    });
})();
</script>
@endpush

<div class="mt-4">
    <button type="submit" class="btn btn-success rounded-pill px-4">
        {{ isset($product) ? 'Update Product' : 'Create Product' }}
    </button>
    <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary rounded-pill px-4">
        Cancel
    </a>
</div>

<style>
    .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 10px;
    }

    .gallery-item {
        position: relative;
        border: 1px solid var(--line, #dee2e6);
        border-radius: 12px;
        overflow: hidden;
        aspect-ratio: 1 / 1;
        cursor: grab;
        background: var(--card-2, #f5f7f7);
    }

    .gallery-item.dragging { opacity: .4; }
    .gallery-item img { width: 100%; height: 100%; object-fit: cover; display: block; }

    .gallery-badge {
        position: absolute; left: 6px; top: 6px;
        background: rgba(0,0,0,.66); color: #fff;
        font-size: 10.5px; font-weight: 700;
        padding: 2px 7px; border-radius: 999px;
    }

    .gallery-remove {
        position: absolute; right: 6px; top: 6px;
        width: 22px; height: 22px; border-radius: 50%;
        background: rgba(0,0,0,.66); color: #fff;
        display: grid; place-items: center;
        font-size: 15px; line-height: 1; cursor: pointer;
        margin: 0;
    }

    .gallery-remove input { position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; margin: 0; }
    .gallery-remove:has(input:checked) { background: #cf3b4a; }
    .gallery-item:has(input:checked) { outline: 2px solid #cf3b4a; }
    .gallery-item:has(input:checked) img { opacity: .35; }
</style>

<script>
(function () {
    const grid = document.getElementById('galleryGrid');
    const order = document.getElementById('imageOrder');
    if (!grid || !order) return;

    let dragging = null;

    function saveOrder() {
        order.value = [...grid.children].map(el => el.dataset.id).join(',');
        // Renumber the badges so "Main" always sits on the first one.
        [...grid.children].forEach(function (el, i) {
            const badge = el.querySelector('.gallery-badge');
            if (badge) badge.textContent = i === 0 ? 'Main' : (i + 1);
        });
    }

    grid.addEventListener('dragstart', function (e) {
        dragging = e.target.closest('.gallery-item');
        if (dragging) dragging.classList.add('dragging');
    });

    grid.addEventListener('dragend', function () {
        if (dragging) dragging.classList.remove('dragging');
        dragging = null;
        saveOrder();
    });

    grid.addEventListener('dragover', function (e) {
        e.preventDefault();
        const over = e.target.closest('.gallery-item');
        if (!over || !dragging || over === dragging) return;

        const box = over.getBoundingClientRect();
        const after = (e.clientX - box.left) > box.width / 2;
        grid.insertBefore(dragging, after ? over.nextSibling : over);
    });
})();
</script>
