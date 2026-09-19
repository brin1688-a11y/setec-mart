<?php

namespace App\Services\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Google AI Studio (Gemini) for the shop assistant.
 *
 * Kept deliberately thin: it sends the system instruction plus the question
 * and hands back the text along with any action the model proposed. What the
 * assistant is allowed to know is decided by ShopContext and baked into the
 * prompt, not here.
 */
class ChatClient
{
    public function __construct(
        protected ?string $apiKey,
        protected string $model,
        protected string $baseUrl,
        protected int $timeout = 25,
        protected int $maxTokens = 700,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * The one thing the assistant may ask the shop to do.
     *
     * Declared as a tool so the model returns structured arguments instead of
     * prose we would have to guess at. Nothing is added to a basket from here
     * — the caller checks the proposal against the database and the customer
     * confirms it with a button.
     *
     * @return array<string, mixed>
     */
    protected function tools(): array
    {
        return [[
            'function_declarations' => [[
                'name' => 'add_to_cart',
                'description' => 'Offer to put a product in the customer\'s cart. '
                    .'Only use a product_id listed in the shop facts. The customer '
                    .'still has to confirm, so offer it whenever they say they want something.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'The id of the product, exactly as listed in the shop facts.',
                        ],
                        'quantity' => [
                            'type' => 'integer',
                            'description' => 'How many, at least 1. Use 1 unless the customer asked for more.',
                        ],
                    ],
                    'required' => ['product_id'],
                ],
            ]],
        ]];
    }

    /**
     * Ask for a reply.
     *
     * @return array{text: ?string, actions: array<int, array{product_id: int, quantity: int}>}|null
     *                                                                                               null when the assistant could not be reached
     */
    public function reply(string $systemPrompt, string $message): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $url = sprintf('%s/v1beta/models/%s:generateContent', $this->baseUrl, $this->model);

        try {
            $response = Http::withHeaders([
                // In a header rather than the query string, so the key stays
                // out of URLs, proxy logs and browser history.
                'x-goog-api-key' => $this->apiKey,
            ])
                ->timeout($this->timeout)
                // One retry for a dropped connection only; a refusal or a bad
                // request will not fix itself by being sent again.
                ->retry(2, 400, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($url, [
                    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $message]]]],
                    'tools' => $this->tools(),
                    'generationConfig' => [
                        'maxOutputTokens' => $this->maxTokens,
                        // Factual shop answers, not creative writing.
                        'temperature' => 0.3,
                        // The current flash models reason before answering and
                        // charge the output budget for it — enough to swallow
                        // the whole allowance and return nothing. A shop FAQ
                        // does not need it, and without it replies are quicker.
                        'thinkingConfig' => ['thinkingBudget' => 0],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Could not reach the chat assistant: '.$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            Log::warning('The chat assistant refused a request.', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        $candidate = $response->json('candidates.0', []);
        $parsed = $this->parse($candidate['content']['parts'] ?? []);

        // An answer cut off mid-sentence is worth knowing about: it means the
        // token budget is too tight for the prompt being sent.
        if ($parsed['text'] === null && $parsed['actions'] === []) {
            Log::warning('The chat assistant returned nothing.', [
                'finish_reason' => $candidate['finishReason'] ?? null,
            ]);

            return null;
        }

        return $parsed;
    }

    /**
     * Pull the wording and any proposed action out of the reply.
     *
     * Gemini returns content as a list of parts: text, function calls, or
     * both in the same answer.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array{text: ?string, actions: array<int, array{product_id: int, quantity: int}>}
     */
    protected function parse(array $parts): array
    {
        $text = [];
        $actions = [];

        foreach ($parts as $part) {
            if (isset($part['text']) && trim($part['text']) !== '') {
                $text[] = trim($part['text']);
            }

            $call = $part['functionCall'] ?? null;

            if (($call['name'] ?? null) === 'add_to_cart') {
                $id = (int) ($call['args']['product_id'] ?? 0);

                if ($id > 0) {
                    $actions[] = [
                        'product_id' => $id,
                        'quantity' => max(1, (int) ($call['args']['quantity'] ?? 1)),
                    ];
                }
            }
        }

        return [
            'text' => $text === [] ? null : implode("\n", $text),
            'actions' => $actions,
        ];
    }
}
