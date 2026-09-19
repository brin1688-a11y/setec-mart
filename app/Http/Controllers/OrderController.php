<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCutLuyWebhook;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CutLuy\CutLuyClient;
use App\Services\CutLuy\Exceptions\CutLuyException;
use App\Support\Cambodia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * The tabs across the top of My Orders. "Active" is what a customer
     * actually wants on opening the page — an order still on its way.
     */
    public const FILTERS = [
        'all' => 'All',
        'active' => 'Active',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    private const ACTIVE_STATUSES = ['Pending', 'Confirmed', 'Preparing', 'Out for Delivery'];

    /**
     * Show the logged-in user's order history.
     */
    public function index(Request $request)
    {
        $filter = array_key_exists($request->query('filter'), self::FILTERS)
            ? $request->query('filter')
            : 'all';

        $orders = Auth::user()->orders()
            // Orders the customer has cleared from their own list. The shop
            // still has them; this is only their view of it.
            ->whereNull('hidden_at')
            // The list shows each order's items and their pictures, so fetch
            // them up front rather than one query per row.
            ->with(['items.product.images', 'allItems.product.images', 'payment'])
            ->when($filter === 'active', fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES))
            ->when($filter === 'delivered', fn ($q) => $q->where('status', 'Delivered'))
            ->when($filter === 'cancelled', fn ($q) => $q->where('status', 'Cancelled'))
            ->latest()
            ->paginate(8)
            ->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'filter' => $filter,
            'counts' => $this->counts(),
        ]);
    }

    /**
     * Show a single order's details.
     */
    public function show(Order $order)
    {
        if ($order->user_id !== Auth::id()) {
            abort(403);
        }

        $order->load('items.product.images', 'allItems.product.images', 'payment');

        return view('orders.show', [
            'order' => $order,
            'eta' => Cambodia::deliveryEta($order->province),
            'arrival' => Cambodia::estimatedArrival($order->province, $order->created_at),
        ]);
    }

    /**
     * Call off an order that has not been paid for.
     *
     * The shop only holds stock for an order while it is Pending, so letting
     * the customer stop there — rather than leaving an abandoned order to time
     * out — puts the items back on the shelf sooner.
     */
    public function cancel(Order $order, CutLuyClient $cutluy)
    {
        if ($order->user_id !== Auth::id()) {
            abort(403);
        }

        $order->load('payment');

        if (! $order->isCancellableByCustomer()) {
            return back()->with('error', $order->payment?->isPaid()
                ? 'This order is already paid. Message us on Telegram if you need to change it.'
                : 'This order is '.strtolower($order->status).' and can no longer be cancelled here.');
        }

        // The money may have landed in the seconds before the button was
        // pressed. Cancelling then would hand back stock we have been paid for.
        if ($this->alreadyPaid($order, $cutluy)) {
            return redirect()->route('orders.show', $order)
                ->with('success', 'Your payment arrived just now, so the order stands.');
        }

        $stopped = DB::transaction(function () use ($order) {
            // Lock the row: a webhook settling this order at the same moment
            // must not race the stock going back.
            $fresh = Order::with('items', 'payment')->whereKey($order->id)->lockForUpdate()->first();

            if (! $fresh || ! $fresh->isCancellableByCustomer()) {
                return false;
            }

            $fresh->restoreStock();
            $fresh->update(['status' => 'Cancelled']);

            $fresh->payment?->update([
                'status' => Payment::STATUS_CANCELLED,
                'qr_string' => null,
                'checkout_url' => null,
            ]);

            return true;
        });

        if (! $stopped) {
            return back()->with('error', 'That order was just updated — please take another look.');
        }

        Log::info('Customer cancelled their own order.', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);

        return redirect()->route('orders.index')
            ->with('success', 'Order '.$order->order_number.' was cancelled and the items are back in stock.');
    }

    /**
     * Clear a finished order off the customer's own list.
     *
     * Not a deletion: the shop's records, its takings and its stock history
     * all still need this order. It simply stops appearing here, the way
     * Taobao lets you tidy away orders you are done with.
     */
    public function hide(Order $order)
    {
        if ($order->user_id !== Auth::id()) {
            abort(403);
        }

        if (! $order->isFinished()) {
            return back()->with('error', 'Only finished orders can be cleared from your list.');
        }

        $order->update(['hidden_at' => now()]);

        return redirect()->route('orders.index')
            ->with('success', 'Order '.$order->order_number.' was removed from your list.');
    }

    /**
     * Drop one item from an order that has not been paid for.
     *
     * The order is priced again afterwards, because delivery and any coupon
     * depend on the subtotal. Taking out the last item leaves nothing to
     * deliver, so the order is cancelled instead of left empty.
     */
    public function removeItem(Order $order, OrderItem $item, CutLuyClient $cutluy)
    {
        if ($order->user_id !== Auth::id()) {
            abort(403);
        }

        if ($item->order_id !== $order->id) {
            abort(404);
        }

        $order->load('payment');

        if (! $order->isCancellableByCustomer()) {
            return back()->with('error', $order->payment?->isPaid()
                ? 'This order is already paid. Message us on Telegram if you need to change it.'
                : 'This order is '.strtolower($order->status).' and can no longer be changed here.');
        }

        // The same guard as cancelling: money that arrived a moment ago must
        // not be undone by an edit.
        if ($this->alreadyPaid($order, $cutluy)) {
            return redirect()->route('orders.show', $order)
                ->with('success', 'Your payment arrived just now, so the order stands as it was.');
        }

        $outcome = DB::transaction(function () use ($order, $item) {
            $fresh = Order::with('items', 'payment')->whereKey($order->id)->lockForUpdate()->first();

            if (! $fresh || ! $fresh->isCancellableByCustomer()) {
                return 'changed';
            }

            $line = $fresh->items->firstWhere('id', $item->id);

            if (! $line) {
                return 'gone';
            }

            if ($line->product_id) {
                Product::whereKey($line->product_id)->increment('stock', $line->quantity);
            }

            $line->delete();
            $fresh->load('items');

            if ($fresh->items->isEmpty()) {
                $fresh->update(['status' => 'Cancelled']);
                $fresh->payment?->update([
                    'status' => Payment::STATUS_CANCELLED,
                    'qr_string' => null,
                    'checkout_url' => null,
                ]);

                return 'emptied';
            }

            $fresh->reprice();

            // The QR was drawn for the old total. Clearing it makes the pay
            // page fetch a new one for what is actually owed now.
            $fresh->payment?->update([
                'qr_string' => null,
                'checkout_url' => null,
            ]);

            return 'removed';
        });

        return match ($outcome) {
            'emptied' => redirect()->route('orders.index')->with(
                'success',
                'That was the last item, so order '.$order->order_number.' was cancelled.'
            ),
            'removed' => redirect()->route('orders.show', $order)->with(
                'success',
                $item->product_name.' was removed and the order was priced again.'
            ),
            'gone' => back()->with('error', 'That item is no longer on this order.'),
            default => back()->with('error', 'That order was just updated — please take another look.'),
        };
    }

    /**
     * Has CutLuy taken the money after all?
     *
     * Only asked for a KHQR order that actually reached the provider. A
     * provider we cannot reach is treated as "not paid": the customer asked to
     * stop, the stock is ours to hold, and a payment that lands later still
     * settles through the webhook.
     */
    protected function alreadyPaid(Order $order, CutLuyClient $cutluy): bool
    {
        $payment = $order->payment;

        if (! $payment || $payment->method !== Payment::METHOD_KHQR || blank($payment->cutluy_payment_id)) {
            return false;
        }

        try {
            $remote = $cutluy->getPayment($payment->cutluy_payment_id);
        } catch (CutLuyException $e) {
            Log::warning('Could not check a payment before cancelling: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);

            return false;
        }

        if (($remote['status'] ?? null) !== 'paid') {
            return false;
        }

        ProcessCutLuyWebhook::dispatchSync('payment.completed', $remote);

        return true;
    }

    /**
     * How many orders sit behind each tab, so the numbers are visible before
     * you click.
     *
     * @return array<string, int>
     */
    protected function counts(): array
    {
        $byStatus = Auth::user()->orders()
            ->whereNull('hidden_at')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            'active' => (int) collect(self::ACTIVE_STATUSES)->sum(fn ($s) => $byStatus[$s] ?? 0),
            'delivered' => (int) ($byStatus['Delivered'] ?? 0),
            'cancelled' => (int) ($byStatus['Cancelled'] ?? 0),
        ];
    }
}
