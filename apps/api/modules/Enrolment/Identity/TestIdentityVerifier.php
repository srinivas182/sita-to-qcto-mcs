<?php

declare(strict_types=1);

namespace Modules\Enrolment\Identity;

use Modules\Identity\Support\SaIdNumber;
use Modules\Settings\Models\Integration;

/**
 * For trying the flow without calling Home Affairs: answers from settings. A number listed
 * as "not_found" is not found, one listed as "deceased" is not alive; any other valid
 * number is found under the names in "names" (ID => "First|Surname"), else the person's own.
 */
final class TestIdentityVerifier implements IdentityVerifier
{
    public function verify(Integration $integration, string $idNumber): array
    {
        $sa = SaIdNumber::tryParse($idNumber);
        $list = fn (string $k): array => array_filter(array_map('trim', explode(',', (string) $integration->value($k, ''))));
        if ($sa === null || in_array($idNumber, $list('not_found'), true)) {
            return ['found' => false, 'alive' => null, 'first_names' => null, 'surname' => null, 'dob' => null, 'cost_cents' => 0];
        }
        $names = (array) json_decode((string) $integration->value('names', '{}'), true);
        [$first, $last] = array_pad(explode('|', (string) ($names[$idNumber] ?? '')), 2, null);

        return ['found' => true, 'alive' => ! in_array($idNumber, $list('deceased'), true), 'first_names' => $first ?: null, 'surname' => $last ?: null,
            'dob' => $sa->dateOfBirth->toDateString(), 'cost_cents' => 0];
    }
}
