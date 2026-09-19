@csrf

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">

    <div class="col-md-5">
        <label class="form-label fw-semibold small">Code</label>
        <input type="text" name="code" class="form-control text-uppercase"
               value="{{ old('code', $coupon->code) }}"
               placeholder="SAVE10" required autocomplete="off">
        <div class="form-text">Letters, numbers, hyphens and underscores. Not case-sensitive for customers.</div>
    </div>

    <div class="col-md-7">
        <label class="form-label fw-semibold small">Description <span class="text-muted fw-normal">(optional)</span></label>
        <input type="text" name="description" class="form-control"
               value="{{ old('description', $coupon->description) }}"
               placeholder="New year promotion">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold small">Type</label>
        <select name="type" id="couponType" class="form-select">
            <option value="percent" @selected(old('type', $coupon->type) === 'percent')>Percentage off</option>
            <option value="fixed" @selected(old('type', $coupon->type) === 'fixed')>Fixed amount off</option>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold small">Value</label>
        <div class="input-group">
            <span class="input-group-text" id="valuePrefix">%</span>
            <input type="number" name="value" step="0.01" min="0.01" class="form-control"
                   value="{{ old('value', $coupon->value) }}" required>
        </div>
        <div class="form-text" id="valueHint">10 means 10% off the subtotal.</div>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold small">
            Maximum discount <span class="text-muted fw-normal">(optional)</span>
        </label>
        <div class="input-group">
            <span class="input-group-text">$</span>
            <input type="number" name="max_discount" step="0.01" min="0.01" class="form-control"
                   value="{{ old('max_discount', $coupon->max_discount) }}" placeholder="No cap">
        </div>
        <div class="form-text">Caps a percentage coupon.</div>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold small">
            Minimum subtotal <span class="text-muted fw-normal">(optional)</span>
        </label>
        <div class="input-group">
            <span class="input-group-text">$</span>
            <input type="number" name="min_subtotal" step="0.01" min="0" class="form-control"
                   value="{{ old('min_subtotal', $coupon->min_subtotal) }}" placeholder="0.00">
        </div>
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold small">
            Usage limit <span class="text-muted fw-normal">(optional)</span>
        </label>
        <input type="number" name="max_uses" min="1" class="form-control"
               value="{{ old('max_uses', $coupon->max_uses) }}" placeholder="Unlimited">
        @if($coupon->exists)
            <div class="form-text">Used {{ $coupon->used_count }} time(s) so far.</div>
        @endif
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold small">
            Starts <span class="text-muted fw-normal">(optional)</span>
        </label>
        <input type="date" name="starts_at" class="form-control"
               value="{{ old('starts_at', $coupon->starts_at?->format('Y-m-d')) }}">
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold small">
            Expires <span class="text-muted fw-normal">(optional)</span>
        </label>
        <input type="date" name="expires_at" class="form-control"
               value="{{ old('expires_at', $coupon->expires_at?->format('Y-m-d')) }}">
    </div>

    <div class="col-12">
        <hr class="my-2">
        <label class="form-label fw-semibold small mb-1">What the coupon applies to</label>
        <p class="text-muted small">
            Leave both empty and the coupon discounts the whole cart. Pick categories
            or products and it only discounts those items.
        </p>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold small">Categories</label>
        <select name="categories[]" class="form-select" multiple size="6">
            @foreach($allCategories as $category)
                <option value="{{ $category->id }}"
                    @selected(in_array($category->id, old('categories', $coupon->categories->pluck('id')->all())))>
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
        <div class="form-text">Ctrl / Cmd to pick more than one.</div>
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold small">Individual products</label>
        <input type="text" class="form-control mb-2" id="productFilter" placeholder="Filter products...">
        <select name="products[]" id="productSelect" class="form-select" multiple size="6">
            @foreach($allProducts as $product)
                <option value="{{ $product->id }}"
                    @selected(in_array($product->id, old('products', $coupon->products->pluck('id')->all())))>
                    {{ $product->name }}
                </option>
            @endforeach
        </select>
        <div class="form-text" id="productCount"></div>
    </div>

    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active" value="1" id="active"
                   {{ old('active', $coupon->active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="active">
                Active — customers can use this code right away
            </label>
        </div>
    </div>

</div>

<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-success rounded-pill px-4">
        {{ $coupon->exists ? 'Save Changes' : 'Create Coupon' }}
    </button>
    <a href="{{ route('admin.coupons.index') }}" class="btn btn-light rounded-pill px-4">Cancel</a>
</div>

<script>
    (function () {
        const type = document.getElementById('couponType');
        const prefix = document.getElementById('valuePrefix');
        const hint = document.getElementById('valueHint');

        function sync() {
            const percent = type.value === 'percent';
            prefix.textContent = percent ? '%' : '$';
            hint.textContent = percent
                ? '10 means 10% off the subtotal.'
                : '10 means $10 off the subtotal.';
        }

        type.addEventListener('change', sync);
        sync();

        // A long product list is unusable without a filter.
        const filter = document.getElementById('productFilter');
        const products = document.getElementById('productSelect');
        const count = document.getElementById('productCount');
        const all = [...products.options];

        function showCount() {
            const chosen = all.filter(o => o.selected).length;
            count.textContent = chosen
                ? chosen + ' product(s) selected'
                : 'None selected — the coupon is not limited to specific products.';
        }

        filter.addEventListener('input', function () {
            const term = filter.value.trim().toLowerCase();
            all.forEach(function (option) {
                // Never hide something already chosen, or it looks lost.
                option.hidden = term !== '' && !option.selected
                    && !option.textContent.toLowerCase().includes(term);
            });
        });

        products.addEventListener('change', showCount);
        showCount();
    })();
</script>
