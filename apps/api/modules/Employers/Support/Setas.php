<?php

declare(strict_types=1);

namespace Modules\Employers\Support;

/** The 21 Sector Education and Training Authorities, and SA employer identifiers. */
final class Setas
{
    public const ALL = [
        'AgriSETA', 'BANKSETA', 'CATHSSETA', 'CETA', 'CHIETA', 'ETDP SETA', 'EWSETA', 'FASSET', 'FoodBev SETA', 'FP&M SETA',
        'HWSETA', 'INSETA', 'LGSETA', 'MERSETA', 'MICT SETA', 'MQA', 'PSETA', 'SASSETA', 'Services SETA', 'TETA', 'W&RSETA',
    ];

    public const BBBEE = ['1', '2', '3', '4', '5', '6', '7', '8', 'non_compliant', 'eme', 'qse'];

    /** Skills Development Levy reference: L followed by 9 digits (as on SARS EMP201). */
    public static function sdl(?string $value): bool
    {
        return $value === null || preg_match('/^L\d{9}$/', $value) === 1;
    }
}
