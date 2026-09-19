<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somewhere to keep more than one delivery address.
     *
     * Until now a customer had exactly one, held on their own row. People
     * order to home and to work, so this moves them into their own table and
     * carries the existing one across as the default.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What the customer calls it — "Home", "Office".
            $table->string('label', 40)->default('Home');

            $table->string('name');
            $table->string('phone', 30);
            $table->string('telegram', 64)->nullable();

            $table->string('province', 120);
            $table->string('district', 120);
            $table->string('commune', 120);
            $table->string('address', 500);

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        $this->carryOverExistingAddresses();
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }

    /**
     * Move the single address each customer already had into the new table,
     * so nobody has to type theirs again.
     */
    protected function carryOverExistingAddresses(): void
    {
        DB::table('users')
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->whereNotNull('province')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('addresses')->insert([
                    'user_id' => $user->id,
                    'label' => 'Home',
                    'name' => $user->name,
                    'phone' => $user->phone ?: '',
                    'telegram' => $user->telegram,
                    'province' => $user->province,
                    'district' => $user->district ?: '',
                    'commune' => $user->commune ?: '',
                    'address' => $user->address,
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};
