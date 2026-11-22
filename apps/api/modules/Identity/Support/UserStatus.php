<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
