<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

/**
 * Normalises phone numbers to E.164. South African local formats (082…, 27…) are
 * understood; other countries must be given with their + prefix.
 */
final class PhoneNumber
{
    public static function normalise(string $input): ?string
    {
        $raw = trim($input);
        $plus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = (string) preg_replace('/\D/', '', $raw);

        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $plus) {
            if (strlen($digits) === 10 && $digits[0] === '0') {
                $digits = '27'.substr($digits, 1);
            } elseif (! (strlen($digits) === 11 && str_starts_with($digits, '27'))) {
                return null;
            }
        }

        if (str_starts_with($digits, '27')) {
            // SA mobile and landline numbers: 27 + 9 digits, first digit 1–8.
            return preg_match('/^27[1-8]\d{8}$/', $digits) ? '+'.$digits : null;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $digits) ? '+'.$digits : null;
    }

    /** "+27 82 *** **67" for display in messages and screens. */
    public static function mask(string $e164): string
    {
        return substr($e164, 0, 5).str_repeat('•', max(0, strlen($e164) - 7)).substr($e164, -2);
    }
}
