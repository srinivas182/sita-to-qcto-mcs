<?php

declare(strict_types=1);

namespace Modules\Enrolment\Qcto;

use Carbon\CarbonImmutable;
use Modules\Identity\Support\SaIdNumber;
use Modules\Support\Qcto\QctoCodes;

/**
 * The zero-rejection rules: every value of the Learner Enrolment and Readiness for EISA file
 * checked against the QCTO data load specification (field formats, code lists and the rules
 * between fields) and against the provider's accreditation, before the file leaves. Each
 * finding has a stable code, a severity, a plain message and where to fix it.
 *
 * Rules are versioned: when the QCTO changes the specification, add a version with its date.
 */
final class QctoRules
{
    public const VERSION = 'LEISA-2024.1';

    public const SOURCE = 'QCTO data load specification for the Learner Enrolment and Readiness for EISA file';

    /** Where each column is fixed in the platform. */
    public const FIX = [
        'A' => 'centre', 'B' => 'qualification', 'C' => 'identity', 'D' => 'identity', 'E' => 'identity', 'F' => 'profile', 'G' => 'profile', 'H' => 'profile',
        'I' => 'profile', 'J' => 'profile', 'K' => 'profile', 'L' => 'profile', 'M' => 'profile', 'N' => 'profile', 'O' => 'identity', 'P' => 'identity',
        'Q' => 'profile', 'R' => 'profile', 'S' => 'identity', 'T' => 'profile', 'U' => 'profile', 'V' => 'profile', 'W' => 'profile', 'X' => 'profile',
        'Y' => 'profile', 'Z' => 'profile', 'AA' => 'profile', 'AB' => 'profile', 'AC' => 'profile', 'AD' => 'profile', 'AE' => 'profile', 'AF' => 'profile',
        'AG' => 'profile', 'AH' => 'consent', 'AI' => 'consent', 'AJ' => 'cohort', 'AK' => 'statement', 'AL' => 'statement', 'AM' => 'eisa', 'AN' => 'statement',
        'AO' => 'eisa', 'AP' => 'eisa', 'AQ' => 'cohort',
    ];

