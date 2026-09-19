<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give every order a reference of its own.
     *
     * Until now the customer was shown the row's primary key — "Order #50" —
     * which tells anyone who looks exactly how many orders the shop has ever
     * taken, and lets them guess their neighbours' by counting. A dated
     * reference with a random tail says nothing about volume and is easier to
     * read back over Telegram.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_number', 24)->nullable()->unique()->after('id');
        });

        $this->backfill();

        // Every row has one now, so require it from here on.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_number', 24)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_number');
        });
    }

    /**
     * Number the orders that already exist, dating each from when it was placed.
     */
    protected function backfill(): void
    {
        $taken = [];

        DB::table('orders')->whereNull('order_number')->orderBy('id')
            ->select('id', 'created_at')->get()
            ->each(function ($order) use (&$taken) {
                $date = $order->created_at
                    ? Carbon::parse($order->created_at)
                    : now();

                do {
                    $number = Order::nextNumber($date);
                } while (isset($taken[$number]));

                $taken[$number] = true;

                DB::table('orders')->where('id', $order->id)->update(['order_number' => $number]);
            });
    }
};
