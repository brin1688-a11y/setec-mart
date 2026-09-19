<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'category_id' => 1,
            'name' => 'Fresh Apple',
            'description' => 'Fresh and sweet apples.',
            'price' => 2.50,
            'stock' => 50,
            'status' => true,
        ]);

        Product::create([
            'category_id' => 1, 
            'name' => 'Banana',
            'description' => 'Fresh yellow bananas.',
            'price' => 1.50,
            'stock' => 100,
            'status' => true,
        ]);

        Product::create([
            'category_id' => 1,
            'name' => 'Mango',
            'description' => 'Sweet and delicious mango.',
            'price' => 3.00,
            'stock' => 40,
            'status' => true,
        ]);

        Product::create([
            'category_id' => 2,
            'name' => 'Carrot',
            'description' => 'Fresh organic carrots.',
            'price' => 1.80,
            'stock' => 70,
            'status' => true,
        ]);

        Product::create([
            'category_id' => 2,
            'name' => 'Tomato',
            'description' => 'Fresh red tomatoes.',
            'price' => 2.00,
            'stock' => 60,
            'status' => true,
        ]);

        Product::create([
            'category_id' => 3,
            'name' => 'Orange Juice',
            'description' => 'Refreshing orange juice.',
            'price' => 2.75,
            'stock' => 30,
            'status' => true,
        ]);

        Product::create([
            'category_id' => 3,
            'name' => 'Mineral Water',
            'description' => 'Clean and refreshing drinking water.',
            'price' => 0.75,
            'stock' => 200,
            'status' => true,
        ]);

        Product::create([
            'category_id' => 6,
            'name' => 'Fresh Milk',
            'description' => 'Fresh and healthy milk.',
            'price' => 2.25,
            'stock' => 45,
            'status' => true,
        ]);
    }
}