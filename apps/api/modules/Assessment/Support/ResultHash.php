<?php

declare(strict_types=1);

namespace Modules\Assessment\Support;

use Modules\Assessment\Models\Result;

/** The fingerprint of an assessment decision, so a quiet change to a stored result shows. */
final class ResultHash
{
    public static function of(Result $r): string
    {
        $criteria = collect($r->criteria)->sortBy('key')->map(fn (array $c) => $c['key'].':'.($c['met'] ? '1' : '0').':'.trim((string) ($c['comment'] ?? '')))->implode(';');
        $files = collect($r->file_ids)->map(fn ($f) => strtolower((string) $f))->sort()->implode(',');

        return hash('sha256', implode('|', ['r1', $r->enrolment_id, $r->module_id, $r->instrument_id, $r->attempt, $r->assessor_id, $r->outcome, $r->score ?? '',
            $criteria, $files, trim((string) $r->feedback), trim((string) $r->remediation), $r->assessed_on->toDateString(), $r->decided_at->utc()->format('Y-m-d\TH:i:s\Z'),
            $r->replaces_id ?? '']));
    }

    public static function intact(Result $r): bool
    {
        return hash_equals($r->hash, self::of($r));
    }
}
