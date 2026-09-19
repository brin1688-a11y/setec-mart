<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-key',
            'services.gemini.model' => 'gemini-2.0-flash',
        ]);

        $this->category = Category::create(['name' => 'Drinks']);
    }

    protected function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name' => $name,
            'description' => 'Cold and fizzy',
            'price' => 1.50,
            'stock' => 24,
            'status' => true,
        ], $overrides));
    }

    /**
     * Gemini answering with words, and optionally offering to add something.
     *
     * @param  array<int, array{id: int, qty?: int}>  $adds
     */
    protected function fakeGemini(string $reply = 'We have it in stock.', array $adds = []): void
    {
        $parts = $reply === '' ? [] : [['text' => $reply]];

        foreach ($adds as $add) {
            $parts[] = ['functionCall' => [
                'name' => 'add_to_cart',
                'args' => ['product_id' => $add['id'], 'quantity' => $add['qty'] ?? 1],
            ]];
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => $parts]]],
            ]),
        ]);
    }

    /**
     * The system prompt sent to Claude on the last call.
     */
    protected function promptSent(): string
    {
        $prompt = '';

        Http::assertSent(function ($request) use (&$prompt) {
            if (str_contains($request->url(), 'generativelanguage')) {
                $prompt = data_get($request->data(), 'system_instruction.parts.0.text', '');
            }

            return true;
        });

        return $prompt;
    }

    protected function ask(string $message, array $extra = [])
    {
        return $this->postJson('/api/chat', array_merge(['message' => $message], $extra));
    }

    // ---- The endpoint ----------------------------------------------------

    public function test_it_answers_a_question(): void
    {
        $this->product('Coca-Cola');
        $this->fakeGemini('Yes, Coca-Cola is $1.50 and we have 24 in stock.');

        $this->ask('do you have coca-cola?')
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('reply', 'Yes, Coca-Cola is $1.50 and we have 24 in stock.');
    }

    public function test_a_message_is_required(): void
    {
        Http::fake();

        $this->ask('')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_an_unsupported_language_is_refused(): void
    {
        Http::fake();

        $this->ask('hello', ['lang' => 'fr'])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_it_declines_politely_when_no_api_key_is_set(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $response = $this->ask('hello')->assertOk()->assertJson(['configured' => false]);

        // A shop without an assistant should still point people somewhere.
        $this->assertStringContainsString('Telegram', $response->json('reply'));
        Http::assertNothingSent();
    }

    public function test_it_says_so_rather_than_erroring_when_the_assistant_is_unreachable(): void
    {
        $this->product('Coca-Cola');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        $this->ask('do you have cola?')
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonFragment(['reply' => 'Sorry, I could not answer just now. Please try again, or message us on Telegram @setecmart.']);
    }

    public function test_questions_are_rate_limited(): void
    {
        $this->fakeGemini();

        for ($i = 0; $i < 15; $i++) {
            $this->ask('hello '.$i)->assertOk();
        }

        // Every reply costs money; one visitor cannot hold the tap open.
        $this->ask('hello again')->assertStatus(429)->assertJson(['throttled' => true]);
    }

    // ---- What the assistant is told --------------------------------------

    public function test_the_prompt_carries_the_matching_products_with_live_prices(): void
    {
        $this->product('Coca-Cola', ['price' => 2.00, 'stock' => 7]);
        $this->product('Rice 5kg', ['price' => 9.00]);
        $this->fakeGemini();

        $this->ask('how much is coca-cola?');

        $prompt = $this->promptSent();

        $this->assertStringContainsString('Coca-Cola', $prompt);
        $this->assertStringContainsString('$2.00', $prompt);
        $this->assertStringContainsString('7 in stock', $prompt);

        // The question's own match is singled out, above the full shelf.
        $matched = Str::between(
            $prompt, 'PRODUCTS MATCHING THE QUESTION', 'EVERYTHING ON SALE'
        );

        $this->assertStringContainsString('Coca-Cola', $matched);
        $this->assertStringNotContainsString('Rice 5kg', $matched);
    }

    public function test_a_promotion_price_is_what_the_assistant_is_given(): void
    {
        $this->product('Coca-Cola', ['price' => 2.00, 'sale_price' => 1.20]);
        $this->fakeGemini();

        $this->ask('coca-cola price?');

        $prompt = $this->promptSent();

        $this->assertStringContainsString('$1.20', $prompt);
        $this->assertStringContainsString('was $2.00', $prompt);
    }

    public function test_an_out_of_stock_product_is_described_as_such(): void
    {
        $this->product('Coca-Cola', ['stock' => 0]);
        $this->fakeGemini();

        $this->ask('coca-cola?');

        $this->assertStringContainsString('out of stock', $this->promptSent());
    }

    public function test_a_hidden_product_is_never_mentioned(): void
    {
        $this->product('Secret Recipe', ['status' => false]);
        $this->fakeGemini();

        $this->ask('secret recipe?');

        $this->assertStringNotContainsString('Secret Recipe', $this->promptSent());
    }

    public function test_the_prompt_carries_the_delivery_rules(): void
    {
        $this->fakeGemini();

        $this->ask('how much is delivery?');

        $prompt = $this->promptSent();

        $this->assertStringContainsString('Phnom Penh', $prompt);
        $this->assertStringContainsString('KHQR', $prompt);
    }

    public function test_a_signed_in_customers_cart_is_read_from_the_database(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $product = $this->product('Coca-Cola');

        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 3]);

        $this->fakeGemini();

        // The browser claims something different; the database wins.
        $this->actingAs($user)->ask('what is in my cart?', [
            'cart' => [['id' => $product->id, 'qty' => 99]],
        ]);

        $prompt = $this->promptSent();

        $this->assertStringContainsString('Coca-Cola x3', $prompt);
        $this->assertStringNotContainsString('x99', $prompt);
    }

    public function test_a_guests_basket_is_looked_up_rather_than_taken_on_trust(): void
    {
        $product = $this->product('Coca-Cola', ['price' => 1.50]);
        $this->fakeGemini();

        $this->ask('what is in my cart?', [
            // A made-up id alongside a real one.
            'cart' => [['id' => $product->id, 'qty' => 2], ['id' => 99999, 'qty' => 5]],
        ]);

        $prompt = $this->promptSent();

        $this->assertStringContainsString('Coca-Cola x2 at $1.50', $prompt);
        $this->assertStringContainsString('Cart: 2 items', $prompt);
    }

    public function test_the_customers_latest_order_is_available_to_answer_from(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Sok Dara',
            'phone' => '012345678',
            'province' => 'Phnom Penh',
            'district' => 'Chamkar Mon',
            'commune' => 'Tonle Bassac',
            'address' => 'St. 271',
            'status' => 'Out for Delivery',
            'subtotal' => 10.00,
            'discount' => 0,
            'delivery_fee' => 1.50,
            'total' => 11.50,
        ]);

        $this->fakeGemini();

        $this->actingAs($user)->ask('where is my order?');

        $prompt = $this->promptSent();

        $this->assertStringContainsString($order->order_number, $prompt);
        $this->assertStringContainsString('Out for Delivery', $prompt);
    }

    public function test_one_customer_never_sees_anothers_order(): void
    {
        $mine = User::factory()->create(['role' => 'customer']);
        $theirs = User::factory()->create(['role' => 'customer']);

        Order::create([
            'user_id' => $theirs->id,
            'name' => 'Someone Else',
            'phone' => '012000111',
            'province' => 'Phnom Penh',
            'district' => 'X', 'commune' => 'Y', 'address' => 'Z',
            'status' => 'Delivered',
            'subtotal' => 5, 'discount' => 0, 'delivery_fee' => 0, 'total' => 5,
        ]);

        $this->fakeGemini();

        $this->actingAs($mine)->ask('where is my order?');

        $this->assertStringNotContainsString('Someone Else', $this->promptSent());
    }

    public function test_khmer_is_asked_for_when_the_customer_writes_in_khmer(): void
    {
        $this->fakeGemini();

        $this->ask('មានទឹកក្រូចទេ?', ['lang' => 'kh']);

        $this->assertStringContainsString('Khmer', $this->promptSent());
    }

    public function test_english_is_the_default(): void
    {
        $this->fakeGemini();

        $this->ask('do you have orange juice?');

        $prompt = $this->promptSent();

        $this->assertStringContainsString('Reply in English', $prompt);
        $this->assertStringNotContainsString('Reply in Khmer', $prompt);
    }

    public function test_the_assistant_is_told_not_to_invent_anything(): void
    {
        $this->fakeGemini();

        $this->ask('do you sell motorbikes?');

        $prompt = $this->promptSent();

        $this->assertStringContainsString('Never invent a product', $prompt);
        $this->assertStringContainsString('Answer only from the shop facts', $prompt);
    }

    // ---- Offering to put things in the cart ------------------------------

    public function test_it_offers_to_add_what_the_customer_asked_for(): void
    {
        $product = $this->product('Coca-Cola', ['price' => 1.50, 'stock' => 20]);
        $this->fakeGemini('Sure — Coca-Cola is $1.50.', [['id' => $product->id, 'qty' => 2]]);

        $response = $this->ask('I want two cokes')->assertOk();

        $response->assertJsonPath('actions.0.type', 'add_to_cart')
            ->assertJsonPath('actions.0.product_id', $product->id)
            ->assertJsonPath('actions.0.quantity', 2)
            ->assertJsonPath('actions.0.price', '$3.00')
            ->assertJsonPath('actions.0.url', route('cart.add', $product));

        // Offering is not doing — nothing reaches the cart until the
        // customer presses the button.
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_an_offer_is_capped_at_what_is_on_the_shelf(): void
    {
        $product = $this->product('Coca-Cola', ['stock' => 3]);
        $this->fakeGemini('Adding those now.', [['id' => $product->id, 'qty' => 50]]);

        $this->ask('give me 50 cokes')
            ->assertOk()
            ->assertJsonPath('actions.0.quantity', 3);
    }

    public function test_an_offer_for_an_out_of_stock_product_is_dropped(): void
    {
        $product = $this->product('Coca-Cola', ['stock' => 0]);
        $this->fakeGemini('Here you go.', [['id' => $product->id, 'qty' => 1]]);

        $this->ask('one coke please')
            ->assertOk()
            ->assertJsonPath('actions', []);
    }

    public function test_an_offer_for_a_hidden_product_is_dropped(): void
    {
        $product = $this->product('Secret Recipe', ['status' => false]);
        $this->fakeGemini('Adding it.', [['id' => $product->id, 'qty' => 1]]);

        $this->ask('secret recipe please')
            ->assertOk()
            ->assertJsonPath('actions', []);
    }

    public function test_an_invented_product_id_is_dropped(): void
    {
        // The model's ids are a suggestion, never authority.
        $this->fakeGemini('Adding it.', [['id' => 987654, 'qty' => 1]]);

        $this->ask('add something')
            ->assertOk()
            ->assertJsonPath('actions', []);
    }

    public function test_an_offer_with_no_wording_still_says_something(): void
    {
        $product = $this->product('Coca-Cola');
        $this->fakeGemini('', [['id' => $product->id, 'qty' => 1]]);

        $reply = $this->ask('one coke')->assertOk()->json('reply');

        $this->assertNotEmpty($reply);
    }

    public function test_the_assistant_is_told_it_cannot_pay_or_cancel(): void
    {
        $this->fakeGemini();

        $this->ask('cancel my order and pay for me');

        $prompt = $this->promptSent();

        $this->assertStringContainsString('cannot place the order, pay, cancel', $prompt);
        $this->assertStringContainsString('Never guess one', $prompt);
    }

    public function test_the_prompt_carries_the_product_id_and_description(): void
    {
        $product = $this->product('Coca-Cola', ['description' => 'Chilled 330ml can, sold singly.']);
        $this->fakeGemini();

        $this->ask('tell me about coca-cola');

        $prompt = $this->promptSent();

        // The id is how the assistant proposes adding it.
        $this->assertStringContainsString('[id '.$product->id.']', $prompt);
        $this->assertStringContainsString('330ml can', $prompt);
    }

    public function test_the_whole_shelf_is_offered_so_khmer_can_be_matched(): void
    {
        // A question in Khmer will never match an English product name by
        // keyword, so the assistant is handed the list and does it itself.
        $this->product('Orange Juice', ['price' => 2.50, 'stock' => 12]);
        $this->fakeGemini();

        $this->ask('មានទឹកក្រូចទេ?', ['lang' => 'kh']);

        $prompt = $this->promptSent();

        $this->assertStringContainsString('EVERYTHING ON SALE', $prompt);
        $this->assertStringContainsString('Orange Juice', $prompt);
        $this->assertStringContainsString('12 in stock', $prompt);
    }

    public function test_a_hidden_product_stays_off_the_shelf_too(): void
    {
        $this->product('Secret Recipe', ['status' => false]);
        $this->product('Coca-Cola');
        $this->fakeGemini();

        $this->ask('what do you sell?');

        $this->assertStringNotContainsString('Secret Recipe', $this->promptSent());
    }

    // ---- The widget ------------------------------------------------------

    public function test_the_widget_is_on_the_storefront(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('chatWidget', false)
            ->assertSee('window.siteLang', false)
            // @json escapes the slashes in the URL — valid JSON, and the
            // browser parses it back to the real address.
            ->assertSee(str_replace('/', '\/', route('chat.handle')), false);
    }
}
