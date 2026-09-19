<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Orders that never brought money in — excluded from every revenue figure.
     */
    protected const DEAD_STATUSES = ['Cancelled'];

    public function index()
    {
        $today = Carbon::today();

        return view('admin.dashboard', [
            'kpis' => $this->kpis($today),
            'trend' => $this->revenueTrend(14),
            'statusBreakdown' => $this->statusBreakdown(),
            'paymentSplit' => $this->paymentSplit(),
            'topProducts' => $this->topProducts(),
            'recentOrders' => Order::with('user', 'payment')->latest()->take(8)->get(),
            'lowStock' => Product::where('stock', '<=', 10)->orderBy('stock')->take(6)->get(),
            'outOfStockCount' => Product::where('stock', '<=', 0)->count(),
            'needsAttention' => $this->needsAttention(),
        ]);
    }

    /**
     * Headline numbers, each with the previous equivalent period so the view
     * can show which way things are moving.
     *
     * @return array<string, mixed>
     */
    protected function kpis(Carbon $today): array
    {
        $revenue = fn ($from, $to) => (float) Order::whereNotIn('status', self::DEAD_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->sum('total');

        $orders = fn ($from, $to) => Order::whereBetween('created_at', [$from, $to])->count();

        $last30 = [$today->copy()->subDays(29), $today->copy()->endOfDay()];
        $prev30 = [$today->copy()->subDays(59), $today->copy()->subDays(30)->endOfDay()];

        $revenue30 = $revenue(...$last30);
        $orders30 = $orders(...$last30);

        // Average order value has to divide by the same set the revenue came
        // from — counting cancelled orders in the denominator would drag it down.
        $paidOrders30 = Order::whereNotIn('status', self::DEAD_STATUSES)
            ->whereBetween('created_at', $last30)
            ->count();

        return [
            'revenue_30' => $revenue30,
            'revenue_30_delta' => $this->percentChange($revenue30, $revenue(...$prev30)),

            'orders_30' => $orders30,
            'orders_30_delta' => $this->percentChange($orders30, $orders(...$prev30)),

            'avg_order' => $paidOrders30 > 0 ? $revenue30 / $paidOrders30 : 0.0,

            'revenue_today' => $revenue($today->copy(), $today->copy()->endOfDay()),
            'orders_today' => $orders($today->copy(), $today->copy()->endOfDay()),

            'revenue_total' => (float) Order::whereNotIn('status', self::DEAD_STATUSES)->sum('total'),
            'orders_total' => Order::count(),

            'customers' => User::where('role', 'customer')->count(),
            'new_customers_30' => User::where('role', 'customer')
                ->where('created_at', '>=', $last30[0])
                ->count(),

            'products' => Product::count(),
        ];
    }

    /**
     * Daily revenue for the last N days, zero-filled so the line has no gaps.
     *
     * @return array<int, array{date: string, label: string, revenue: float, orders: int}>
     */
    protected function revenueTrend(int $days): array
    {
        $start = Carbon::today()->subDays($days - 1);

        $rows = Order::whereNotIn('status', self::DEAD_STATUSES)
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue, COUNT(*) as orders')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $counts = Order::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders')
            ->groupBy('day')
            ->pluck('orders', 'day');

        $trend = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();

            $trend[] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'revenue' => round((float) ($rows[$key] ?? 0), 2),
                'orders' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * How many orders sit in each status, in the order they flow through.
     *
     * @return array<int, array{status: string, count: int, color: string}>
     */
    protected function statusBreakdown(): array
    {
        $counts = Order::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return collect(Order::STATUSES)
            ->map(fn ($status) => [
                'status' => $status,
                'count' => (int) ($counts[$status] ?? 0),
                'color' => (new Order(['status' => $status]))->statusColor(),
            ])
            ->all();
    }

    /**
     * COD versus KHQR, and how much of the KHQR money has actually landed.
     *
     * @return array<string, mixed>
     */
    protected function paymentSplit(): array
    {
        $byMethod = Payment::selectRaw('method, COUNT(*) as c')->groupBy('method')->pluck('c', 'method');

        $cod = (int) ($byMethod[Payment::METHOD_COD] ?? 0);
        // Older orders used a manual "bank_transfer" option before KHQR existed.
        $khqr = (int) ($byMethod[Payment::METHOD_KHQR] ?? 0);
        $legacy = (int) ($byMethod['bank_transfer'] ?? 0);

        return [
            'cod' => $cod,
            'khqr' => $khqr,
            'legacy' => $legacy,
            'total' => $cod + $khqr + $legacy,
            'khqr_paid' => Payment::where('method', Payment::METHOD_KHQR)
                ->where('status', Payment::STATUS_PAID)->count(),
            'khqr_awaiting' => Payment::where('method', Payment::METHOD_KHQR)
                ->where('status', Payment::STATUS_PENDING)->count(),
        ];
    }

    /**
     * Best sellers by units moved, ignoring cancelled orders.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function topProducts()
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', self::DEAD_STATUSES)
            ->groupBy('order_items.product_name')
            ->select('order_items.product_name')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.quantity * order_items.price) as revenue')
            ->orderByDesc(DB::raw('SUM(order_items.quantity)'))
            ->take(5)
            ->get();
    }

    /**
     * The short list of things an admin should actually act on today.
     *
     * @return array<int, array{label: string, count: int, url: string, tone: string}>
     */
    protected function needsAttention(): array
    {
        $items = [];

        $pending = Order::where('status', 'Pending')->count();

        if ($pending > 0) {
            $items[] = [
                'label' => $pending . ' ' . str('order')->plural($pending) . ' waiting to be confirmed',
                'count' => $pending,
                'url' => route('admin.orders.index', ['status' => 'Pending']),
                'tone' => 'warning',
            ];
        }

        $outOfStock = Product::where('stock', '<=', 0)->count();

        if ($outOfStock > 0) {
            $items[] = [
                'label' => $outOfStock . ' ' . str('product')->plural($outOfStock) . ' out of stock',
                'count' => $outOfStock,
                'url' => route('admin.inventory.index'),
                'tone' => 'danger',
            ];
        }

        // A KHQR payment still pending long after its QR expired means the
        // customer walked away, or a webhook never landed.
        $stuck = Payment::where('method', Payment::METHOD_KHQR)
            ->where('status', Payment::STATUS_PENDING)
            ->where('created_at', '<', now()->subHour())
            ->count();

        if ($stuck > 0) {
            $items[] = [
                'label' => $stuck . ' KHQR ' . str('payment')->plural($stuck) . ' unresolved for over an hour',
                'count' => $stuck,
                'url' => route('admin.orders.index'),
                'tone' => 'secondary',
            ];
        }

        return $items;
    }

    /**
     * Percent change, or null when there is no baseline to compare against.
     */
    protected function percentChange(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
