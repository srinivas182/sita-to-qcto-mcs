<?php

declare(strict_types=1);

namespace Modules\Support\Qcto;

/**
 * Codes from the QCTO Learner Enrolment and Readiness for EISA data load specification
 * (columns A to AQ). Our own values are mapped to them here and nowhere else.
 */
final class QctoCodes
{
    public const TITLES = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof'];

    public const EQUITY = ['african' => 'BA', 'coloured' => 'BC', 'indian' => 'BI', 'white' => 'Wh', 'other' => 'Oth'];

    public const NATIONALITY = [
        'SA' => 'South Africa', 'SDC' => 'SADC (other)', 'NAM' => 'Namibia', 'BOT' => 'Botswana', 'ZIM' => 'Zimbabwe', 'ANG' => 'Angola',
        'MOZ' => 'Mozambique', 'LES' => 'Lesotho', 'SWA' => 'Eswatini', 'MAL' => 'Malawi', 'ZAM' => 'Zambia', 'MAU' => 'Mauritius',
        'TAN' => 'Tanzania', 'SEY' => 'Seychelles', 'ZAI' => 'DR Congo', 'ROA' => 'Rest of Africa', 'EUR' => 'Europe', 'AIS' => 'Asia',
        'NOR' => 'North America', 'SOU' => 'South or Central America', 'AUS' => 'Australia and Oceania', 'OOC' => 'Other', 'U' => 'Unknown',
    ];

    public const CITIZEN = ['SA' => 'South African citizen', 'PR' => 'Permanent resident', 'D' => 'Dual citizenship', 'O' => 'Other'];

    public const SOCIOECONOMIC = [
        '01' => 'Employed', '02' => 'Unemployed, looking for work', '03' => 'Not working, not looking', '04' => 'Home-maker (not working)',
        '06' => 'Scholar or student (not working)', '07' => 'Pensioner or retired', '08' => 'Not working: disabled', '09' => 'Not working: no wish to work',
        '10' => 'Not working: other reason', 'U' => 'Unspecified',
    ];

    public const LANGUAGES = [
        'english' => 'Eng', 'afrikaans' => 'Afr', 'sepedi' => 'Sep', 'northern sotho' => 'Sep', 'sesotho' => 'Ses', 'southern sotho' => 'Ses', 'sotho' => 'Ses',
        'setswana' => 'Set', 'tswana' => 'Set', 'siswati' => 'Swa', 'swati' => 'Swa', 'tshivenda' => 'Tsh', 'venda' => 'Tsh', 'isixhosa' => 'Xho', 'xhosa' => 'Xho',
        'xitsonga' => 'Xit', 'tsonga' => 'Xit', 'isizulu' => 'Zul', 'zulu' => 'Zul', 'isindebele' => 'Nde', 'ndebele' => 'Nde',
        'sign language' => 'SASL', 'sasl' => 'SASL', 'south african sign language' => 'SASL',
    ];

    public const PROVINCES = [
        'Western Cape' => '1', 'Eastern Cape' => '2', 'Northern Cape' => '3', 'Free State' => '4', 'KwaZulu-Natal' => '5',
        'North West' => '6', 'Gauteng' => '7', 'Mpumalanga' => '8', 'Limpopo' => '9',
    ];

    /** Column letters and headings of the file, in order (A to AQ). */
    public const COLUMNS = [
        'A' => 'SDP Code', 'B' => 'Qualification Id', 'C' => 'National Id', 'D' => 'Learner Alternate ID', 'E' => 'Alternative Id Type',
        'F' => 'Equity Code', 'G' => 'Nationality Code', 'H' => 'Home Language Code', 'I' => 'Gender Code', 'J' => 'Citizen Resident Status Code',
        'K' => 'Socioeconomic Status Code', 'L' => 'Disability Status Code', 'M' => 'Disability Rating', 'N' => 'Immigrant Status',
        'O' => 'Learner Last Name', 'P' => 'Learner First Name', 'Q' => 'Learner Middle Name', 'R' => 'Learner Title', 'S' => 'Learner Birth Date',
        'T' => 'Learner Home Address 1', 'U' => 'Learner Home Address 2', 'V' => 'Learner Home Address 3', 'W' => 'Learner Postal Address 1',
        'X' => 'Learner Postal Address 2', 'Y' => 'Learner Postal Address 3', 'Z' => 'Learner Home Address Postal Code',
        'AA' => 'Learner Postal Address Post Code', 'AB' => 'Learner Phone Number', 'AC' => 'Learner Cell Phone Number', 'AD' => 'Learner Fax Number',
        'AE' => 'Learner Email Address', 'AF' => 'Province Code', 'AG' => 'STATSSA Area Code', 'AH' => 'POPI Act Agree', 'AI' => 'POPI Act Date',
        'AJ' => 'Expected Training Completion Date', 'AK' => 'Statement of Results Status', 'AL' => 'Statement of Results Issue Date',
        'AM' => 'Assessment Centre Code', 'AN' => 'Learner Readiness for EISA Type Id', 'AO' => 'FLC', 'AP' => 'FLC Statement of Result Number',
        'AQ' => 'Date Stamp',
    ];

    public static function language(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $key = strtolower(trim($name));

        return self::LANGUAGES[$key] ?? (in_array($name, self::LANGUAGES, true) ? $name : 'Oth');
    }
}
