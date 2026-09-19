<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * How the catalogue can be ordered, and what each is called on screen.
     */
    public const SORTS = [
        'recommended' => 'Recommended',
        'popular' => 'Best selling',
        'newest' => 'Newest first',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'name' => 'Name A-Z',
    ];

    public function index(Request $request)
    {
        $categories = Category::all();

        $sort = array_key_exists($request->query('sort'), self::SORTS)
            ? $request->query('sort')
            : 'recommended';

        $query = Product::with('category')
            ->where('status', true);

        if ($request->filled('search')) {
            // LOWER(...) LIKE works on PostgreSQL and SQLite alike; ILIKE is
            // PostgreSQL-only and blew up everywhere else.
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($request->search).'%']);
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        $products = $this->sorted($query, $sort, $request)
            ->paginate(12)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => $categories,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'search' => trim((string) $request->query('search')),
        ]);
    }

    /**
     * Apply the chosen order.
     *
     * "Recommended" is the default and deliberately not newest-first: a
     * customer who opens the catalogue twice should not meet the same handful
     * of products above the fold every time. It keeps whatever the shop has
     * pulled forward at the top and shuffles the rest, from a seed held in the
     * session — so the arrangement varies between visitors but holds still
     * while one of them pages through it.
     */
    protected function sorted($query, string $sort, Request $request)
    {
        return match ($sort) {
            'popular' => $query->popular(),
            'newest' => $query->latest('id'),
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name'),
            // Anything the shop pulled forward stays at the front; the rest
            // is shuffled behind it.
            default => $query
                ->orderByRaw('CASE WHEN position IS NULL OR position = 0 THEN 1 ELSE 0 END')
                ->orderBy('position')
                ->shuffled($this->shuffleSeed($request)),
        };
    }

    protected function shuffleSeed(Request $request): int
    {
        return $request->session()->remember(
            'catalogue_seed',
            fn () => random_int(1, 999999)
        );
    }

    public function show(Product $product)
    {
        if (! $product->status) {
            abort(404);
        }

        $product->load('category');

        // Something to look at next if this one is not right — kept to the
        // same category, in stock, and never the product already on screen.
        $related = Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where('status', true)
            ->where('stock', '>', 0)
            ->inRandomOrder()
            ->take(4)
            ->get();

        return view('products.show', compact('product', 'related'));
    }
}
