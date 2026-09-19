<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * The shelves a shop owner actually wants to look at.
     */
    public const FILTERS = [
        '' => 'All',
        'on_sale' => 'On promotion',
        'low' => 'Low stock',
        'out' => 'Out of stock',
        'hidden' => 'Hidden',
        'featured' => 'Pinned to front',
    ];

    public const SORTS = [
        'recent' => 'Newest first',
        'best' => 'Best selling',
        'name' => 'Name A-Z',
        'price_desc' => 'Price: high to low',
        'price_asc' => 'Price: low to high',
        'stock' => 'Least stock first',
    ];

    /** Anything at or below this needs restocking soon. */
    public const LOW_STOCK = 10;

    public function index(Request $request)
    {
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS)
            ? (string) $request->query('filter')
            : '';

        $sort = array_key_exists($request->query('sort'), self::SORTS)
            ? $request->query('sort')
            : 'recent';

        $search = trim((string) $request->query('search'));

        $products = Product::with('category')
            // How many have actually sold, as one aggregate rather than a
            // query per row.
            ->withSum(['orderItems as units_sold' => fn ($q) => $q
                ->whereNull('order_items.deleted_at')
                ->whereHas('order', fn ($o) => $o->where('status', '!=', 'Cancelled')),
            ], 'quantity')
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%']))
            ->when($filter === 'low', fn ($q) => $q->where('stock', '>', 0)->where('stock', '<=', self::LOW_STOCK))
            ->when($filter === 'out', fn ($q) => $q->where('stock', '<=', 0))
            ->when($filter === 'hidden', fn ($q) => $q->where('status', false))
            ->when($filter === 'featured', fn ($q) => $q->where('position', '>', 0))
            ->when($filter === 'on_sale', fn ($q) => $q
                ->whereNotNull('sale_price')
                ->whereColumn('sale_price', '<', 'price')
                ->where(fn ($w) => $w->whereNull('sale_starts_at')->orWhere('sale_starts_at', '<=', now()))
                ->where(fn ($w) => $w->whereNull('sale_ends_at')->orWhere('sale_ends_at', '>=', now())))
            ->when($sort === 'best', fn ($q) => $q->orderByDesc('units_sold'))
            ->when($sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($sort === 'price_desc', fn ($q) => $q->orderByDesc('price'))
            ->when($sort === 'price_asc', fn ($q) => $q->orderBy('price'))
            ->when($sort === 'stock', fn ($q) => $q->orderBy('stock'))
            ->when($sort === 'recent', fn ($q) => $q->latest('id'))
            ->paginate(15)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'search' => $search,
            'counts' => $this->counts(),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Show or hide a product without opening the whole edit form.
     */
    public function toggle(Product $product)
    {
        $product->update(['status' => ! $product->status]);

        return back()->with('success', $product->name.' is now '
            .($product->status ? 'visible to customers.' : 'hidden from the shop.'));
    }

    /**
     * How many products sit behind each filter.
     *
     * @return array<string, int>
     */
    protected function counts(): array
    {
        $onSale = Product::whereNotNull('sale_price')
            ->whereColumn('sale_price', '<', 'price')
            ->where(fn ($w) => $w->whereNull('sale_starts_at')->orWhere('sale_starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('sale_ends_at')->orWhere('sale_ends_at', '>=', now()))
            ->count();

        return [
            '' => Product::count(),
            'on_sale' => $onSale,
            'low' => Product::where('stock', '>', 0)->where('stock', '<=', self::LOW_STOCK)->count(),
            'out' => Product::where('stock', '<=', 0)->count(),
            'hidden' => Product::where('status', false)->count(),
            'featured' => Product::where('position', '>', 0)->count(),
        ];
    }

    /**
     * What the shelves are worth and what needs attention.
     *
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        return [
            'products' => Product::count(),
            'visible' => Product::where('status', true)->count(),
            // What the stock on hand would fetch at today's prices.
            'stock_value' => (float) Product::where('status', true)
                ->selectRaw('COALESCE(SUM(COALESCE(sale_price, price) * stock), 0) as v')
                ->value('v'),
            'needs_attention' => Product::where('stock', '<=', self::LOW_STOCK)->count(),
        ];
    }

    public function create()
    {
        $categories = Category::all();

        return view('admin.products.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);

        $validated['image'] = $this->resolveImage($request);

        $product = Product::create($validated);

        $this->syncGallery($request, $product);

        return redirect()->route('admin.products.index')->with('success', 'Product created.');
    }

    public function edit(Product $product)
    {
        $categories = Category::all();

        return view('admin.products.edit', compact('product', 'categories'));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $this->validateProduct($request);

        $validated['image'] = $this->resolveImage($request, $product->image);

        $product->update($validated);

        $this->syncGallery($request, $product);

        return redirect()->route('admin.products.index')->with('success', 'Product updated.');
    }

    public function destroy(Product $product)
    {
        $this->deleteIfLocal($product->image);

        $product->delete();

        return back()->with('success', 'Product deleted.');
    }

    /**
     * Shared validation for store/update.
     */
    /**
     * Add newly uploaded or pasted images, drop the ones ticked for removal,
     * and apply the running order the admin dragged them into.
     */
    protected function syncGallery(Request $request, Product $product): void
    {
        $data = $request->validate([
            'gallery_files' => ['nullable', 'array', 'max:10'],
            'gallery_files.*' => ['image', 'max:4096'],
            'gallery_urls' => ['nullable', 'string', 'max:8000'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],
            'image_order' => ['nullable', 'string'],
        ], [
            'gallery_files.max' => 'You can add at most 10 images at a time.',
            'gallery_files.*.image' => 'Every gallery file has to be an image.',
        ]);

        // 1. Remove — only images that belong to this product.
        if (! empty($data['remove_images'])) {
            $doomed = $product->images()->whereIn('id', $data['remove_images'])->get();

            foreach ($doomed as $image) {
                if ($image->isUploaded()) {
                    $this->deleteIfLocal($image->path);
                }

                $image->delete();
            }
        }

        $next = (int) $product->images()->max('position') + 1;

        // 2. Uploads
        foreach ($request->file('gallery_files', []) as $file) {
            $product->images()->create([
                'path' => $file->store('products', 'public'),
                'position' => $next++,
            ]);
        }

        // 3. Pasted URLs, one per line
        foreach (preg_split('/\r?\n/', (string) ($data['gallery_urls'] ?? '')) as $url) {
            $url = trim($url);

            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $product->images()->create(['path' => $url, 'position' => $next++]);
        }

        // 4. Order — the first image is the one shown everywhere else.
        if (! empty($data['image_order'])) {
            $ids = array_filter(array_map('intval', explode(',', $data['image_order'])));
            $owned = $product->images()->pluck('id')->all();

            $position = 0;

            foreach ($ids as $id) {
                if (in_array($id, $owned, true)) {
                    ProductImage::whereKey($id)->update(['position' => $position++]);
                }
            }
        }

        // Keep the legacy column pointing at the first gallery image so any
        // code still reading products.image stays correct.
        $first = $product->images()->first();

        if ($first) {
            $product->forceFill(['image' => $first->path])->save();
        }
    }

    protected function validateProduct(Request $request): array
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            // A promotion has to actually be a saving, or the storefront would
            // advertise a discount of nothing.
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after:sale_starts_at'],
            'stock' => ['required', 'integer', 'min:0'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'image_file' => ['nullable', 'image', 'max:4096'],
        ], [
            'sale_price.lt' => 'The promotion price has to be lower than the normal price.',
            'sale_ends_at.after' => 'The promotion has to end after it starts.',
        ]);

        $validated['status'] = $request->boolean('status');

        // An empty box means "no position", which is the back of the queue.
        $validated['position'] = (int) ($validated['position'] ?? 0);

        // Clearing the price ends the promotion, so its dates go with it —
        // otherwise they would sit there waiting to surprise someone.
        if (blank($validated['sale_price'] ?? null)) {
            $validated['sale_price'] = null;
            $validated['sale_starts_at'] = null;
            $validated['sale_ends_at'] = null;
        }

        // These are handled separately in resolveImage(), not mass-assigned.
        unset($validated['image_url'], $validated['image_file']);

        return $validated;
    }

    /**
     * Work out what the product's `image` column should be:
     * an uploaded file wins over a pasted URL, which wins over
     * leaving the existing image untouched.
     */
    protected function resolveImage(Request $request, ?string $existing = null): ?string
    {
        if ($request->hasFile('image_file')) {
            $this->deleteIfLocal($existing);

            return $request->file('image_file')->store('products', 'public');
        }

        if ($request->filled('image_url')) {
            $this->deleteIfLocal($existing);

            return $request->input('image_url');
        }

        return $existing;
    }

    /**
     * Delete a previously uploaded file from storage, but never try to
     * delete an external URL.
     */
    protected function deleteIfLocal(?string $image): void
    {
        if ($image && ! str_starts_with($image, 'http')) {
            Storage::disk('public')->delete($image);
        }
    }
}
