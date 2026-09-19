<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shop settings the owner can change without editing a file.
     *
     * Delivery prices moved here first: they were in config/cambodia.php,
     * which meant a code change and a deploy every time the shop wanted to
     * charge a different rate. The config file stays as the fallback, so a
     * fresh install still works before anything is saved.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
