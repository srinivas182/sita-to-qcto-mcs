<?php

declare(strict_types=1);

return [
    /* Argon2id for passwords (OWASP recommendation). */
    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    /*
     | 64 MiB, 2 passes: above the OWASP Argon2id baseline (46 MiB × 1 or 19 MiB × 2)
     | while keeping sign-in inside the 250 ms write budget (~110 ms per hash on a
     | small 2-core host; measured at Sprint 1 close). Older hashes upgrade on login.
     */
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 2),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => true,
];
