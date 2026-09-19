<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the CutLuy (KHQR) flow needs to hang off an order's payment.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // CutLuy's own payment id — the key the webhook handler works from.
            // Unique so a duplicate delivery can never create a second row.
            $table->string('cutluy_payment_id')->nullable()->unique()->after('method');

            // Raw provider status: pending, scanned, paid, expired, failed.
            // Kept separate from `status` (our Pending/Paid/Expired/Failed).
            $table->string('cutluy_status')->nullable()->after('status');

            $table->decimal('amount', 10, 2)->nullable()->after('cutluy_status');
            $table->string('currency', 3)->default('USD')->after('amount');

            $table->text('checkout_url')->nullable()->after('currency');
            $table->text('qr_string')->nullable()->after('checkout_url');

            $table->timestamp('paid_at')->nullable()->after('qr_string');

            // When we last applied a webhook for this payment — useful when
            // reconciling by hand.
            $table->timestamp('last_event_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['cutluy_payment_id']);

            $table->dropColumn([
                'cutluy_payment_id',
                'cutluy_status',
                'amount',
                'currency',
                'checkout_url',
                'qr_string',
                'paid_at',
                'last_event_at',
            ]);
        });
    }
};
