<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Sample stock for the Snacks aisle.
 *
 * The pictures live on this server, under storage/app/public/products/snacks,
 * rather than being hotlinked: an outside CDN can move, block hotlinking or
 * simply be slow from Cambodia, and a shop full of broken images is worse than
 * a plain one. They are CC0 or public-domain photographs, so nothing here owes
 * an attribution; CREDITS.json beside them records where each came from.
 *
 * Safe to run more than once — each snack is matched on its name, so a second
 * run updates rather than duplicates.
 */
class SnackSeeder extends Seeder
{
    /**
     * name, price, stock, image file, description
     *
     * @var array<int, array{0: string, 1: float, 2: int, 3: string, 4: string}>
     */
    protected const SNACKS = [
        ['Chocolate Chip Cookies', 2.25, 60, 'chocolate-chip-cookies', 'Soft-baked cookies with real chocolate chunks. 200g pack.'],
        ['Potato Chips', 1.50, 120, 'potato-chips', 'Thin-cut and lightly salted. The one that never lasts the evening.'],
        ['Popcorn', 1.75, 80, 'popcorn', 'Ready-to-eat buttered popcorn in a resealable bag.'],
        ['Chocolate Bar', 1.25, 150, 'chocolate-bar', 'Smooth milk chocolate, 90g. Keep somewhere cool.'],
        ['Roasted Peanuts', 1.00, 100, 'roasted-peanuts', 'Salted and roasted in the shell. 250g.'],
        ['Rice Crackers', 1.40, 90, 'rice-crackers', 'Light, crisp and lightly sweet — a Khmer tea-time favourite.'],
        ['Pretzels', 2.00, 45, 'pretzels', 'Crunchy salted pretzels, 180g.'],
        ['Doughnut', 0.90, 30, 'doughnut', 'Glazed and made fresh each morning. Best eaten the same day.'],
        ['Fruit Candy', 0.75, 200, 'fruit-candy', 'Mixed fruit hard candy. A handful in every bag.'],
        ['Lollipops', 0.50, 250, 'lollipops', 'Assorted flavours, ten to a pack.'],
        ['Dried Fruit Mix', 2.75, 55, 'dried-fruit-mix', 'Sun-dried fruit by the jar — mango, papaya and pineapple.'],
        ['Banana Chips', 1.60, 70, 'banana-chips', 'Crisp fried banana slices, lightly salted. 150g.'],
        ['Cashew Nuts', 3.50, 40, 'cashew-nuts', 'Roasted Kampong Thom cashews, 200g.'],
        ['Seaweed Snack', 1.20, 85, 'seaweed-snack', 'Roasted seaweed sheets, salted and very moreish.'],
    ];

    public function run(): void
    {
        $category = Category::firstOrCreate(['name' => 'Snacks']);

        foreach (self::SNACKS as $position => [$name, $price, $stock, $file, $description]) {
            $path = 'products/snacks/'.$file.'.jpg';

            if (! Storage::disk('public')->exists($path)) {
                $this->command?->warn("Missing picture for {$name} — skipped.");

                continue;
            }

            $product = Product::updateOrCreate(
                ['name' => $name],
                [
                    'category_id' => $category->id,
                    'description' => $description,
                    'price' => $price,
                    'stock' => $stock,
                    'image' => $path,
                    'status' => true,
                    // Left unpositioned: these sit with the rest of the shop
                    // rather than pushing real stock off the front page.
                    'position' => 0,
                ]
            );

            // The gallery is what the product page reads; keep it in step
            // rather than stacking a duplicate on every run.
            $product->images()->updateOrCreate(
                ['path' => $path],
                ['position' => 0]
            );
        }

        $this->command?->info('Seeded '.count(self::SNACKS).' snacks.');
    }
}
