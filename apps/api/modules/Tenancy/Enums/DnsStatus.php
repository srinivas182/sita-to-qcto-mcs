<?php

declare(strict_types=1);

namespace Modules\Tenancy\Enums;

enum DnsStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
