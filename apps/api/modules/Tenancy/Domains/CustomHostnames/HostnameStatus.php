<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains\CustomHostnames;

use Modules\Tenancy\Enums\SslStatus;

final readonly class HostnameStatus
{
    public function __construct(
        public SslStatus $ssl,
        public ?string $message = null,
    ) {}
}
