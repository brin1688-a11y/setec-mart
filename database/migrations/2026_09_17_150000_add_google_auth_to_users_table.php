<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Google's stable account id ("sub"). Unique so one Google account
            // can never end up attached to two local users.
            $table->string('google_id')->nullable()->unique()->after('email');

            // Someone who only ever signs in with Google has no password of
            // their own, so the column has to allow null.
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
