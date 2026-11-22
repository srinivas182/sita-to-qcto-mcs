<?php

declare(strict_types=1);

namespace Modules\Tenancy\Enums;

enum DomainType: string
{
    /** {code}.{platform base domain}; covered by the wildcard certificate. */
    case Subdomain = 'subdomain';

    /** The provider's own hostname, e.g. learn.trainingyoungminds.co.za. */
    case Custom = 'custom';
}
