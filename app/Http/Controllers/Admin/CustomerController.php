<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /** Orders that never brought money in. */
    protected const DEAD_STATUSES = ['Cancelled'];

    /**
     * How the list can be ordered, and what each is called on screen.
     */
    public const SORTS = [
        'recent' => 'Newest customers',
        'spend' => 'Biggest spenders',
        'orders' => 'Most orders',
        'last_order' => 'Ordered most recently',
        'name' => 'Name A-Z',
    ];

    public function index(Request $request)
    {
        $sort = array_key_exists($request->query('sort'), self::SORTS)
            ? $request->query('sort')
            : 'recent';

        $search = trim((string) $request->query('q'));

        $customers = User::where('role', 'customer')
            ->withCount('orders')
            // Spend and last order come from one aggregate each rather than a
            // query per row, so the list stays flat however many customers grow.
            ->withSum(['orders as spend' => fn ($q) => $q->whereNotIn('status', self::DEAD_STATUSES)], 'total')
            ->withMax('orders as last_order_at', 'created_at')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.strtolower($search).'%';

                $query->where(function ($q) use ($like) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like]);
                });
            })
            ->when($sort === 'spend', fn ($q) => $q->orderByDesc('spend'))
            ->when($sort === 'orders', fn ($q) => $q->orderByDesc('orders_count'))
            ->when($sort === 'last_order', fn ($q) => $q->orderByDesc('last_order_at'))
            ->when($sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($sort === 'recent', fn ($q) => $q->latest())
            ->paginate(15)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'search' => $search,
            'summary' => $this->summary(),
        ]);
    }

    public function show(User $customer)
    {
        $customer->load([
            'orders' => fn ($query) => $query->with('payment', 'items')->latest(),
            'addresses',
        ]);

        $live = $customer->orders->whereNotIn('status', self::DEAD_STATUSES);

        return view('admin.customers.show', [
            'customer' => $customer,
            'spend' => (float) $live->sum('total'),
            'orderCount' => $customer->orders->count(),
            'averageOrder' => $live->count() ? (float) $live->sum('total') / $live->count() : 0.0,
            'activeOrders' => $customer->orders
                ->whereIn('status', ['Pending', 'Confirmed', 'Preparing', 'Out for Delivery'])
                ->count(),
            'favourites' => $this->favourites($customer),
        ]);
    }

    /**
     * Headline numbers for the top of the list.
     *
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        $customers = User::where('role', 'customer');

        return [
            'total' => (clone $customers)->count(),
            'new_this_month' => (clone $customers)->where('created_at', '>=', now()->startOfMonth())->count(),
            'with_orders' => (clone $customers)->has('orders')->count(),
            'revenue' => (float) Order::whereNotIn('status', self::DEAD_STATUSES)->sum('total'),
        ];
    }

    /**
     * What this customer buys most, so the shop knows who it is talking to.
     *
     * @return Collection<int, object>
     */
    protected function favourites(User $customer)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $customer->id)
            ->whereNotIn('orders.status', self::DEAD_STATUSES)
            ->whereNull('order_items.deleted_at')
            ->groupBy('order_items.product_name')
            ->selectRaw('order_items.product_name, SUM(order_items.quantity) as units')
            ->orderByDesc('units')
            ->limit(5)
            ->get();
    }
}
