<?php

declare(strict_types=1);

namespace Modules\Agreements\Signing;

use Modules\Agreements\Models\Agreement;

/**
 * How an agreement's signatures are collected. "simple" is our own PIN-confirmed
 * signature; "advanced" hands the document to an accredited provider (LAWtrust).
 */
interface SignatureProvider
{
    public function level(): string;

    /** Whether it can be used now (e.g. the advanced provider is connected). */
    public function available(): bool;

    /** Start collecting signatures for a sent agreement. */
    public function start(Agreement $agreement): void;

    /** Pick up progress made outside the platform. Returns true when completed. */
    public function sync(Agreement $agreement): bool;
}
