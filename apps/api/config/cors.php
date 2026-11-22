<?php

declare(strict_types=1);

/*
 | Strict by design: the web app is served from the same host as the API on every
 | tenant domain, and the mobile app is not a browser. No cross-origin access is
 | granted to any path, so browsers block every foreign site from calling the API.
 */
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
