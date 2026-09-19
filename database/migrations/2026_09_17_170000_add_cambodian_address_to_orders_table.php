<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Cambodian delivery address is structured, and delivery is priced by
     * province, so the order has to record both.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('province')->nullable()->after('phone');
            $table->string('district')->nullable()->after('province');   // srok / khan
            $table->string('commune')->nullable()->after('district');    // khum / sangkat
            $table->string('telegram')->nullable()->after('commune');

            // `total` already exists and stays the amount actually charged.
            // Splitting it out makes the delivery fee auditable after the fact.
            $table->decimal('subtotal', 10, 2)->nullable()->after('note');
            $table->decimal('delivery_fee', 10, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['province', 'district', 'commune', 'telegram', 'subtotal', 'delivery_fee']);
        });
    }
};
