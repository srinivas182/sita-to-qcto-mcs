<?php

declare(strict_types=1);

namespace Modules\Onboarding\Support;

/**
 * Format checks for South African identifiers, run while the applicant types so
 * they can fix mistakes themselves.
 */
final class Identifiers
{
    /** CIPC enterprise number: YYYY/NNNNNN/NN, with a plausible year. */
    public static function cipc(?string $value): bool
    {
        if ($value === null || preg_match('#^(\d{4})/\d{6}/\d{2}$#', trim($value), $m) !== 1) {
            return false;
        }

        return (int) $m[1] >= 1900 && (int) $m[1] <= (int) now()->format('Y');
    }

    /** QCTO accreditation number, e.g. 07-QCTO/SDP180825105627 or 05-QCTO/AC-TTC130123111044. */
    public static function qcto(?string $value): bool
    {
        return $value !== null && preg_match('#^\d{2}-QCTO/(SDP|AC-TTC|AC)\d{12}$#i', trim($value)) === 1;
    }

    /** SA VAT number: 10 digits starting with 4. */
    public static function vat(?string $value): bool
    {
        return $value !== null && preg_match('/^4\d{9}$/', trim($value)) === 1;
    }

    /** Upper-case and strip spaces so the same number always compares equal. */
    public static function normalise(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
