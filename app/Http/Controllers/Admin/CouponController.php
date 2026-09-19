<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index()
    {
        return view('admin.coupons.index', [
            'coupons' => Coupon::with('categories', 'products')->orderByDesc('active')->latest()->paginate(15),
            // What each code has actually taken off the till.
            'savings' => Order::whereNotNull('coupon_code')
                ->where('status', '!=', 'Cancelled')
                ->selectRaw('coupon_code, COUNT(*) as orders, SUM(discount) as total')
                ->groupBy('coupon_code')
                ->get()
                ->keyBy('coupon_code'),
        ]);
    }

    public function create()
    {
        return view('admin.coupons.create', [
            'coupon' => new Coupon(['type' => Coupon::TYPE_PERCENT, 'active' => true]),
        ] + $this->scopeOptions());
    }

    public function store(Request $request)
    {
        $coupon = Coupon::create($this->validated($request));
        $this->syncScope($coupon, $request);

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon created.');
    }

    public function edit(Coupon $coupon)
    {
        $coupon->load('categories', 'products');

        return view('admin.coupons.edit', ['coupon' => $coupon] + $this->scopeOptions());
    }

    public function update(Request $request, Coupon $coupon)
    {
        $coupon->update($this->validated($request, $coupon));
        $this->syncScope($coupon, $request);

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon updated.');
    }

    /**
     * Switch a coupon on or off without deleting it, so its history survives.
     */
    public function toggle(Coupon $coupon)
    {
        $coupon->update(['active' => ! $coupon->active]);

        return back()->with('success', 'Coupon ' . $coupon->code . ' is now '
            . ($coupon->active ? 'active' : 'disabled') . '.');
    }

    public function destroy(Coupon $coupon)
    {
        // A code that has been used is kept: orders reference it by code and
        // the admin should still be able to see what was given away.
        if ($coupon->used_count > 0) {
            return back()->with('error', 'This coupon has been used on '
                . $coupon->used_count . ' order(s), so it cannot be deleted. Disable it instead.');
        }

        $coupon->delete();

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon deleted.');
    }

    /**
     * Categories and products the admin can limit a coupon to.
     *
     * @return array<string, mixed>
     */
    protected function scopeOptions(): array
    {
        return [
            'allCategories' => Category::orderBy('name')->get(),
            'allProducts' => Product::where('status', true)->orderBy('name')->get(['id', 'name', 'category_id']),
        ];
    }

    /**
     * Attach the chosen categories and products. Choosing none means the
     * coupon applies to the whole cart.
     */
    protected function syncScope(Coupon $coupon, Request $request): void
    {
        $data = $request->validate([
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'products' => ['nullable', 'array'],
            'products.*' => ['integer', 'exists:products,id'],
        ]);

        $coupon->categories()->sync($data['categories'] ?? []);
        $coupon->products()->sync($data['products'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Coupon $coupon = null): array
    {
        // Codes are stored upper-cased, so upper-case before validating —
        // otherwise "taken" passes the unique rule against a stored "TAKEN"
        // and the insert fails on the database constraint instead.
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('coupons')->ignore($coupon?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(Coupon::TYPES)],
            'value' => ['required', 'numeric', 'min:0.01', $request->input('type') === Coupon::TYPE_PERCENT ? 'max:100' : 'max:100000'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'active' => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'Use only letters, numbers, hyphens and underscores.',
            'value.max' => $request->input('type') === Coupon::TYPE_PERCENT
                ? 'A percentage cannot be more than 100.'
                : 'That amount is too large.',
            'expires_at.after' => 'The end date must come after the start date.',
        ]);

        $data['active'] = $request->boolean('active');

        // The form always submits these fields, so leaving one blank sends an
        // explicit null. min_subtotal is NOT NULL with a default of 0, and an
        // explicit null bypasses that default and fails the constraint — so
        // it becomes 0 here. The rest are nullable columns and stay null.
        $data['min_subtotal'] = $data['min_subtotal'] ?? 0;

        return $data;
    }
}
