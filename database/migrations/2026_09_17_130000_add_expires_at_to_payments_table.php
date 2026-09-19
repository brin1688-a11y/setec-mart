<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CutLuy returns expires_at (5 minutes out) on both create and read. The
     * pay page counts down to it.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
