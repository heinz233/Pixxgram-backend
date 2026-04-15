<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // -----------------------------------------------------------------
    // M-Pesa (Safaricom Daraja API)
    // -----------------------------------------------------------------
    'mpesa' => [
        'consumer_key'      => env('MPESA_CONSUMER_KEY'),
        'consumer_secret'   => env('MPESA_CONSUMER_SECRET'),
        'business_shortcode'=> env('MPESA_BUSINESS_SHORTCODE'),
        'passkey'           => env('MPESA_PASSKEY'),
        'environment'       => env('MPESA_ENVIRONMENT', 'sandbox'), // 'sandbox' | 'production'
        'callback_url'      => env('MPESA_CALLBACK_URL'),
    ],

];
