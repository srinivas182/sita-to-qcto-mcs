<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Enrolment\Models\QctoOutcome;
use Modules\Enrolment\Models\QctoSubmission;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * The QCTO's reply to a submission, loaded back onto learners. The reply is usually the file
 * we sent with a status or comment column added; we match rows by national ID (or alternate
 * ID and last name) and read the first column whose heading speaks of an error, reason,
 * comment or status. Learners can also be marked by hand when the QCTO replies in an email.
 * The uploaded file itself is not kept (it holds ID numbers); only the outcomes are.
 */
final class QctoResponse
{
    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{accepted: int, rejected: int, unmatched: int}
     */
    public function load(QctoSubmission $s, User $by, ?UploadedFile $file, array $input): array
    {
        $rows = [];
        $unmatched = [];
        $learners = $this->context->runAsPlatform(fn () => User::query()->whereIn('id', $s->learner_ids)->get(['id', 'id_number', 'last_name']));
        if ($file !== null) {
            foreach ($this->read($file) as $r) {
                $id = (string) preg_replace('/\D/', '', (string) ($r['national id'] ?? ''));
                $alt = strtolower(trim((string) ($r['learner alternate id'] ?? '')));
                $last = strtolower(trim((string) ($r['learner last name'] ?? '')));
                $u = $learners->first(fn (User $u) => ($id !== '' && (string) $u->id_number === $id)
                    || ($alt !== '' && strtolower((string) $u->id_number) === $alt && strtolower((string) $u->last_name) === $last));
                $reason = trim((string) ($r['__reason'] ?? ''));
                if ($u === null) {
                    $unmatched[] = ['id' => $id !== '' ? substr($id, 0, 6).'*******' : ($alt !== '' ? '(alternate ID)' : ''), 'reason' => $reason];

                    continue;
                }
                $rows[$u->id] = ['status' => self::rejected($reason) ? 'rejected' : 'accepted', 'reason' => self::rejected($reason) ? $reason : null];
            }
        } else {
            $data = Validator::make($input, [
                'outcomes' => ['required', 'array', 'min:1'],
                'outcomes.*.user_id' => ['required', 'ulid'],
                'outcomes.*.status' => ['required', 'in:accepted,rejected'],
                'outcomes.*.reason' => ['required_if:outcomes.*.status,rejected', 'nullable', 'string', 'max:1000'],
            ], ['outcomes.*.reason.required_if' => 'Give the QCTO\'s reason for each rejected learner.'])->validate();
            foreach ((array) $data['outcomes'] as $o) {
                if (! in_array($o['user_id'], $s->learner_ids, true)) {
                    throw ValidationException::withMessages(['outcomes' => 'Only learners in this submission can be marked.']);
                }
                $rows[$o['user_id']] = ['status' => $o['status'], 'reason' => $o['reason'] ?? null];
            }
        }
        if ($rows === [] && $unmatched === []) {
            throw ValidationException::withMessages(['file' => 'No learners were found in the file.']);
        }
        DB::transaction(function () use ($s, $by, $rows, $unmatched, $file): void {
            foreach ($rows as $userId => $r) {
                QctoOutcome::query()->updateOrCreate(['submission_id' => $s->id, 'user_id' => $userId], $r + ['loaded_by' => $by->id]);
            }
            $s->forceFill(['response_loaded_at' => now(), 'response_unmatched' => $file !== null ? $unmatched : $s->response_unmatched])->save();
        });
        $counts = ['accepted' => count(array_filter($rows, fn ($r) => $r['status'] === 'accepted')), 'rejected' => count(array_filter($rows, fn ($r) => $r['status'] === 'rejected')),
            'unmatched' => count($unmatched)];
        $this->audit->record('qcto.response_loaded', $s, [], $counts + ['from' => $file !== null ? 'file' : 'hand']);

        return $counts;
    }

    /** A reply's comment that means the row was not accepted. */
    public static function rejected(string $reason): bool
    {
        $r = strtolower($reason);
        if ($r === '' || preg_match('/^(ok|okay|accepted|success(ful)?|loaded|valid|pass(ed)?|no errors?|none|-)$/', $r)) {
            return false;
        }

        return true;
    }

    /**
     * Rows keyed by lower-case heading; the reason column under "__reason".
     *
     * @return list<array<string, string>>
     */
    private function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Load the QCTO\'s reply as an Excel (.xlsx) or CSV file.']);
        }
        $reader = $ext === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($file->getRealPath());
        $out = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $head = null;
                $reasonCol = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($v) => trim($v instanceof \DateTimeInterface ? $v->format('Ymd') : (is_scalar($v) ? (string) $v : '')), $row->toArray());
                    if ($head === null) {
                        if (! in_array('national id', array_map('strtolower', $cells), true)) {
                            continue; // look for the heading row
                        }
                        $head = array_map('strtolower', $cells);
                        foreach ($head as $i => $h) {
                            if ($reasonCol === null && preg_match('/error|reason|comment|status|outcome|result/', $h) && ! str_contains($h, 'statement of results') && ! str_contains($h, 'citizen')
                                && ! str_contains($h, 'socioeconomic') && ! str_contains($h, 'disability') && ! str_contains($h, 'immigrant')) {
                                $reasonCol = $i;
                            }
                        }

                        continue;
                    }
                    if (array_filter($cells) === []) {
                        continue;
                    }
                    $assoc = [];
                    foreach ($head as $i => $h) {
                        $assoc[$h] = $cells[$i] ?? '';
                    }
                    $assoc['__reason'] = $reasonCol !== null ? ($cells[$reasonCol] ?? '') : '';
                    $out[] = $assoc;
                }
                if ($head !== null) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
        if ($out === []) {
            throw ValidationException::withMessages(['file' => 'The file has no learner rows under a “National Id” heading.']);
        }

        return $out;
    }
}
