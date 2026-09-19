<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            // Stored upper-cased so SAVE10 and save10 are the same coupon.
            $table->string('code')->unique();
            $table->string('description')->nullable();

            $table->string('type');              // percent | fixed
            $table->decimal('value', 10, 2);     // 10 = 10% or $10 depending on type

            // Optional guard rails.
            $table->decimal('min_subtotal', 10, 2)->default(0);
            $table->decimal('max_discount', 10, 2)->nullable();  // caps a percent coupon
            $table->unsignedInteger('max_uses')->nullable();      // null = unlimited
            $table->unsignedInteger('used_count')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
