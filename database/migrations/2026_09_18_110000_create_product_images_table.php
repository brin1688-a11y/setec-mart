<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Either an uploaded path on the "public" disk or a full URL,
            // the same convention products.image already used.
            $table->string('path', 2048);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        // Carry the single image each product already has across, so nothing
        // disappears from the storefront the moment this runs.
        foreach (DB::table('products')->whereNotNull('image')->get(['id', 'image']) as $row) {
            if (trim((string) $row->image) === '') {
                continue;
            }

            DB::table('product_images')->insert([
                'product_id' => $row->id,
                'path' => $row->image,
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
