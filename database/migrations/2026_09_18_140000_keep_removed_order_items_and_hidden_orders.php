<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two records the shop should not lose.
     *
     * order_items.deleted_at — taking a line off an unpaid order used to
     * delete the row, so an order emptied that way ended up cancelled with no
     * trace of what had been ordered. The line is now only hidden from the
     * totals; the history stays.
     *
     * orders.hidden_at — a customer with a screen full of cancelled orders
     * should be able to clear them from their own list. That is their view of
     * it, not a deletion: the shop's books keep every order.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
