<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A KHQR code is only valid for a few minutes. When it lapses before the
     * customer pays, we ask CutLuy for a fresh one against the same order —
     * and that request needs an idempotency key CutLuy has not seen, or it
     * hands back the expired payment. Counting the renewals gives us one.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('renewals')->default(0)->after('cutluy_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('renewals');
        });
    }
};
