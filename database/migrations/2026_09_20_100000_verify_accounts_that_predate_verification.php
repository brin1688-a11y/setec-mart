<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Accounts that existed before the shop asked anyone to confirm an address
     * were never sent a link and have no way to ask for one retrospectively.
     * Requiring it of them would lock out every customer the shop already has,
     * so they are taken as verified and the rule applies from here on.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately empty: which rows this filled is not recorded, and
        // clearing them all would lock out people who really did verify.
    }
};
