<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Cambodia;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Shoppers and their orders, for showing the shop to someone.
 *
 * Only adds — it never deletes, so running it twice makes more customers
 * rather than replacing the ones already there.
 *
 * The orders are spread back over about six weeks so both halves of the sales
 * report have a shape to show: enough days with trade for the daily view, and
 * two months for the monthly one. Stock is deliberately left alone; these are
 * records of sales that already happened, and taking the stock down now would
 * only empty the shelves before the demo.
 */
class DemoDataSeeder extends Seeder
{
    /** Names and addresses that read like the shop's actual customers. */
    protected const SHOPPERS = [
        ['Chan Sophea', 'sophea.chan@example.com', '012 345 678', 'Phnom Penh', 'Chamkar Mon', 'Tonle Bassac', 'St. 294, House 17'],
        ['Sok Dara', 'dara.sok@example.com', '077 201 449', 'Phnom Penh', 'Toul Kork', 'Boeung Kak Ti Muoy', 'St. 337, House 4B'],
        ['Ly Chanthou', 'chanthou.ly@example.com', '085 662 130', 'Phnom Penh', 'Sen Sok', 'Phnom Penh Thmey', 'St. 2004, House 51'],
        ['Meas Vanna', 'vanna.meas@example.com', '016 884 025', 'Siem Reap', 'Siem Reap', 'Svay Dangkum', 'Wat Bo Road, House 9'],
        ['Keo Sreymom', 'sreymom.keo@example.com', '092 730 118', 'Battambang', 'Battambang', 'Svay Por', 'St. 3, House 22'],
        ['Pich Ratana', 'ratana.pich@example.com', '098 415 267', 'Phnom Penh', 'Daun Penh', 'Phsar Thmey Ti Bei', 'St. 130, House 8'],
    ];

    /**
     * How many days ago, what state it reached, and how it was paid.
     * Older orders have finished; the last few days are still moving.
     */
    protected const ORDERS = [
        [41, 'Delivered', 'khqr'], [40, 'Delivered', 'cod'], [38, 'Delivered', 'khqr'],
        [35, 'Delivered', 'cod'], [34, 'Delivered', 'khqr'], [31, 'Delivered', 'cod'],
        [29, 'Delivered', 'khqr'], [28, 'Cancelled', 'khqr'], [26, 'Delivered', 'cod'],
        [24, 'Delivered', 'khqr'], [22, 'Delivered', 'cod'], [21, 'Delivered', 'khqr'],
        [19, 'Delivered', 'cod'], [17, 'Delivered', 'khqr'], [16, 'Delivered', 'cod'],
        [14, 'Delivered', 'khqr'], [12, 'Cancelled', 'cod'], [11, 'Delivered', 'khqr'],
        [9, 'Delivered', 'cod'], [8, 'Delivered', 'khqr'], [7, 'Delivered', 'cod'],
        [5, 'Delivered', 'khqr'], [4, 'Out for Delivery', 'cod'], [3, 'Preparing', 'khqr'],
        [2, 'Confirmed', 'cod'], [2, 'Confirmed', 'khqr'], [1, 'Preparing', 'cod'],
        [1, 'Out for Delivery', 'khqr'], [0, 'Confirmed', 'cod'], [0, 'Pending', 'khqr'],
    ];

    public function run(): void
    {
        $products = Product::where('status', true)->where('stock', '>', 0)->get();

        if ($products->isEmpty()) {
            $this->command?->warn('No products to sell — seed the catalogue first.');

            return;
        }

        $shoppers = collect(self::SHOPPERS)->map(fn (array $row) => $this->shopper($row));

        foreach (self::ORDERS as $i => [$daysAgo, $status, $method]) {
            $this->order($shoppers[$i % $shoppers->count()], $products, $daysAgo, $status, $method, $i);
        }

        $this->command?->info('Added '.$shoppers->count().' customers and '.count(self::ORDERS).' orders.');
    }

    /**
     * @param  array<int, string>  $row
     */
    protected function shopper(array $row): User
    {
        [$name, $email, $phone, $province, $district, $commune, $address] = $row;

        return User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make(str()->random(32)),
            'role' => 'customer',
            'phone' => $phone,
            'province' => $province,
            'district' => $district,
            'commune' => $commune,
            'address' => $address,
            // Seeded accounts are not waiting on anything.
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function order(User $user, $products, int $daysAgo, string $status, string $method, int $seed): void
    {
        // Seeded so a second run of the same position produces the same basket
        // rather than a different one.
        mt_srand($seed * 7919);

        $placedAt = Carbon::now()
            ->subDays($daysAgo)
            ->setTime(mt_rand(8, 20), mt_rand(0, 59));

        $basket = $products->shuffle()->take(mt_rand(1, 4));

        DB::transaction(function () use ($user, $basket, $placedAt, $status, $method) {
            $order = Order::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'province' => $user->province,
                'district' => $user->district,
                'commune' => $user->commune,
                'address' => $user->address,
                'status' => $status,
                'subtotal' => 0, 'discount' => 0, 'delivery_fee' => 0, 'total' => 0,
            ]);

            $subtotal = 0.0;

            foreach ($basket as $product) {
                $quantity = mt_rand(1, 3);
                $price = round($product->effectivePrice(), 2);
                $subtotal += $price * $quantity;

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $price,
                    'quantity' => $quantity,
                ]);
            }

            $delivery = Cambodia::deliveryFee($order->province, $subtotal);

            $order->update([
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => $delivery,
                'total' => round($subtotal + $delivery, 2),
            ]);

            $order->payment()->create([
                'method' => $method,
                'status' => match (true) {
                    $status === 'Cancelled' => Payment::STATUS_CANCELLED,
                    $method === 'cod' && $status === 'Delivered' => Payment::STATUS_PAID,
                    $method === 'khqr' && $status !== 'Pending' => Payment::STATUS_PAID,
                    default => Payment::STATUS_PENDING,
                },
            ]);

            // created_at is set by the database, so it moves afterwards — and
            // both the order and its lines have to move together or the report
            // counts units on one day and money on another.
            $order->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->saveQuietly();
            $order->items()->update(['created_at' => $placedAt, 'updated_at' => $placedAt]);
        });

        mt_srand();
    }
}
