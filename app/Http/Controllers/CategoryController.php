<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * All categories, each with a count of what is actually buyable in it.
     */
    public function index()
    {
        $categories = Category::query()
            ->withCount(['products' => fn ($q) => $q->where('status', true)])
            ->orderBy('name')
            ->get();

        return view('categories.index', [
            'categories' => $categories,
            // One image per category to put a face on the tile, taken from a
            // product that actually has one.
            'covers' => $this->coverImages($categories),
        ]);
    }

    /**
     * Everything in one category.
     */
    public function show(Request $request, Category $category)
    {
        $products = $category->products()
            ->where('status', true)
            ->when($request->filled('search'), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->search) . '%']))
            ->when($request->input('sort') === 'price_asc', fn ($q) => $q->orderBy('price'))
            ->when($request->input('sort') === 'price_desc', fn ($q) => $q->orderByDesc('price'))
            ->when($request->input('sort') === 'name', fn ($q) => $q->orderBy('name'))
            ->when(! $request->filled('sort'), fn ($q) => $q->inShopOrder())
            ->paginate(12)
            ->withQueryString();

        return view('categories.show', [
            'category' => $category,
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Category>  $categories
     * @return array<int, string|null>
     */
    protected function coverImages($categories): array
    {
        $covers = [];

        foreach ($categories as $category) {
            $product = $category->products()
                ->where('status', true)
                ->whereNotNull('image')
                ->first();

            $covers[$category->id] = $product?->imageUrl();
        }

        return $covers;
    }
}
