<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Where an order is allowed to go next.
     *
     * An order only moves forward through the pipeline, and can be cancelled
     * from anywhere before it is out for delivery. Once it is Delivered or
     * Cancelled it is finished — otherwise a stray click could un-deliver an
     * order, or cancel one twice and hand the stock back a second time.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'Pending' => ['Confirmed', 'Cancelled'],
        'Confirmed' => ['Preparing', 'Cancelled'],
        'Preparing' => ['Out for Delivery', 'Cancelled'],
        'Out for Delivery' => ['Delivered', 'Cancelled'],
        'Delivered' => [],
        'Cancelled' => [],
    ];

    /**
     * Orders that never brought money in.
     */
    protected const DEAD_STATUSES = ['Cancelled'];

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), Order::STATUSES, true)
            ? $request->query('status')
            : null;

        $search = trim((string) $request->query('q'));

        $orders = Order::with(['user', 'payment', 'items'])
            ->when($status, fn ($q) => $q->where('status', $status))
            // With no status picked this is the working list — what still
            // needs doing. An order the customer called off needs nothing
            // done, and leaving them mixed in buries the ones that do. The
            // Cancelled tab still holds them: a customer who disputes a
            // charge is why they are kept rather than deleted.
            ->when(! $status, fn ($q) => $q->whereNotIn('status', self::DEAD_STATUSES))
            ->when($search !== '', function ($q) use ($search) {
                // Whatever the shop has to hand: the reference the customer
                // read out, their name, or the phone the order came from.
                $like = '%'.strtolower($search).'%';

                $q->where(function ($q) use ($like) {
                    $q->whereRaw('LOWER(order_number) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$like]);
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'status' => $status,
            'search' => $search,
            'counts' => $this->counts(),
            'today' => $this->todayAtAGlance(),
            'removableCancelled' => $this->removableCancelled()->count(),
        ]);
    }

    /**
     * The same clearing, for every cancelled order at once.
     *
     * Doing it a row at a time is fine for four and hopeless for four
     * hundred, which is the number a run of abandoned checkouts produces.
     */
    public function purgeCancelled()
    {
        $ids = $this->removableCancelled()->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('error', 'There is nothing to remove.');
        }

        DB::transaction(function () use ($ids) {
            // Deleted per table rather than per order: one statement each,
            // however many orders there are.
            OrderItem::withTrashed()->whereIn('order_id', $ids)->forceDelete();
            Payment::whereIn('order_id', $ids)->delete();
            Order::whereIn('id', $ids)->delete();
        });

        return redirect()
            ->route('admin.orders.index', ['status' => 'Cancelled'])
            ->with('success', $ids->count().' cancelled '.str('order')->plural($ids->count()).' removed.');
    }

    /**
     * Cancelled orders that never took a payment — the ones safe to delete.
     *
     * @return Builder<Order>
     */
    protected function removableCancelled()
    {
        return Order::whereIn('status', self::DEAD_STATUSES)
            ->whereDoesntHave('payment', fn ($q) => $q->where('status', Payment::STATUS_PAID));
    }

    /**
     * Clear a cancelled order off the books.
     *
     * Only ones that never took money: the customer side deletes those for
     * itself, and this is the shop doing the same tidying without waiting for
     * them. An order that was paid and then cancelled is a refund, which is
     * the record most likely to be asked about later, so it stays.
     */
    public function destroy(Order $order)
    {
        if (! $order->tookNoMoney()) {
            return back()->with('error',
                'Order '.$order->order_number.' took a payment, so its record is kept.');
        }

        $number = $order->order_number;

        DB::transaction(function () use ($order) {
            $order->allItems()->forceDelete();
            $order->payment()?->delete();
            $order->delete();
        });

        return redirect()
            ->route('admin.orders.index', ['status' => 'Cancelled'])
            ->with('success', 'Order '.$number.' was removed.');
    }

    /**
     * How many orders sit at each step, for the filter tabs.
     *
     * @return array<string, int>
     */
    protected function counts(): array
    {
        $byStatus = Order::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // The unfiltered tab counts what it actually lists.
        $counts = ['' => (int) $byStatus->except(self::DEAD_STATUSES)->sum()];

        foreach (Order::STATUSES as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return $counts;
    }

    /**
     * The numbers a shop actually opens this page for: what is waiting on
     * someone, and what today has taken.
     *
     * @return array<string, mixed>
     */
    protected function todayAtAGlance(): array
    {
        $today = [now()->startOfDay(), now()->endOfDay()];

        return [
            'awaiting_payment' => Order::where('status', 'Pending')
                ->whereHas('payment', fn ($q) => $q->where('method', Payment::METHOD_KHQR)
                    ->where('status', Payment::STATUS_PENDING))
                ->count(),
            'to_prepare' => Order::whereIn('status', ['Confirmed', 'Preparing'])->count(),
            'on_the_road' => Order::where('status', 'Out for Delivery')->count(),
            'orders_today' => Order::whereBetween('created_at', $today)->count(),
            'revenue_today' => (float) Order::whereNotIn('status', self::DEAD_STATUSES)
                ->whereBetween('created_at', $today)
                ->sum('total'),
        ];
    }

    public function show(Order $order)
    {
        // allItems, so a line the customer took off is still on the record.
        $order->load('items.product.images', 'allItems.product.images', 'payment', 'user');

        return view('admin.orders.show', [
            'order' => $order,
            'allowedStatuses' => self::TRANSITIONS[$order->status] ?? [],
            'customerOrders' => Order::where('user_id', $order->user_id)->count(),
            'customerSpend' => (float) Order::where('user_id', $order->user_id)
                ->whereNotIn('status', self::DEAD_STATUSES)
                ->sum('total'),
        ]);
    }

    public function updateStatus(Request $request, Order $order)
    {
        $allowed = self::TRANSITIONS[$order->status] ?? [];

        if ($allowed === []) {
            return back()->with('error', 'Order '.$order->order_number.' is '.strtolower($order->status).' and can no longer be changed.');
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in($allowed)],
        ], [
            'status.in' => 'An order that is '.strtolower($order->status)
                .' can only move to: '.implode(', ', $allowed).'.',
        ]);

        $message = DB::transaction(function () use ($order, $validated) {
            // Lock the row so two admins clicking at once cannot both cancel
            // it and return the stock twice.
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! in_array($validated['status'], self::TRANSITIONS[$fresh->status] ?? [], true)) {
                return 'Order '.$fresh->order_number.' was already updated by someone else.';
            }

            if ($validated['status'] === 'Cancelled') {
                // Stock was taken off the shelf when the order was placed, so
                // cancelling has to put it back.
                $fresh->load('items');
                $fresh->restoreStock();
            }

            $fresh->update(['status' => $validated['status']]);

            return null;
        });

        if ($message) {
            return back()->with('error', $message);
        }

        return back()->with('success', $validated['status'] === 'Cancelled'
            ? 'Order cancelled and stock returned to inventory.'
            : 'Order status updated to '.$validated['status'].'.');
    }
}
