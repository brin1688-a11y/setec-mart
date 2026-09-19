<?php

namespace App\Services\Chat;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Cambodia;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The facts the assistant is allowed to answer from.
 *
 * Everything here is read out of the shop's own tables at the moment of the
 * question — prices, stock, delivery rules, the basket in front of the
 * customer. The assistant is told to answer from this and nothing else, so it
 * cannot invent a product or quote a price the shop does not charge.
 */
class ShopContext
{
    /**
     * Products worth putting in front of the model for this question.
     *
     * Matches on the product and category name. LOWER(...) LIKE rather than
     * ILIKE: ILIKE is PostgreSQL-only and the test suite runs on SQLite.
     *
     * @return Collection<int, Product>
     */
    public function matchingProducts(string $message, int $limit = 8): Collection
    {
        $words = $this->keywords($message);

        if ($words === []) {
            return collect();
        }

        return Product::with('category')
            ->where('status', true)
            ->where(function ($query) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';

                    $query->orWhereRaw('LOWER(products.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(products.description) LIKE ?', [$like])
                        ->orWhereHas('category', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like]));
                }
            })
            ->orderByDesc('stock')
            ->take($limit)
            ->get();
    }

    /**
     * A product written out for the assistant.
     *
     * The id is included because the assistant proposes "add this to the
     * cart" by id, and the description because half the questions a grocery
     * shop gets are about size, weight and what is in the packet.
     *
     * @return array<string, mixed>
     */
    public function describe(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'category' => $product->category?->name ?? 'Uncategorised',
            'price' => '$'.number_format($product->effectivePrice(), 2),
            'was' => $product->isOnSale() ? '$'.number_format($product->price, 2) : null,
            'discount' => $product->isOnSale() ? $product->discountPercent().'% off' : null,
            'stock' => $product->stock,
            'availability' => $product->stock > 0
                ? $product->stock.' in stock'
                : 'out of stock',
            'description' => Str::limit((string) $product->description, 180),
        ];
    }

    /**
     * The same, as the line the prompt carries.
     */
    public function describeLine(Product $product): string
    {
        $d = $this->describe($product);

        $line = sprintf(
            '- [id %d] %s (%s) — %s%s, %s',
            $d['id'],
            $d['name'],
            $d['category'],
            $d['price'],
            $d['was'] ? ' (was '.$d['was'].', '.$d['discount'].')' : '',
            $d['availability'],
        );

        if ($d['description'] !== '') {
            $line .= '
    '.$d['description'];
        }

        return $line;
    }

    /**
     * Words worth searching on.
     *
     * Short words and the usual filler match half the shop, so they are
     * dropped. Khmer has no spaces between words, so any run of Khmer script
     * is kept whole and matched as a substring.
     */
    protected function keywords(string $message): array
    {
        $message = mb_strtolower(trim($message));

        $noise = ['the', 'and', 'for', 'you', 'have', 'has', 'any', 'got', 'want',
            'need', 'how', 'much', 'many', 'what', 'where', 'when', 'does', 'can',
            'about', 'with', 'this', 'that', 'there', 'please', 'hello', 'order'];

        $words = preg_split('/[^\p{L}\p{N}]+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $keep = [];

        foreach ($words as $word) {
            $isKhmer = (bool) preg_match('/\p{Khmer}/u', $word);

            if ($isKhmer || (mb_strlen($word) >= 3 && ! in_array($word, $noise, true))) {
                $keep[] = $word;
            }
        }

        return array_slice(array_unique($keep), 0, 6);
    }

    /**
     * A short catalogue so the assistant knows what the shop sells even when
     * the question matched nothing — "what do you have?" deserves an answer.
     *
     * @return array<string, mixed>
     */
    public function catalogueSummary(): array
    {
        return [
            'categories' => Category::withCount(['products' => fn ($q) => $q->where('status', true)])
                ->get()
                ->map(fn ($c) => $c->name.' ('.$c->products_count.')')
                ->all(),
            'total_products' => Product::where('status', true)->count(),
        ];
    }

    /**
     * The whole shelf, in one line per product.
     *
     * Keyword matching cannot bridge languages — a customer asking for
     * "ទឹកក្រូច" will never match a row called "Orange Juice" — so for a shop
     * this size it is cheaper and far more reliable to hand over the list and
     * let the assistant do the matching itself.
     *
     * Capped, because past a few hundred this stops being a sensible prompt.
     *
     * @return array<int, string>
     */
    public function shortlist(int $limit = 90): array
    {
        if (Product::where('status', true)->count() > $limit) {
            return [];
        }

        return Product::with('category')
            ->where('status', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($product) => sprintf(
                '[id %d] %s (%s) %s, %s',
                $product->id,
                $product->name,
                $product->category?->name ?? '-',
                '$'.number_format($product->effectivePrice(), 2),
                $product->stock > 0 ? $product->stock.' in stock' : 'out of stock',
            ))
            ->all();
    }

    /**
     * What is in the basket right now.
     *
     * Read from the customer's own cart when they are signed in. The payload
     * the widget sends is only trusted to the extent of naming products; the
     * quantities and prices come from the database either way.
     *
     * @param  array<int, array{id: int, qty: int}>  $claimed
     * @return array<string, mixed>
     */
    public function cart(?User $user, array $claimed = []): array
    {
        $lines = collect();

        if ($user?->cart) {
            $lines = $user->cart->items()->with('product')->get()
                ->map(fn ($item) => [
                    'name' => $item->product?->name,
                    'qty' => $item->quantity,
                    'each' => '$'.number_format($item->unitPrice(), 2),
                ])
                ->filter(fn ($line) => $line['name'] !== null)
                ->values();
        } elseif ($claimed !== []) {
            // A guest's basket lives in the browser, so the ids it names are
            // looked up here rather than taken on trust.
            $products = Product::whereIn('id', array_column($claimed, 'id'))->get()->keyBy('id');

            $lines = collect($claimed)
                ->map(function ($line) use ($products) {
                    $product = $products->get($line['id'] ?? null);

                    return $product ? [
                        'name' => $product->name,
                        'qty' => max(1, (int) ($line['qty'] ?? 1)),
                        'each' => '$'.number_format($product->effectivePrice(), 2),
                    ] : null;
                })
                ->filter()
                ->values();
        }

        return [
            'count' => $lines->sum('qty'),
            'items' => $lines->all(),
        ];
    }

    /**
     * The customer's most recent order, so "where is my order?" can be
     * answered instead of deflected.
     *
     * @return array<string, mixed>|null
     */
    public function latestOrder(?User $user): ?array
    {
        $order = $user?->orders()->with('payment')->latest()->first();

        if (! $order) {
            return null;
        }

        return [
            'reference' => $order->order_number,
            'placed' => $order->created_at->format('j M Y'),
            'status' => $order->status,
            'total' => '$'.number_format($order->total, 2),
            'payment' => $order->payment?->methodLabel(),
            'payment_status' => $order->payment?->status,
            'arriving' => $order->province ? Cambodia::deliveryEta($order->province) : null,
        ];
    }

    /**
     * Delivery, payment and contact — the questions a grocery shop is asked
     * all day.
     *
     * @return array<string, mixed>
     */
    public function shopFacts(): array
    {
        $zones = collect(Cambodia::zones())
            ->map(fn ($zone) => sprintf(
                '%s: %s, %s%s',
                $zone['label'],
                $zone['fee'] > 0 ? '$'.number_format($zone['fee'], 2) : 'free',
                $zone['eta'],
                $zone['free_over'] ? ', free over $'.number_format($zone['free_over'], 2) : ''
            ))
            ->values()
            ->all();

        return [
            'shop' => config('app.name'),
            'delivery' => $zones,
            'payment_methods' => ['KHQR (scan with any Cambodian banking app)', 'Cash on delivery'],
            'hours' => config('cambodia.shop.hours'),
            'telegram' => '@'.config('cambodia.shop.telegram'),
            'phone' => config('cambodia.shop.phone'),
        ];
    }
}