    private const CODES = [
        'E' => ['527', '533', '565', '538', '540', '570'],
        'F' => ['BA', 'BC', 'BI', 'Oth', 'U', 'Wh'],
        'G' => ['U', 'SA', 'SDC', 'NAM', 'BOT', 'ZIM', 'ANG', 'MOZ', 'LES', 'SWA', 'MAL', 'ZAM', 'MAU', 'TAN', 'SEY', 'ZAI', 'ROA', 'EUR', 'AIS', 'NOR', 'SOU', 'AUS', 'OOC', 'NOT'],
        'H' => ['Eng', 'Afr', 'Oth', 'SASL', 'Sep', 'Ses', 'Set', 'Swa', 'Tsh', 'Xho', 'Xit', 'Zul', 'Nde'],
        'I' => ['M', 'F'],
        'J' => ['SA', 'O', 'D', 'PR', 'U'],
        'K' => ['01', '02', '03', '04', '06', '07', '08', '09', '10', '97', '98', 'U'],
        'L' => ['N', '01', '02', '03', '04', '05', '06', '07', '09'],
        'M' => ['01', '02', '03', '04', '06', '60', '70', '80'],
        'N' => ['01', '02', '03'],
        'R' => QctoCodes::TITLES,
        'AF' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'N', 'X'],
        'AH' => ['Yes', 'No'],
        'AK' => ['01', '02'],
        'AN' => ['1', '2', '3', '4', '5', '6'],
        'AO' => ['01', '02', '03', '04', '05', '06', '07', '08'],
    ];

    /**
     * @param  array<string, string>  $c  the row's columns
     * @param  array{intake_on: string, planned_end_on: string, centre_expires_on: string|null, in_scope: bool|null, scope_expires_on: string|null, eisa_on: string|null}  $ctx
     * @param  list<string>  $missing  columns already reported as missing (not checked again)
     * @return list<array{code: string, column: string, field: string, severity: string, problem: string, fix: string}>
     */
    public static function check(array $c, array $ctx, array $missing): array
    {
        $out = [];
        $add = function (string $code, string $col, string $severity, string $problem) use (&$out): void {
            $out[] = ['code' => $code, 'column' => $col, 'field' => QctoCodes::COLUMNS[$col] ?? $col, 'severity' => $severity, 'problem' => $problem, 'fix' => self::FIX[$col] ?? 'profile'];
        };
        $v = fn (string $col): string => (string) ($c[$col] ?? '');
        $present = fn (string $col): bool => $v($col) !== '' && ! in_array($col, $missing, true);

        // Leading spaces anywhere are refused by the QCTO's loader.
        foreach ($c as $col => $value) {
            if ($value !== '' && $value !== ltrim((string) $value)) {
                $add('Q-SPACE', $col, 'error', 'Remove the space at the start.');
            }
        }
        // Code lists.
        foreach (self::CODES as $col => $allowed) {
            if ($present($col) && ! in_array($v($col), $allowed, true)) {
                $add('Q-CODE', $col, 'error', "“{$v($col)}” is not one of the QCTO codes for this field.");
            }
        }
        // Identity.
        if ($present('C')) {
            if (! preg_match('/^\d{13}$/', $v('C'))) {
                $add('Q-ID-FORMAT', 'C', 'error', 'The national ID must be exactly 13 digits.');
            } elseif (SaIdNumber::tryParse($v('C')) === null) {
                $add('Q-ID-CHECK', 'C', 'error', 'The national ID number fails its check digit.');
            }
        }
        if ($v('C') !== '' && $v('D') === '' && $v('E') !== '533') {
            $add('Q-ID-TYPE', 'E', 'error', 'With a national ID and no alternate ID, the type must be 533 (none).');
        }
        if ($v('C') === '' && $v('D') !== '' && $v('E') === '533') {
            $add('Q-ID-TYPE', 'E', 'error', 'An alternate ID needs its document type, not 533.');
        }
        $sa = $v('C') !== '' ? SaIdNumber::tryParse($v('C')) : null;
        if ($sa !== null && $present('I') && ($sa->sexOnIssue() === 'male' ? 'M' : 'F') !== $v('I')) {
            $add('Q-ID-GENDER', 'I', 'error', 'The gender does not match the national ID number.');
        }
        // Disability rating only with a disability.
        $disabled = $v('L') !== '' && $v('L') !== 'N';
        if ($disabled && ! $present('M')) {
            $add('Q-DIS-RATING', 'M', 'error', 'Record the disability rating.');
        }
        if (! $disabled && $v('M') !== '') {
            $add('Q-DIS-RATING', 'M', 'error', 'A disability rating is only given with a disability.');
        }
        // Names.
        foreach (['O' => true, 'P' => true, 'Q' => false] as $col => $required) {
            if ($v($col) !== '' && ! preg_match("/^\\p{L}[\\p{L} '\\-\\^\"‘`]*$/u", $v($col))) {
                $add('Q-NAME', $col, 'error', 'Use letters only (and \' - ^).');
            }
        }
        // Dates.
        foreach (['S', 'AI', 'AJ', 'AL', 'AQ'] as $col) {
            if ($v($col) !== '' && ! self::date($v($col))) {
                $add('Q-DATE', $col, 'error', 'Dates are written YYYYMMDD with no separators.');
            }
        }
        if ($sa !== null && self::date($v('S')) && $sa->dateOfBirth->format('Ymd') !== $v('S')) {
            $add('Q-DOB-ID', 'S', 'error', 'The date of birth does not match the national ID number.');
        }
        if (self::date($v('S')) && CarbonImmutable::createFromFormat('Ymd', $v('S'))?->diffInYears(CarbonImmutable::parse($ctx['intake_on'])) < 15) {
            $add('Q-AGE', 'S', 'warning', 'The learner was younger than 15 at the start of training. Check the date of birth.');
        }
        if (self::date($v('AJ')) && $v('AJ') < CarbonImmutable::parse($ctx['intake_on'])->format('Ymd')) {
            $add('Q-END', 'AJ', 'error', 'Training cannot end before it starts.');
        }
        // Addresses and contact details.
        foreach (['T', 'U', 'V', 'W', 'X', 'Y'] as $col) {
            if ($v($col) !== '' && ! preg_match("/^[\\p{L}\\d \\-#&()\\/\\\\:._',]+$/u", $v($col))) {
                $add('Q-ADDRESS', $col, 'error', 'The address has characters the QCTO does not accept.');
            }
        }
        if ($present('T') && preg_match('/^\d+$/', $v('T'))) {
            $add('Q-ADDRESS', 'T', 'error', 'The first address line cannot be only a number; give the street number and name.');
        }
        foreach (['Z', 'AA'] as $col) {
            if ($present($col) && ! preg_match('/^\d{4}$/', $v($col))) {
                $add('Q-POSTCODE', $col, 'error', 'A postal code is exactly 4 digits.');
            }
        }
        foreach (['AB', 'AC', 'AD'] as $col) {
            if ($v($col) !== '' && ! preg_match('/^\d+$/', $v($col))) {
                $add('Q-PHONE', $col, 'error', 'Phone numbers are digits only.');
            }
        }
        if ($v('AC') !== '' && strlen($v('AC')) !== 10) {
            $add('Q-PHONE', 'AC', 'warning', 'A South African cell number has 10 digits.');
        }
        if ($v('AE') !== '' && ! preg_match('/^[A-Za-z0-9_.<>\-]+@[A-Za-z0-9_.<>\-]+$/', $v('AE'))) {
            $add('Q-EMAIL', 'AE', 'error', 'The email address has characters the QCTO does not accept.');
        }
        if ($present('AG') && $present('AF') && ctype_digit($v('AF')) && $v('AG')[0] !== $v('AF')) {
            $add('Q-AREA', 'AG', 'warning', 'The Stats SA area code does not seem to be in the province given.');
        }
        // POPIA consent.
        if ($v('AH') === 'No' && $v('AI') !== '') {
            $add('Q-POPI', 'AI', 'error', 'Leave the consent date blank when there is no consent.');
        }
        if ($v('AH') === 'Yes' && ! self::date($v('AI'))) {
            $add('Q-POPI', 'AI', 'error', 'Give the date the learner consented.');
        }
        // Statement of Results and readiness.
        if ($v('AK') === '01' && ! self::date($v('AL'))) {
            $add('Q-SOR', 'AL', 'error', 'Give the Statement of Results issue date.');
        }
        if ($v('AK') === '02' && $v('AL') !== '') {
            $add('Q-SOR', 'AL', 'error', 'Leave the issue date blank until the Statement of Results is issued.');
        }
        if ($v('AN') === '1' && ($v('AK') !== '02' || $v('AL') !== '')) {
            $add('Q-READY', 'AN', 'error', 'Readiness type 1 (enrolled) needs SoR status 02 and no issue date.');
        }
        if ($v('AN') !== '' && $v('AN') !== '1' && $v('AK') !== '01') {
            $add('Q-READY', 'AN', 'error', 'This readiness type needs an issued Statement of Results.');
        }
        if ($ctx['eisa_on'] !== null && self::date($v('AL')) && $v('AL') > str_replace('-', '', $ctx['eisa_on'])) {
            $add('Q-SOR-EISA', 'AL', 'error', 'The Statement of Results must be issued before the EISA date.');
        }
        if ($v('AK') === '01') { // the readiness submission: AM, AO and AP are needed
            if ($v('AM') === '') {
                $add('Q-AC', 'AM', 'error', 'Choose the assessment centre where the learner will write the EISA.');
            }
            if ($v('AO') === '') {
                $add('Q-FLC', 'AO', 'error', 'Record the learner\'s foundational learning (FLC) result.');
            }
            if ($v('AP') === '' && ! in_array($v('AO'), ['04', '05', '06', '07'], true)) {
                $add('Q-FLC', 'AP', 'error', 'Record the FLC, school or N3 certificate number (or RPL and the date).');
            }
        }
        if ($v('AP') !== '' && $v('AO') === '02' && ! preg_match('/^RPL\d{8}$/', $v('AP'))) {
            $add('Q-FLC', 'AP', 'error', 'For RPL, write RPL followed by the assessment date (RPLYYYYMMDD).');
        }
        // Accreditation.
        if ($ctx['in_scope'] === false) {
            $add('Q-ACC-SCOPE', 'B', 'error', 'The centre is not accredited for this qualification.');
        } elseif ($ctx['in_scope'] === null) {
            $add('Q-ACC-SCOPE', 'B', 'warning', 'Record the centre\'s accreditation scope so it can be checked.');
        }
        $ends = $ctx['scope_expires_on'] ?? $ctx['centre_expires_on'];
        if ($ends !== null && $ends < $ctx['intake_on']) {
            $add('Q-ACC-EXPIRED', 'A', 'error', 'The accreditation had ended before training started.');
        } elseif ($ends !== null && $ends < $ctx['planned_end_on']) {
            $add('Q-ACC-ENDS', 'A', 'warning', 'The accreditation ends before training is due to end. Renew it in time.');
        }

        return $out;
    }

    private static function date(string $v): bool
    {
        if (! preg_match('/^\d{8}$/', $v)) {
            return false;
        }
        $d = CarbonImmutable::createFromFormat('!Ymd', $v);

        return $d !== null && $d->format('Ymd') === $v;
    }
}
