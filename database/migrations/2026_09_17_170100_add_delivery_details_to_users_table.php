<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved delivery details, so a returning customer does not retype their
     * address on every order.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('telegram')->nullable()->after('phone');
            $table->string('province')->nullable()->after('telegram');
            $table->string('district')->nullable()->after('province');
            $table->string('commune')->nullable()->after('district');
            $table->text('address')->nullable()->after('commune');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'telegram', 'province', 'district', 'commune', 'address']);
        });
    }
};
