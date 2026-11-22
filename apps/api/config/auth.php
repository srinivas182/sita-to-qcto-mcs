<?php

declare(strict_types=1);

use Modules\Identity\Models\User;

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        // Web app: cookie session (same-origin on every tenant host).
        // Mobile app: Sanctum bearer tokens (see config/sanctum.php).
        'web' => ['driver' => 'session', 'provider' => 'users'],
    ],

    'providers' => [
        // People are global; PlatformUserProvider loads them regardless of tenant,
        // and the EnsureAccess middleware decides whether they may act here.
        'users' => ['driver' => 'platform-eloquent', 'model' => User::class],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
