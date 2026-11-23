<?php

declare(strict_types=1);

namespace Modules\Enrolment\Identity;

use Modules\Settings\Models\Integration;

/**
 * Checks a South African ID number against the Home Affairs population register, through
 * a reseller the JV contracts with. One adapter per reseller; the rest of the platform
 * sees only this.
 */
interface IdentityVerifier
{
    /** @return array{found: bool, alive: bool|null, first_names: string|null, surname: string|null, dob: string|null, cost_cents: int|null} */
    public function verify(Integration $integration, string $idNumber): array;
}
