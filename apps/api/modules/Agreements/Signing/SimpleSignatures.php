<?php

declare(strict_types=1);

namespace Modules\Agreements\Signing;

use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Services\Invitations;

/** Our own signing: a link to each signer; identity confirmed by a one-time PIN when they sign. */
final class SimpleSignatures implements SignatureProvider
{
    public function __construct(private readonly Invitations $invitations) {}

    public function level(): string
    {
        return 'simple';
    }

    public function available(): bool
    {
        return true;
    }

    public function start(Agreement $agreement): void
    {
        $this->invitations->sendAll($agreement);
    }

    public function sync(Agreement $agreement): bool
    {
        return $agreement->status === 'completed';
    }
}
