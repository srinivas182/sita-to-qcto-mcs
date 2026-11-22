<?php

declare(strict_types=1);

namespace Modules\Billing\Support;

/** Rand amounts held as integer cents. */
final class Money
{
    /** VAT on an amount, rounded half up to the cent. */
    public static function vat(int $cents, float $rate): int
    {
        return (int) round($cents * $rate / 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function format(int $cents): string
    {
        $negative = $cents < 0;
        $value = number_format(abs($cents) / 100, 2, '.', ' ');

        return ($negative ? '-R ' : 'R ').$value;
    }

    /** "123.45" as gateways that take decimal strings expect. */
    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
