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
    // config/services.php

    'sabre' => [
        'url'           => env('SABRE_API_URL', 'https://api.platform.sabre.com'),
        'client_id'     => env('SABRE_CLIENT_ID'),
        'client_secret' => env('SABRE_CLIENT_SECRET'),
        'pcc'           => env('SABRE_PCC'),
        'target'        => env('SABRE_TARGET', 'Test'), // 'Test' ou 'Production'
    ],
    'travelport' => [
        'username' => env('TRAVELPORT_USERNAME'),
        'password' => env('TRAVELPORT_PASSWORD'),
        'auth_url'      => env('TRAVELPORT_AUTH_URL'),
        'base_url'      => env('TRAVELPORT_BASE_URL'),
        'client_id'     => env('TRAVELPORT_CLIENT_ID'),
        'client_secret' => env('TRAVELPORT_CLIENT_SECRET'),
        'target_branch' => env('TRAVELPORT_TARGET_BRANCH'),
        'pcc' => env('TRAVELPORT_PCC'),
        'access_group' => env('TRAVELPORT_ACCESS_GROUP'),
    ],

];
