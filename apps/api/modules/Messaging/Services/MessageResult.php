<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use Modules\Messaging\Models\Message;

final readonly class MessageResult
{
    public function __construct(public bool $sent, public ?Message $message, public ?string $error = null) {}
}
