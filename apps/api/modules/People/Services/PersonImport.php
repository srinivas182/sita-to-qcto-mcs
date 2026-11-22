<?php

declare(strict_types=1);

namespace Modules\People\Services;

use Illuminate\Validation\ValidationException;
use Modules\Access\Catalogue;
use Modules\Access\Services\AccessService;
use Modules\Access\Services\InvitationService;
use Modules\Identity\Models\User;
use Modules\Identity\Support\PhoneNumber;
use Modules\Identity\Support\SaIdNumber;
use Modules\Tenancy\Models\Centre;

/**
 * Invites many people from a spreadsheet. Columns: first_name, last_name, email,
 * phone, role, centre, id_number (any order; role by name or key). A dry run reports
 * every row; nothing is sent until the report is accepted and every row is clean.
 */
final class PersonImport
{
    public const MAX_ROWS = 500;

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly AccessService $access,
        private readonly InvitationService $invitations,
    ) {}

    /**
     * @return array{rows: list<array{line: int, name: string, contact: string, role: string|null, centre: string|null, ok: bool, problems: list<string>}>, ready: int, problems: int, sent: int}
     */
    public function run(string $csv, User $actor, bool $send): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($csv)) ?: [], fn (string $l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['csv' => 'Add a header row and at least one person.']);
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            throw ValidationException::withMessages(['csv' => 'Import at most '.self::MAX_ROWS.' people at a time.']);
        }

        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $header = array_map(fn (?string $h) => strtolower(trim((string) $h, " \"\u{FEFF}")), str_getcsv($lines[0], $sep));
        foreach (['first_name', 'last_name', 'role'] as $needed) {
            if (! in_array($needed, $header, true)) {
                throw ValidationException::withMessages(['csv' => "The header row needs a {$needed} column."]);
            }
        }

        $roles = [];
        foreach ($this->catalogue->roles() as $key => $r) {
            if ($r['scope'] !== 'platform') {
                $roles[strtolower($key)] = $key;
                $roles[strtolower($r['label'])] = $key;
            }
        }
        $centres = Centre::query()->get(['id', 'name'])->mapWithKeys(fn (Centre $c) => [strtolower($c->name) => $c]);

        $report = [];
        $seen = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            $cells = str_getcsv($line, $sep);
            $get = function (string $col) use ($header, $cells): string {
                $at = array_search($col, $header, true);

                return $at === false ? '' : trim((string) ($cells[$at] ?? ''));
            };
            $problems = [];
            $first = $get('first_name');
            $last = $get('last_name');
            $email = strtolower($get('email'));
            $phoneRaw = $get('phone');
            $phone = $phoneRaw !== '' ? PhoneNumber::normalise($phoneRaw) : null;
            $roleKey = $roles[strtolower($get('role'))] ?? null;
            $centreName = $get('centre');
            $centre = $centreName !== '' ? $centres->get(strtolower($centreName)) : null;
            $idNumber = (string) preg_replace('/\s/', '', $get('id_number'));

            if ($first === '' || $last === '') {
                $problems[] = 'First and last name are needed.';
            }
            if ($email === '' && $phone === null) {
                $problems[] = $phoneRaw !== '' ? 'The mobile number is not valid.' : 'Give an email address or a mobile number.';
            }
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = 'The email address is not valid.';
            }
            if ($roleKey === null) {
                $problems[] = 'Unknown role "'.$get('role').'".';
            } elseif (! $this->access->mayDelegate($actor, $roleKey)) {
                $problems[] = 'You cannot give the '.$this->catalogue->label($roleKey).' role.';
            } elseif ($this->catalogue->scope($roleKey) === 'centre' && $centre === null) {
                $problems[] = $centreName === '' ? 'This role needs a centre.' : 'Unknown centre "'.$centreName.'".';
            }
            if ($idNumber !== '' && SaIdNumber::tryParse($idNumber) === null) {
                $problems[] = 'The ID number is not a valid South African ID (check the 13 digits).';
            }
            foreach (array_filter(['email' => $email, 'phone' => $phone, 'ID number' => $idNumber]) as $what => $value) {
                if (isset($seen[$what.$value])) {
                    $problems[] = "Same {$what} as line {$seen[$what.$value]}.";
                }
                $seen[$what.$value] = $i + 2;
            }

            $report[] = [
                'line' => $i + 2,
                'name' => trim("{$first} {$last}"),
                'contact' => $email !== '' ? $email : (string) $phone,
                'role' => $roleKey !== null ? $this->catalogue->label($roleKey) : null,
                'centre' => $centre?->name,
                'ok' => $problems === [],
                'problems' => $problems,
                'data' => $problems === [] ? ['first_name' => $first, 'last_name' => $last, 'email' => $email !== '' ? $email : null, 'phone' => $phone,
                    'role' => $roleKey, 'centre_id' => $this->catalogue->scope($roleKey) === 'centre' ? $centre?->id : null,
                    'id_number' => $idNumber !== '' ? $idNumber : null] : null,
            ];
        }

        $bad = count(array_filter($report, fn (array $r) => ! $r['ok']));
        $sent = 0;
        if ($send && $bad === 0) {
            foreach ($report as $row) {
                if ($row['data'] !== null) {
                    $this->invitations->invite($actor, $row['data']);
                    $sent++;
                }
            }
        }

        return [
            'rows' => array_map(function (array $r): array {
                unset($r['data']);

                return $r;
            }, $report),
            'ready' => count($report) - $bad,
            'problems' => $bad,
            'sent' => $sent,
        ];
    }
}
