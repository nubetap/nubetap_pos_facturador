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

    'resend' => [
        'key' => env('RESEND_KEY'),
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
    |--------------------------------------------------------------------------
    | ValidaPSE
    |--------------------------------------------------------------------------
    |
    | Proveedor PSE externo que firma + envía CPE a SUNAT con su propio
    | certificado. Usado para clientes NRUS (Company.cpe_provider == 'validapse').
    | Doc: docs/INTEGRACION-VALIDAPSE-NRUS.md
    |
    | El token NO se setea aquí — cada Company tiene su propio
    | validapse_token_acceso (sincronizado desde Django).
    */

    'validapse' => [
        'base_url' => env('VALIDAPSE_BASE_URL', 'https://app.validapse.com'),
        'timeout' => env('VALIDAPSE_TIMEOUT', 30),
        'retry_times' => env('VALIDAPSE_RETRY_TIMES', 2),
        'retry_sleep_ms' => env('VALIDAPSE_RETRY_SLEEP_MS', 500),
    ],

];
