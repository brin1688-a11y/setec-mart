<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A coupon with no rows in either table applies to the whole cart.
     * With rows, it only discounts the matching items.
     */
    public function up(): void
    {
        Schema::create('category_coupon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'category_id']);
        });

        Schema::create('coupon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('category_coupon');
    }
};
