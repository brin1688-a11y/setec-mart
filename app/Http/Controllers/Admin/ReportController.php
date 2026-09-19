<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales reporting: what was bought, and what came in, day by day and month by
 * month.
 *
 * Every figure is grouped from one daily query and bucketed in PHP rather than
 * with a date function in SQL. Grouping by month needs TO_CHAR on PostgreSQL
 * and strftime on SQLite, and the test suite runs on SQLite while the shop runs
 * on PostgreSQL — a year is at most 366 rows, so the portable way costs nothing.
 */
class ReportController extends Controller
{
    /** Orders that never brought money in. Counted, but never as revenue. */
    protected const DEAD_STATUSES = ['Cancelled'];

    public function index(Request $request)
    {
        [$view, $from, $to] = $this->period($request);

        $days = $this->dailyRows($from, $to);
        $rows = $view === 'monthly' ? $this->byMonth($days, $from) : $this->byDay($days, $from, $to);

        return view('admin.reports.index', [
            'view' => $view,
            'rows' => $rows,
            'summary' => $this->summarise($rows),
            'topProducts' => $this->topProducts($from, $to),
            'from' => $from,
            'to' => $to,
            'month' => $from->format('Y-m'),
            'year' => (int) $from->format('Y'),
            'years' => $this->yearsWithOrders(),
        ]);
    }

