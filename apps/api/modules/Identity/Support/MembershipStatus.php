<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Left = 'left';
}
