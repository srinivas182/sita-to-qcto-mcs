<?php

declare(strict_types=1);

namespace Modules\Tenancy\Enums;

enum SslStatus: string
{
    case Pending = 'pending';
    case Issuing = 'issuing';
    case Active = 'active';
    case Failed = 'failed';
}
