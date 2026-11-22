<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Context\TenantContext;

/**
 * Gap-free sequential numbers per series (SARS requires consecutive tax invoice
 * numbers). The row lock is held until the caller's transaction commits, so a number
 * is used only if the document is saved.
 */
final class DocumentNumbers
{
    public function __construct(private readonly TenantContext $context) {}

    /** Call inside a transaction. */
    public function next(string $series): string
    {
        $number = $this->context->runAsPlatform(function () use ($series): int {
            DB::statement(
                'INSERT INTO document_sequences (series, next_number, created_at, updated_at) VALUES (?, 1, now(), now()) ON CONFLICT (series) DO NOTHING',
                [$series],
            );
            $row = DB::selectOne('SELECT next_number FROM document_sequences WHERE series = ? FOR UPDATE', [$series]);
            $number = (int) $row->next_number;
            DB::update('UPDATE document_sequences SET next_number = next_number + 1, updated_at = now() WHERE series = ?', [$series]);

            return $number;
        });

        return sprintf('%s-%06d', $series, $number);
    }
}
