<?php

declare(strict_types=1);

namespace Modules\Qualifications\Support;

/**
 * Reads modules pasted from a QCTO curriculum document or a spreadsheet.
 *
 * Accepts CSV/TSV with a header row (kind, code, title, nqf_level, credits, group,
 * workplace_criteria) or loose lines such as
 *   "641201001-KM-01  Industry contexts  NQF 4  8"
 *   "119073 | Build masonry superstructures using solid units | 1 | 12 | elective"
 * The kind comes from the code (…-KM-01, -PM-, -WM-); a 4–6 digit code is a unit standard.
 * Nothing is saved here: the result is a preview with an error per bad line.
 */
final class ModuleImport
{
    private const OCCUPATIONAL_CODE = '/\b(\d{6,9}-(KM|PM|WM)-\d{2})\b/i';

    private const UNIT_STANDARD_CODE = '/^\s*(\d{4,6})\b/';

    private const GROUPS = ['compulsory', 'elective', 'fundamental', 'core'];

    /**
     * @return array{rows: list<array{kind: string, group: string, code: string, title: string, nqf_level: int|null, credits: int, workplace_criteria: string|null, line: int}>, errors: list<array{line: int, text: string, message: string}>}
     */
    public static function parse(string $text, bool $legacy): array
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];
        $rows = [];
        $errors = [];
        $header = null;

        foreach ($lines as $i => $raw) {
            $line = $i + 1;
            // Keep leading tabs: an empty first cell is still a column.
            $raw = rtrim($raw, "\r\n ");
            if (trim($raw) === '') {
                continue;
            }

            $cells = self::split($raw);
            $raw = trim($raw);
            if ($header === null && $i === 0 && in_array('code', array_map(fn (string $c) => strtolower(trim($c)), $cells), true)) {
                $header = array_map(fn (string $c) => strtolower(trim($c)), $cells);

                continue;
            }

            $row = $header !== null ? self::fromHeader($header, $cells) : self::loose($raw, $cells);
            $problem = self::problem($row, $legacy);
            if ($problem !== null) {
                $errors[] = ['line' => $line, 'text' => mb_substr($raw, 0, 200), 'message' => $problem];

                continue;
            }

            /** @var array{kind: string, group: string, code: string, title: string, nqf_level: int|null, credits: int, workplace_criteria: string|null} $row */
            $rows[] = $row + ['line' => $line];
        }

        // The same code twice is a copy-paste slip.
        $seen = [];
        foreach ($rows as $k => $row) {
            if (isset($seen[$row['code']])) {
                $errors[] = ['line' => $row['line'], 'text' => $row['code'], 'message' => "Code {$row['code']} is already on line {$seen[$row['code']]}."];
                unset($rows[$k]);

                continue;
            }
            $seen[$row['code']] = $row['line'];
        }

        return ['rows' => array_values($rows), 'errors' => $errors];
    }

    /** @return list<string> */
    private static function split(string $raw): array
    {
        foreach (["\t", '|', ';'] as $sep) {
            if (str_contains($raw, $sep)) {
                return array_map(fn (string $c): string => trim($c), explode($sep, $raw));
            }
        }

        return str_contains($raw, ',') ? array_map(fn (?string $c): string => trim((string) $c), str_getcsv($raw)) : [$raw];
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    private static function fromHeader(array $header, array $cells): array
    {
        $get = function (string ...$names) use ($header, $cells): ?string {
            foreach ($names as $name) {
                $at = array_search($name, $header, true);
                if ($at !== false && isset($cells[$at]) && $cells[$at] !== '') {
                    return $cells[$at];
                }
            }

            return null;
        };
        $code = strtoupper((string) $get('code'));

        return [
            'kind' => strtoupper((string) ($get('kind', 'type') ?? self::kindOf($code))),
            'group' => strtolower((string) ($get('group', 'category') ?? '')),
            'code' => $code,
            'title' => (string) $get('title', 'name'),
            'nqf_level' => self::int($get('nqf_level', 'nqf', 'level')),
            'credits' => self::int($get('credits')),
            'workplace_criteria' => $get('workplace_criteria', 'criteria'),
        ];
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    private static function loose(string $raw, array $cells): array
    {
        $code = null;
        if (preg_match(self::OCCUPATIONAL_CODE, $raw, $m) === 1) {
            $code = strtoupper($m[1]);
        } elseif (preg_match(self::UNIT_STANDARD_CODE, $raw, $m) === 1) {
            $code = $m[1];
        }

        $group = null;
        foreach (self::GROUPS as $g) {
            if (preg_match('/\b'.$g.'\b/i', $raw) === 1) {
                $group = $g;
            }
        }

        // NQF level: "NQF 4", "NQF Level 04", or the second-last number in a cell list.
        $nqf = preg_match('/NQF(?:\s*Level)?\s*0?(\d{1,2})/i', $raw, $m) === 1 ? (int) $m[1] : null;
        $rest = $code !== null ? trim(str_ireplace($code, '', $raw)) : $raw;
        $rest = (string) preg_replace('/NQF(?:\s*Level)?\s*0?\d{1,2}/i', '', $rest);
        preg_match_all('/(?<![\w-])(\d{1,3})(?![\w-])/', $rest, $nums);
        $numbers = array_map('intval', $nums[1]);
        $credits = $numbers !== [] ? (int) end($numbers) : null;
        if ($nqf === null && count($numbers) >= 2) {
            $nqf = $numbers[count($numbers) - 2];
        }

        $title = count($cells) > 1 ? self::titleCell($cells, $code) : trim((string) preg_replace('/\s{2,}.*$/', '', $rest));
        if (count($cells) === 1) {
            // "CODE  Title  NQF 4  8": the title is the text before the numbers.
            $title = trim((string) preg_replace('/(\b(compulsory|elective|fundamental|core)\b|\d+|\s)+$/i', '', $rest));
        }

        return [
            'kind' => $code !== null ? self::kindOf($code) : '',
            'group' => $group ?? '',
            'code' => (string) $code,
            'title' => $title,
            'nqf_level' => $nqf,
            'credits' => $credits,
            'workplace_criteria' => null,
        ];
    }

    /** @param  list<string>  $cells */
    private static function titleCell(array $cells, ?string $code): string
    {
        foreach ($cells as $cell) {
            if ($code !== null) {
                $cell = trim(str_ireplace($code, '', $cell));
            }
            if ($cell !== '' && preg_match('/[A-Za-z]{3,}/', $cell) === 1
                && preg_match('/^(NQF|compulsory|elective|fundamental|core)\b/i', $cell) !== 1) {
                return $cell;
            }
        }

        return '';
    }

    private static function kindOf(string $code): string
    {
        if (preg_match('/-(KM|PM|WM)-/i', $code, $m) === 1) {
            return strtoupper($m[1]);
        }

        return preg_match('/^\d{4,6}$/', $code) === 1 ? 'US' : '';
    }

    private static function int(?string $value): ?int
    {
        if ($value === null || preg_match('/(\d+)/', $value, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /** @param  array<string, mixed>  $row */
    private static function problem(array &$row, bool $legacy): ?string
    {
        if ($row['code'] === '') {
            return $legacy ? 'No unit standard ID found (4–6 digits at the start).' : 'No module code found (like 641201001-KM-01).';
        }
        if (! in_array($row['kind'], ['KM', 'PM', 'WM', 'US'], true)) {
            return 'Kind must be KM, PM, WM or US.';
        }
        if ($row['kind'] !== self::kindOf($row['code']) && self::kindOf($row['code']) !== '') {
            return "The code says {$row['code']} but the kind says {$row['kind']}.";
        }
        if ($legacy !== ($row['kind'] === 'US')) {
            return $legacy ? 'Legacy qualifications are made of unit standards.' : 'Occupational qualifications use KM, PM and WM modules.';
        }
        if (mb_strlen(trim((string) $row['title'])) < 3) {
            return 'The title is missing.';
        }
        if (! is_int($row['credits']) || $row['credits'] < 1 || $row['credits'] > 500) {
            return 'Credits are missing or not a number.';
        }
        if ($row['nqf_level'] !== null && ($row['nqf_level'] < 1 || $row['nqf_level'] > 10)) {
            return 'NQF level must be 1–10.';
        }
        $row['group'] = in_array($row['group'], self::GROUPS, true) ? $row['group'] : ($legacy ? 'core' : 'compulsory');
        if ($legacy && $row['group'] === 'compulsory') {
            $row['group'] = 'core';
        }
        if (! $legacy && in_array($row['group'], ['fundamental', 'core'], true)) {
            $row['group'] = 'compulsory';
        }
        $row['title'] = mb_substr(trim((string) $row['title']), 0, 400);

        return null;
    }
}
