<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Carbon\CarbonImmutable;

/**
 * South African identity number: YYMMDD SSSS C A Z (13 digits).
 *
 * - YYMMDD date of birth (century inferred: not in the future)
 * - SSSS gender sequence (0000–4999 female, 5000–9999 male) — not stored
 * - C citizenship: 0 citizen, 1 permanent resident, 2 refugee
 * - A historically race, now always 8 or 9 — ignored
 * - Z Luhn check digit
 */
final readonly class SaIdNumber
{
    private function __construct(
        public string $value,
        public CarbonImmutable $dateOfBirth,
        public int $citizenship,
    ) {}

    public static function tryParse(string $input, ?CarbonImmutable $today = null): ?self
    {
        $digits = (string) preg_replace('/\D/', '', $input);

        if (strlen($digits) !== 13 || ! self::luhn($digits)) {
            return null;
        }

        $citizenship = (int) $digits[10];

        if ($citizenship > 2) {
            return null;
        }

        $today ??= CarbonImmutable::today();
        $yy = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        $year = 2000 + $yy;

        if ($year > (int) $today->format('Y')) {
            $year -= 100;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $dob = CarbonImmutable::create($year, $month, $day);

        if ($dob === null) {
            return null;
        }

        if ($dob->greaterThan($today)) {
            $dob = $dob->subYears(100);
        }

        return new self($digits, $dob, $citizenship);
    }

    public static function isValid(string $input): bool
    {
        return self::tryParse($input) !== null;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($double) {
                $n *= 2;
                $n = $n > 9 ? $n - 9 : $n;
            }

            $sum += $n;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
