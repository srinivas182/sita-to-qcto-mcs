<?php

declare(strict_types=1);

return [
    /*
     | Secret mixed into identity-number and PIN hashes. Must differ from APP_KEY
     | and stay stable: changing it makes existing ID numbers unfindable.
     */
    'pepper' => env('IDENTITY_PEPPER', env('APP_KEY')),

    'default_country' => 'ZA',

    'otp' => [
        'length' => 6,
        'ttl' => 300,
        'max_attempts' => 5,
        'per_identifier_per_hour' => 5,
        'per_ip_per_hour' => 30,
        'resend_after' => 30,
        /* Delivery order; the next channel is tried if one fails. */
        'channels' => array_filter(explode(',', (string) env('OTP_CHANNELS', 'whatsapp,sms'))),
        'drivers' => [
            'whatsapp' => env('OTP_WHATSAPP_DRIVER', 'log'),
            'sms' => env('OTP_SMS_DRIVER', 'log'),
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