    /**
     * The same table, as a file a shop keeps or sends to an accountant.
     */
    public function export(Request $request): StreamedResponse
    {
        [$view, $from, $to] = $this->period($request);

        $days = $this->dailyRows($from, $to);
        $rows = $view === 'monthly' ? $this->byMonth($days, $from) : $this->byDay($days, $from, $to);

        $name = 'setec-mart-'.$view.'-'.$from->format($view === 'monthly' ? 'Y' : 'Y-m').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Period', 'Orders', 'Cancelled', 'Items', 'Subtotal', 'Discount', 'Delivery', 'Revenue']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['label'], $row['orders'], $row['cancelled'], $row['items'],
                    number_format($row['subtotal'], 2, '.', ''),
                    number_format($row['discount'], 2, '.', ''),
                    number_format($row['delivery'], 2, '.', ''),
                    number_format($row['revenue'], 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * Which stretch of time the page is showing.
     *
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    protected function period(Request $request): array
    {
        $view = $request->query('view') === 'monthly' ? 'monthly' : 'daily';

        if ($view === 'monthly') {
            $year = (int) $request->query('year', now()->year);

            // A year outside anything the shop could have traded in is a typo
            // or a probe; fall back rather than scanning nothing.
            if ($year < 2020 || $year > now()->year + 1) {
                $year = now()->year;
            }

            $from = Carbon::create($year, 1, 1)->startOfDay();

            return [$view, $from, $from->copy()->endOfYear()];
        }

        $month = (string) $request->query('month', now()->format('Y-m'));

        try {
            $from = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        } catch (\Throwable) {
            $from = now()->startOfMonth();
        }

        return [$view, $from, $from->copy()->endOfMonth()];
    }

    /**
     * Every figure the report needs, one row per calendar day.
     *
     * CASE WHEN rather than FILTER or a date function: both PostgreSQL and
     * SQLite understand it, and the suite runs on SQLite.
     *
     * @return Collection<string, array<string, mixed>>
     */
    protected function dailyRows(Carbon $from, Carbon $to): Collection
    {
        $dead = self::DEAD_STATUSES[0];

        // Orders placed before the shop broke the price down store only a
        // total, and a report whose subtotal column reads $0.03 against
        // $163.05 of revenue looks broken rather than incomplete. The identity
        // total = subtotal - discount + delivery reconstructs it exactly.
        $subtotal = 'COALESCE(subtotal, total + COALESCE(discount, 0) - COALESCE(delivery_fee, 0))';

        $money = Order::whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [$dead])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) as orders', [$dead])
            ->selectRaw("SUM(CASE WHEN status <> ? THEN {$subtotal} ELSE 0 END) as subtotal", [$dead])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN COALESCE(discount, 0) ELSE 0 END) as discount', [$dead])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN COALESCE(delivery_fee, 0) ELSE 0 END) as delivery', [$dead])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN total ELSE 0 END) as revenue', [$dead])
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        // Units sold lives in the lines, not the order, and a line the
        // customer removed should not count as sold.
        $units = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereNotIn('orders.status', self::DEAD_STATUSES)
            ->whereNull('order_items.deleted_at')
            ->selectRaw('DATE(orders.created_at) as day')
            ->selectRaw('SUM(order_items.quantity) as items')
            ->groupBy('day')
            ->pluck('items', 'day');

        return $money->map(fn ($row, $day) => [
            'orders' => (int) $row->orders,
            'cancelled' => (int) $row->cancelled,
            'items' => (int) ($units[$day] ?? 0),
            'subtotal' => (float) $row->subtotal,
            'discount' => (float) $row->discount,
            'delivery' => (float) $row->delivery,
            'revenue' => (float) $row->revenue,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function byDay(Collection $days, Carbon $from, Carbon $to): array
    {
        $rows = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();

            $rows[] = array_merge(
                ['key' => $key, 'label' => $date->format('D j M')],
                $this->figuresFor($days, [$key])
            );
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function byMonth(Collection $days, Carbon $from): array
    {
        $rows = [];

        for ($m = 1; $m <= 12; $m++) {
            $month = Carbon::create((int) $from->format('Y'), $m, 1);

            $keys = [];
            for ($d = $month->copy(); $d->month === $m; $d->addDay()) {
                $keys[] = $d->toDateString();
            }

            $rows[] = array_merge(
                ['key' => $month->format('Y-m'), 'label' => $month->format('M Y')],
                $this->figuresFor($days, $keys)
            );
        }

        return $rows;
    }

    /**
     * Add up whichever days belong to one row of the report.
     *
     * @param  Collection<string, array<string, mixed>>  $days
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function figuresFor(Collection $days, array $keys): array
    {
        $figures = [
            'orders' => 0, 'cancelled' => 0, 'items' => 0,
            'subtotal' => 0.0, 'discount' => 0.0, 'delivery' => 0.0, 'revenue' => 0.0,
        ];

        foreach ($keys as $key) {
            if (! $row = $days->get($key)) {
                continue;
            }

            foreach ($figures as $k => $_) {
                $figures[$k] += $row[$k];
            }
        }

        foreach (['subtotal', 'discount', 'delivery', 'revenue'] as $k) {
            $figures[$k] = round($figures[$k], 2);
        }

        return $figures;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function summarise(array $rows): array
    {
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        $orders = (int) $sum('orders');
        $revenue = round((float) $sum('revenue'), 2);

        $best = collect($rows)->sortByDesc('revenue')->first();

        return [
            'orders' => $orders,
            'cancelled' => (int) $sum('cancelled'),
            'items' => (int) $sum('items'),
            'subtotal' => round((float) $sum('subtotal'), 2),
            'discount' => round((float) $sum('discount'), 2),
            'delivery' => round((float) $sum('delivery'), 2),
            'revenue' => $revenue,
            'average' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'best_label' => ($best && $best['revenue'] > 0) ? $best['label'] : null,
            'best_revenue' => $best['revenue'] ?? 0.0,
        ];
    }

    /**
     * What actually sold in the period, by units.
     *
     * @return Collection<int, object>
     */
    protected function topProducts(Carbon $from, Carbon $to): Collection
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereNotIn('orders.status', self::DEAD_STATUSES)
            ->whereNull('order_items.deleted_at')
            ->groupBy('order_items.product_name')
            ->selectRaw('order_items.product_name as name')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.quantity * order_items.price) as takings')
            ->orderByDesc('units')
            ->limit(10)
            ->get();
    }

    /**
     * @return array<int, int>
     */
    protected function yearsWithOrders(): array
    {
        $first = Order::min('created_at');
        $start = $first ? (int) Carbon::parse($first)->format('Y') : now()->year;

        return range(now()->year, min($start, now()->year));
    }
}
