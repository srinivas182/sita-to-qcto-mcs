<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

enum IdType: string
{
    case SaId = 'sa_id';
    case Passport = 'passport';
}
