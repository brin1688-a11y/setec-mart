<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;

class HomeController extends Controller
{
    public function index()
    {
        $popular = Product::with('category')
            ->where('status', true)
            ->popular()
            ->take(8)
            ->get();

        return view('home', [
            'categories' => Category::all(),
            'products' => $popular,
            // A shop with no sales yet has nothing to rank, so say "new in"
            // rather than claim these are what everyone is buying.
            'hasSales' => Product::hasAnySales(),
        ]);
    }
}
