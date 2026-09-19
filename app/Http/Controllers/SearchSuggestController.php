<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suggestions for the storefront search box.
 *
 * Typing the first letters of a product should show it, so a customer who is
 * not sure of the spelling — or of the English name — still lands on the
 * right thing instead of an empty result page.
 */
class SearchSuggestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        // One letter matches most of the shop, which is noise, not help.
        if (mb_strlen($term) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $like = '%'.mb_strtolower($term).'%';

        $products = Product::with('category', 'images')
            ->where('status', true)
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            // A product whose name starts with what was typed is the more
            // likely target, so lift those above the ones that merely contain it.
            ->orderByRaw('CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($term).'%'])
            ->orderBy('name')
            ->take(6)
            ->get()
            ->map(fn (Product $product) => [
                'name' => $product->name,
                'category' => $product->category?->name,
                'price' => number_format($product->effectivePrice(), 2),
                'was' => $product->isOnSale() ? number_format($product->price, 2) : null,
                'image' => $product->imageUrl(),
                'in_stock' => $product->stock > 0,
                'url' => route('products.show', $product),
            ]);

        $categories = Category::whereRaw('LOWER(name) LIKE ?', [$like])
            ->orderBy('name')
            ->take(3)
            ->get()
            ->map(fn (Category $category) => [
                'name' => $category->name,
                'url' => route('categories.show', $category),
            ]);

        return response()->json([
            'products' => $products,
            'categories' => $categories,
        ]);
    }
}
