<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Common site API: requests per minute from one sender IP (any source, before the key
    // is checked) and per authenticated source from one sender IP.
    'site_api' => [
        'ip_per_minute' => (int) env('SITE_API_IP_PER_MINUTE', 300),
        'per_minute' => (int) env('SITE_API_PER_MINUTE', 60),
    ],

    // Mango VPBX API (MangoOffice_VPBX_API v1.9): base address 2.1.1; recordings are played
    // through 3.5.2 — `GET queries/recording/link/{id}/play/{key}/{timestamp}/{sign}` answers
    // 302 to a one-time link of the file service. The audio stays at Mango.
    'mango' => [
        'api_url' => env('MANGO_API_URL', 'https://app.mango-office.ru/vpbx/'),
        'recordings' => [
            // Seconds a generated 3.5.2 link stays valid (its `timestamp`).
            'link_ttl' => (int) env('MANGO_RECORDING_LINK_TTL', 300),
            // Hosts a redirect of the API may lead to (3.5.2 example: files.mango-office.ru).
            'file_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MANGO_RECORDING_FILE_HOSTS', 'files.mango-office.ru'))))),
            'types' => ['audio/mpeg', 'audio/mp3'],
            'connect_timeout' => (int) env('MANGO_RECORDING_CONNECT_TIMEOUT', 10),
            // Waiting for headers and for each next piece of the stream.
            'request_timeout' => (int) env('MANGO_RECORDING_REQUEST_TIMEOUT', 30),
            // Upper bound of one streamed response.
            'stream_seconds' => (int) env('MANGO_RECORDING_STREAM_SECONDS', 3600),
        ],
    ],

];
