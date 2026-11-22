<?php

declare(strict_types=1);

return [
    /*
     | The cell this instance serves. A cell is a full, isolated stack that serves
     | a group of training providers. The global layer (identity, routing,
     | billing, shared registries) runs with cell = "global".
     */
    'cell' => env('PLATFORM_CELL', 'cell-1'),

    /* Base domain for free subdomains, e.g. tym.<base>. */
    'base_domain' => env('PLATFORM_BASE_DOMAIN', 'platform.test'),

    /*
     | Hosts that belong to the platform itself rather than to a tenant
     | (marketing site, Super Admin console, API used by the mobile app).
     | Relative names are prefixed to the base domain.
     */
    'platform_hosts' => array_filter(explode(',', (string) env('PLATFORM_HOSTS', '@,www,admin,api'))),

    /* Subdomain codes that tenants may never take. */
    'reserved_codes' => [
        'admin', 'api', 'app', 'apps', 'assets', 'auth', 'billing', 'blog', 'cdn', 'docs', 'edge',
        'help', 'id', 'mail', 'mobile', 'platform', 'qcto', 'seta', 'ceta', 'static', 'status',
        'support', 'verify', 'www',
    ],

    'tenancy' => [
        /* Cache store for host → tenant lookups (Redis in production). */
        'cache_store' => env('TENANCY_CACHE_STORE'),
        'cache_ttl' => (int) env('TENANCY_CACHE_TTL', 600),
        'negative_cache_ttl' => (int) env('TENANCY_NEGATIVE_CACHE_TTL', 60),

        /* Header the mobile app sends after the TP code / QR step. */
        'header' => 'X-Tenant',
    ],

    'domains' => [
        /* Target that custom domains point a CNAME to (Cloudflare for SaaS fallback origin). */
        'edge_hostname' => env('PLATFORM_EDGE_HOSTNAME', 'edge.platform.test'),

        /* Optional anycast IPs accepted for apex domains that cannot use a CNAME. */
        'edge_ips' => array_filter(explode(',', (string) env('PLATFORM_EDGE_IPS', ''))),

        /* TXT record label that proves ownership: <label>.<hostname> = token. */
        'challenge_label' => '_skills-challenge',

        /* Custom hostname / SSL provider: "log" (local, tests) or "cloudflare". */
        'provider' => env('CUSTOM_HOSTNAME_PROVIDER', 'log'),

        'cloudflare' => [
            'api' => env('CLOUDFLARE_API', 'https://api.cloudflare.com/client/v4'),
            'zone_id' => env('CLOUDFLARE_ZONE_ID'),
            'token' => env('CLOUDFLARE_API_TOKEN'),
            'timeout' => (int) env('CLOUDFLARE_TIMEOUT', 5),
        ],
    ],
];
