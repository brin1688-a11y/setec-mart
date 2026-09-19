<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'sale_price',
        'sale_starts_at',
        'sale_ends_at',
        'stock',
        'image',
        'status',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
            'status' => 'boolean',
        ];
    }

    /**
     * Is a promotion running on this product right now?
     *
     * A sale price alone is enough; the dates are optional bookends. An empty
     * start means "already on", an empty end means "until someone stops it".
     * A sale price that is not actually cheaper is ignored rather than shown
     * as a saving of nothing.
     */
    public function isOnSale(): bool
    {
        if ($this->sale_price === null || (float) $this->sale_price >= (float) $this->price) {
            return false;
        }

        if ($this->sale_starts_at && $this->sale_starts_at->isFuture()) {
            return false;
        }

        if ($this->sale_ends_at && $this->sale_ends_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * What this product actually costs today.
     *
     * Everything that charges money goes through here — the cart, the order,
     * the coupon maths — so a promotion cannot apply in one place and not
     * another.
     */
    public function effectivePrice(): float
    {
        return round((float) ($this->isOnSale() ? $this->sale_price : $this->price), 2);
    }

    /**
     * How much is off, as a whole percentage, for the badge on the card.
     */
    public function discountPercent(): int
    {
        if (! $this->isOnSale() || (float) $this->price <= 0) {
            return 0;
        }

        return (int) round((1 - ((float) $this->sale_price / (float) $this->price)) * 100);
    }

    /**
     * A promotion that is set up but has not started yet.
     */
    public function saleIsScheduled(): bool
    {
        return $this->sale_price !== null
            && $this->sale_starts_at
            && $this->sale_starts_at->isFuture();
    }

    /**
     * The order the shop wants the storefront to show things in.
     *
     * Position 1 comes first, then 2, and so on. Zero means "no preference",
     * and those sort *after* the numbered ones rather than before them —
     * otherwise giving a product position 1 would push it behind every
     * product still sitting at the default. Within each group the newest
     * comes first, which is what the list did before.
     */
    /**
     * Best sellers first, counted from what has actually been bought.
     *
     * Cancelled orders are left out — a basket that was never paid for says
     * nothing about what sells. Products with no sales still appear, behind
     * the ones that have them, so a quiet shop is not an empty page.
     */
    public function scopePopular($query, int $days = 90)
    {
        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('order_items.deleted_at')
            ->where('orders.status', '!=', 'Cancelled')
            ->where('orders.created_at', '>=', now()->subDays($days))
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as units');

        return $query
            ->leftJoinSub($sold, 'sold', fn ($join) => $join->on('sold.product_id', '=', 'products.id'))
            ->select('products.*')
            ->orderByRaw('COALESCE(sold.units, 0) DESC')
            ->orderBy('products.position')
            ->orderByDesc('products.id');
    }

    /**
     * Has anything been bought yet?
     *
     * Decides whether the front page can honestly call a row "popular".
     */
    public static function hasAnySales(): bool
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('order_items.deleted_at')
            ->where('orders.status', '!=', 'Cancelled')
            ->exists();
    }

    /**
     * A stable shuffle.
     *
     * Plain RANDOM() re-draws on every query, so page two of a paginated list
     * would repeat and skip products. Deriving the order from the row id and a
     * seed gives a different arrangement per visitor that nonetheless holds
     * still while they page through it.
     */
    public function scopeShuffled($query, int $seed)
    {
        // Drawn in PHP and then spelled out for the database. Arithmetic
        // tricks like (id * n) % p are tempting, but over a run of
        // consecutive ids they come out patterned — every other product, or
        // plain reverse order — which is not a shuffle at all.
        $ids = (clone $query)->pluck('products.id')->all();

        // Past a few hundred the CASE below stops being reasonable SQL; a
        // catalogue that size has bigger sorting needs anyway.
        if ($ids === [] || count($ids) > 500) {
            return $query->inShopOrder();
        }

        mt_srand($seed);
        shuffle($ids);
        mt_srand();

        $cases = '';
        foreach ($ids as $position => $id) {
            $cases .= ' WHEN '.(int) $id.' THEN '.$position;
        }

        return $query->orderByRaw('CASE products.id'.$cases.' END');
    }

    public function scopeInShopOrder($query)
    {
        return $query
            ->orderByRaw('CASE WHEN position IS NULL OR position = 0 THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderByDesc('id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Every order line this product has ever appeared on, including the ones
     * customers later removed — those are filtered where it matters.
     */
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class)->withTrashed();
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every image for this product, newest gallery first and falling back to
     * the legacy single-image column so older rows still show something.
     *
     * @return Collection<int, string>
     */
    public function imageUrls()
    {
        $urls = $this->images->map(fn ($image) => $image->url());

        if ($urls->isEmpty() && $this->imageUrl()) {
            $urls = collect([$this->imageUrl()]);
        }

        return $urls->values();
    }

    public function hasGallery(): bool
    {
        return $this->imageUrls()->count() > 1;
    }

    /**
     * The image column stores either a full external URL (pasted by the
     * admin) or a path relative to the "public" disk (an uploaded file).
     * This resolves either case to a real, browser-loadable URL.
     */
    public function imageUrl(): ?string
    {
        // The gallery is the source of truth; `image` is the older column,
        // kept so nothing breaks for rows that predate the gallery.
        if ($this->relationLoaded('images') ? $this->images->isNotEmpty() : $this->images()->exists()) {
            return $this->images()->first()?->url();
        }

        if (! $this->image) {
            return null;
        }

        if (str_starts_with($this->image, 'http://') || str_starts_with($this->image, 'https://')) {
            return $this->image;
        }

        return Storage::url($this->image);
    }

    public function stockAdjustments()
    {
        return $this->hasMany(StockAdjustment::class)->latest();
    }
}
