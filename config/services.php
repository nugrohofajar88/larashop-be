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

    'biteship' => [
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
        'api_key' => env('BITESHIP_API_KEY'),
    ],

    'rajaongkir' => [
        'base_url' => env('RAJAONGKIR_BASE_URL', 'https://rajaongkir.komerce.id/api/v1/'),
        'api_key' => env('RAJAONGKIR_API_KEY'),
        'available_couriers' => array_values(array_filter(array_map('trim', explode(',', (string) env('AVAILABLE_COURIER', 'jne,jnt,sicepat,ide,sap,ninja,tiki,lion,anteraja'))))),
    ],

    'checkout' => [
        'use_unique_code' => filter_var(env('CHECKOUT_USE_UNIQUE_CODE', true), FILTER_VALIDATE_BOOL),
    ],

    'shipping' => [
        // Pembagi berat volumetrik. Standar kurir domestik Indonesia = 6000.
        'volumetric_divisor' => (int) env('SHIPPING_VOLUMETRIC_DIVISOR', 6000),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'wablas' => [
        'base_url' => env('WABLAS_BASE_URL', 'https://wablas.com'),
        'token' => env('WABLAS_TOKEN'),
        'secret_key' => env('WABLAS_SECRET_KEY'),
        'webhook_secret' => env('WABLAS_WEBHOOK_SECRET'),
    ],
];

