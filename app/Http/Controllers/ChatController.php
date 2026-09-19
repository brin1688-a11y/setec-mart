<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Chat\ChatClient;
use App\Services\Chat\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The shop assistant.
 *
 * Answers about stock, prices, product detail and the customer's own order,
 * grounded in the shop's tables rather than the model's memory. It can also
 * offer to put something in the basket — as a button the customer presses,
 * never a silent change to their cart.
 */
class ChatController extends Controller
{
    /** The languages the assistant will answer in. */
    public const LANGUAGES = ['kh', 'en'];

    public function handle(Request $request, ChatClient $client, ShopContext $context): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'lang' => ['nullable', 'string', 'in:kh,en'],
            'cart' => ['nullable', 'array', 'max:100'],
            'cart.*.id' => ['required_with:cart', 'integer'],
            'cart.*.qty' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $lang = $data['lang'] ?? 'en';

        // Every reply costs money and time, so cap how fast one visitor can
        // ask. Keyed on the account when there is one, the address otherwise.
        $key = 'chat:'.(Auth::id() ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 15)) {
            return response()->json([
                'reply' => $this->tooFast($lang),
                'actions' => [],
                'throttled' => true,
            ], 429);
        }

        RateLimiter::hit($key, decaySeconds: 60);

        if (! $client->isConfigured()) {
            return response()->json([
                'reply' => $this->notConfigured($lang),
                'actions' => [],
                'configured' => false,
            ]);
        }

        $user = Auth::user();

        $answer = $client->reply(
            $this->systemPrompt($data['message'], $lang, $user, $context, $data['cart'] ?? []),
            $data['message'],
        );

        if ($answer === null) {
            return response()->json([
                'reply' => $this->unavailable($lang),
                'actions' => [],
                'ok' => false,
            ]);
        }

        $actions = $this->checkedActions($answer['actions'], $lang);

        return response()->json([
            // A tool call can arrive with no wording of its own.
            'reply' => $answer['text'] ?? $this->hereYouAre($lang, $actions),
            'actions' => $actions,
            'ok' => true,
        ]);
    }

    /**
     * Turn what the assistant proposed into something the customer can press.
     *
     * Every proposal is checked against the database: the product has to
     * exist, be on sale and be in stock, and the quantity is capped at what
     * is on the shelf. The model's numbers are a suggestion, never authority.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $proposed
     * @return array<int, array<string, mixed>>
     */
    protected function checkedActions(array $proposed, string $lang): array
    {
        if ($proposed === []) {
            return [];
        }

        $products = Product::whereIn('id', array_column($proposed, 'product_id'))
            ->where('status', true)
            ->get()
            ->keyBy('id');

        $actions = [];

        foreach ($proposed as $item) {
            $product = $products->get($item['product_id']);

            if (! $product || $product->stock < 1) {
                continue;
            }

            $quantity = min($item['quantity'], $product->stock);

            $actions[] = [
                'type' => 'add_to_cart',
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'price' => '$'.number_format($product->effectivePrice() * $quantity, 2),
                'url' => route('cart.add', $product),
                'label' => $lang === 'kh'
                    ? 'ដាក់ចូលកន្ត្រក '.$quantity.' × '.$product->name
                    : 'Add '.$quantity.' × '.$product->name.' to cart',
            ];
        }

        return $actions;
    }

    /**
     * Everything the assistant is allowed to answer from, plus how to behave.
     *
     * @param  array<int, array<string, mixed>>  $claimedCart
     */
    protected function systemPrompt(
        string $message,
        string $lang,
        $user,
        ShopContext $context,
        array $claimedCart,
    ): string {
        $matches = $context->matchingProducts($message);

        $products = $matches
            ->map(fn ($product) => $context->describeLine($product))
            ->implode("\n");

        $cart = $context->cart($user, $claimedCart);
        $order = $context->latestOrder($user);
        $facts = $context->shopFacts();

        $language = $lang === 'kh'
            ? 'Khmer (ភាសាខ្មែរ). Write naturally and politely, the way a Phnom Penh shop assistant speaks. Keep product names and codes like KHQR in their usual form.'
            : 'English.';

        $sections = [
            "You are the assistant for {$facts['shop']}, an online grocery shop delivering in Cambodia.",
            "Reply in {$language}",
            '',
            'RULES',
            '- Answer only from the shop facts below. Never invent a product, a price or a stock level.',
            '- Give the exact number in stock when asked, and say plainly when something is out of stock.',
            '- When asked about a product, include its price and what it is, not just whether you have it.',
            '- If something is not in the facts, say you are not sure and offer the shop\'s Telegram.',
            '- Keep it short: two or three sentences unless asked for detail.',
            '- Prices are US dollars.',
            '',
            'PUTTING THINGS IN THE CART',
            '- When the customer says they want something, call add_to_cart with the product id from the facts.',
            '- Use only an id listed below. Never guess one.',
            '- Say in words what you are offering; the customer confirms with a button, so do not claim it is already in the cart.',
            '- You cannot place the order, pay, cancel or change an address. Point them at the page that can.',
            '',
            'SHOP',
            '- Delivery: '.implode(' | ', $facts['delivery']),
            '- Payment: '.implode(' | ', $facts['payment_methods']),
            '- Hours: '.$facts['hours'],
            '- Telegram: '.$facts['telegram'].', phone '.$facts['phone'],
        ];

        $summary = $context->catalogueSummary();
        $sections[] = '- Catalogue: '.$summary['total_products'].' products across '
            .implode(', ', $summary['categories']);

        if ($products !== '') {
            $sections[] = '';
            $sections[] = 'PRODUCTS MATCHING THE QUESTION';
            $sections[] = $products;
        }

        // The full shelf, so a question in Khmer — which will never match an
        // English product name by keyword — can still be answered.
        $shelf = $context->shortlist();

        if ($shelf !== []) {
            $sections[] = '';
            $sections[] = 'EVERYTHING ON SALE (match what the customer wrote to these yourself, in any language)';
            $sections[] = implode("\n", $shelf);
        } elseif ($products === '') {
            $sections[] = '';
            $sections[] = 'No product in the catalogue matched this question. Do not guess at one.';
        }

        $sections[] = '';
        $sections[] = 'THIS CUSTOMER';
        $sections[] = $user ? '- Signed in as '.$user->name : '- Not signed in';
        $sections[] = $cart['count'] > 0
            ? '- Cart: '.$cart['count'].' items — '.collect($cart['items'])
                ->map(fn ($line) => "{$line['name']} x{$line['qty']} at {$line['each']}")
                ->implode(', ')
            : '- Cart is empty';

        if ($order) {
            $sections[] = sprintf(
                '- Most recent order %s, placed %s, status %s, %s, paid by %s (%s)%s',
                $order['reference'],
                $order['placed'],
                $order['status'],
                $order['total'],
                $order['payment'] ?? 'not chosen',
                $order['payment_status'] ?? 'unknown',
                $order['arriving'] ? ', arriving '.$order['arriving'] : '',
            );
        }

        return implode("\n", $sections);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    protected function hereYouAre(string $lang, array $actions): string
    {
        if ($actions === []) {
            return $lang === 'kh' ? 'សូមសួរម្ដងទៀតបាទ។' : 'Could you ask that again?';
        }

        return $lang === 'kh'
            ? 'ចុចខាងក្រោមដើម្បីដាក់ចូលកន្ត្រក។'
            : 'Tap below to add it to your cart.';
    }

    protected function notConfigured(string $lang): string
    {
        return $lang === 'kh'
            ? 'សុំទោស ជំនួយការឆ្លាតវៃមិនទាន់ដំណើរការនៅឡើយទេ។ សូមសរសេរមកយើងតាម Telegram '.config('cambodia.shop.telegram').' យើងនឹងឆ្លើយតបភ្លាម។'
            : 'The assistant is not switched on yet. Message us on Telegram @'.config('cambodia.shop.telegram').' and we will help.';
    }

    protected function unavailable(string $lang): string
    {
        return $lang === 'kh'
            ? 'សុំទោស ខ្ញុំមិនអាចឆ្លើយបានឥឡូវនេះទេ។ សូមសាកម្ដងទៀត ឬសរសេរមក Telegram '.config('cambodia.shop.telegram').'។'
            : 'Sorry, I could not answer just now. Please try again, or message us on Telegram @'.config('cambodia.shop.telegram').'.';
    }

    protected function tooFast(string $lang): string
    {
        return $lang === 'kh'
            ? 'សូមរង់ចាំបន្តិច រួចសួរម្ដងទៀត។'
            : 'One moment please — then ask again.';
    }
}
