<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotions, and a say over what the shop shows first.
     *
     * sale_price is the promotional price itself rather than a percentage, so
     * what the customer pays is exactly what the shop typed — no rounding
     * surprises. The two dates make a promotion start and stop on its own;
     * leaving them empty runs it until someone clears the price.
     *
     * position lets the shop pull a product to the front of the storefront.
     * Lower comes first; everything defaults to 0 and falls back to newest.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sale_price', 10, 2)->nullable()->after('price');
            $table->timestamp('sale_starts_at')->nullable()->after('sale_price');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
            $table->integer('position')->default(0)->after('status');

            $table->index(['position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['position', 'id']);
            $table->dropColumn(['sale_price', 'sale_starts_at', 'sale_ends_at', 'position']);
        });
    }
};
