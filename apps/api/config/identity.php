<?php

declare(strict_types=1);

return [
    /*
     | Secret mixed into identity-number and PIN hashes. Must differ from APP_KEY
     | and stay stable: changing it makes existing ID numbers unfindable.
     */
    'pepper' => env('IDENTITY_PEPPER') ?: env('APP_KEY'),

    'default_country' => 'ZA',

    'otp' => [
        'length' => 6,
        'ttl' => 300,
        'max_attempts' => 5,
        'per_identifier_per_hour' => 5,
        'per_ip_per_hour' => 30,
        'resend_after' => 30,
        /* Local only: write PINs to the log so the log driver can be used to sign in. */
        'log_codes' => (bool) env('OTP_LOG_CODES', false),
        /* Delivery order; the next channel is tried if one fails. */
        'channels' => array_filter(explode(',', (string) env('OTP_CHANNELS', 'whatsapp,sms'))),
        /*
         * Android SMS Retriever: the 11-character hash of the app's signing key (shown in
         * the app under Settings → About). When set, SMS codes end with it and Android
         * hands the code to the app with no permission prompt.
         */
        'android_sms_hash' => env('ANDROID_SMS_HASH'),
        /* "messaging": the providers switched on in Settings (Sprint 2c); "log": local only. */
        'drivers' => [
            'whatsapp' => env('OTP_WHATSAPP_DRIVER', 'messaging'),
            'sms' => env('OTP_SMS_DRIVER', 'messaging'),
        ],
    ],

    'login' => [
        'max_attempts' => 5,
        /* Lock-out seconds after each further block of failures. */
        'lockouts' => [60, 300, 900, 3600],
        'mfa_challenge_ttl' => 300,
    ],

    'tokens' => [
        'access_ttl' => (int) env('ACCESS_TOKEN_TTL', 900),
        'refresh_ttl' => (int) env('REFRESH_TOKEN_TTL', 2592000),
        'offline_days' => 14,
        'signature_skew' => 300,
    ],

    'passkeys' => [
        'challenge_ttl' => 300,
        'timeout_ms' => 60000,
    ],

    'invitations' => [
        'ttl_days' => 7,
    ],

    'support_access' => [
        'max_minutes' => 240,
    ],
];
