<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    const LOW_STOCK_THRESHOLD = 10;

    public function index(Request $request)
    {
        $query = Product::with('category');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('filter') && $request->filter === 'low_stock') {
            $query->where('stock', '<=', self::LOW_STOCK_THRESHOLD);
        }

        $products = $query->orderBy('stock', 'asc')->paginate(15)->withQueryString();

        $lowStockCount = Product::where('stock', '<=', self::LOW_STOCK_THRESHOLD)->count();

        return view('admin.inventory.index', compact('products', 'lowStockCount'));
    }

    public function adjust(Request $request, Product $product)
    {
        $request->validate([
            'type' => 'required|in:' . implode(',', StockAdjustment::TYPES),
            'quantity_change' => 'required|integer|not_in:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $newStock = $product->stock + $request->quantity_change;

        if ($newStock < 0) {
            return back()->with('error', 'Stock cannot go below zero. Current stock: ' . $product->stock);
        }

        DB::transaction(function () use ($product, $request, $newStock) {
            $product->update(['stock' => $newStock]);

            StockAdjustment::create([
                'product_id' => $product->id,
                'user_id' => Auth::id(),
                'type' => $request->type,
                'quantity_change' => $request->quantity_change,
                'stock_after' => $newStock,
                'reason' => $request->reason,
            ]);
        });

        return back()->with('success', "Stock updated for {$product->name}. New stock: {$newStock}.");
    }

    public function history(Product $product)
    {
        $adjustments = $product->stockAdjustments()
            ->with('user')
            ->paginate(20);

        return view('admin.inventory.history', compact('product', 'adjustments'));
    }
}