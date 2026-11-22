<?php

declare(strict_types=1);

namespace Modules\Tenancy\Enums;

enum CellStatus: string
{
    /** Accepts new tenants. */
    case Active = 'active';

    /** Serves its tenants but takes no new ones. */
    case Full = 'full';

    /** Being drained or under maintenance; takes no new tenants. */
    case Draining = 'draining';
}
