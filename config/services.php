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

    // A Discord webhook URL for operational alerts (a stalled daily
    // pricing sync, a scheduled command that errored outright) — see
    // App\Support\DiscordAlerter. Deliberately not Horizon's own
    // routeSlackNotificationsTo(): this project has no Slack workspace,
    // and Horizon doesn't ship a Discord notification channel. Left
    // empty, alerting is silently off rather than erroring.
    'discord' => [
        'alert_webhook_url' => env('DISCORD_ALERT_WEBHOOK_URL'),
    ],

];
