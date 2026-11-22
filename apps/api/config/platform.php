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
];
