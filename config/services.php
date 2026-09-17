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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Each connected store supplies its own OAuth client_id/client_secret
    // through the "Add New Store" form (see ShopifyStoreController) — that's
    // intentional for a per-store custom-app integration, so there is no
    // global client id/secret here. api_version is the one thing shared
    // across all stores; Shopify retires API versions roughly once a year,
    // so bump SHOPIFY_API_VERSION in .env when Shopify emails a deprecation
    // notice instead of editing code.
    'shopify' => [
        'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
    ],

];
