<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Category::create([
            'name' => 'Fruits',
            'description' => 'Fresh and healthy fruits',
        ]);

        \App\Models\Category::create([
            'name' => 'Vegetables',
            'description' => 'Fresh vegetables every day',
        ]);

        \App\Models\Category::create([
            'name' => 'Drinks',
            'description' => 'Refreshing drinks',
        ]);

        \App\Models\Category::create([
            'name' => 'Meat',
            'description' => 'Fresh meat and protein',
        ]);

        \App\Models\Category::create([
            'name' => 'Snacks',
            'description' => 'Delicious snacks',
        ]);

        \App\Models\Category::create([
            'name' => 'Dairy',
            'description' => 'Milk, cheese and dairy products',
        ]);
    }
}
