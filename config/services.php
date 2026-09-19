<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Google Sign-In (Laravel Socialite).
    |
    | Create the OAuth client at https://console.cloud.google.com/apis/credentials
    | and add the redirect URI shown in GOOGLE_REDIRECT_URI to it verbatim.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    /*
    | CutLuy — KHQR payments (Cambodia).
    |
    | 'key'            is the secret API key (ck_live_... / ck_test_...).
    | 'webhook_secret' is the endpoint secret used to sign webhook deliveries.
    | Both come from the environment and are never committed.
    */
    'cutluy' => [
        'base_url' => rtrim(env('CUTLUY_BASE_URL', 'https://cutluy.com'), '/'),
        'key' => env('CUTLUY_API_KEY'),
        'webhook_secret' => env('CUTLUY_WEBHOOK_SECRET'),

        // Reject webhook deliveries whose timestamp is further away than this
        // many seconds, so a captured delivery cannot be replayed later.
        'webhook_tolerance' => (int) env('CUTLUY_WEBHOOK_TOLERANCE', 300),

        'timeout' => (int) env('CUTLUY_TIMEOUT', 15),

        // Shown on our own pay page; match the merchant name on your
        // CutLuy account so the customer sees the same name we do.
        'merchant_name' => env('CUTLUY_MERCHANT_NAME', 'Grocery Store'),
    ],

    /*
    | Google AI Studio (Gemini) — the shop assistant.
    |
    | 'key' is the AI Studio API key and comes from the environment. With no
    | key the assistant declines politely instead of erroring, so the widget
    | can ship before the account is set up.
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // An alias rather than a pinned version: Google retires the numbered
        // ones, and a 404 there takes the assistant down with it. Pin a
        // specific model in .env if you would rather control when it moves.
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
        'base_url' => rtrim(env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'), '/'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 25),
        'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 700),
    ],

];
