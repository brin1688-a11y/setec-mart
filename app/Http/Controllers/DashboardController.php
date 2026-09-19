<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Order;
use App\Support\Cambodia;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The customer's own area: what is on its way, what they can spend, and the
 * details the shop delivers to.
 */
class DashboardController extends Controller
{
    /**
     * The panels the sidebar switches between.
     */
    public const TABS = [
        'overview' => 'Overview',
        'orders' => 'My Orders',
        'addresses' => 'Saved Addresses',
        'vouchers' => 'Vouchers',
    ];

    /**
     * An order still on its way — nothing has been delivered or called off.
     */
    private const ACTIVE_STATUSES = ['Pending', 'Confirmed', 'Preparing', 'Out for Delivery'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS)
            ? $request->query('tab')
            : 'overview';

        $user = Auth::user();

        return view('dashboard', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'user' => $user,

            // Overview
            'activeOrders' => $user->orders()->whereIn('status', self::ACTIVE_STATUSES)->count(),
            'loyaltyPoints' => $user->loyaltyPoints(),
            'lifetimeSpend' => (float) $user->orders()->where('status', '!=', 'Cancelled')->sum('total'),

            // Orders — the pictures are on the cards, so fetch them up front
            // rather than one query per line.
            'orders' => $user->orders()
                ->whereNull('hidden_at')
                ->with(['items.product.images', 'allItems.product.images', 'payment'])
                ->latest()
                ->take(10)
                ->get(),

            'addresses' => $user->addresses()->get(),
            'provinces' => Cambodia::provinceOptions(),

            'vouchers' => $this->vouchers(),
        ]);
    }

    /**
     * Coupons the customer could actually use today.
     *
     * Only ones that are switched on, inside their dates and not used up —
     * showing a code that will be refused at checkout is worse than showing
     * nothing.
     *
     * @return Collection<int, Coupon>
     */
    protected function vouchers()
    {
        return Coupon::live()
            ->orderBy('expires_at')
            ->take(6)
            ->get();
    }
}
