<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

/** The milestones a tranche can wait for. All but `evidence` are worked out from the platform's own records. */
final class Milestones
{
    public const LIST = [
        'enrolled' => 'Learner enrolled with a signed learner agreement',
        'qcto_submitted' => 'Learner on a QCTO enrolment submission, not rejected',
        'registers' => 'Signed monthly attendance registers',
        'logbook' => 'Workplace logbook signed off and checked for every compulsory work module',
        'sor' => 'Statement of Results issued',
        'eisa' => 'Registered for the EISA',
    ];

    /**
     * Plain words for one milestone with its parameters.
     *
     * @param  array{key?: string, months?: int}  $m
     */
    public static function label(array $m): string
    {
        $key = (string) ($m['key'] ?? '');
        if ($key === 'registers') {
            $n = (int) ($m['months'] ?? 1);

            return $n === 1 ? '1 signed monthly attendance register' : "{$n} signed monthly attendance registers";
        }

        return self::LIST[$key] ?? $key;
    }
}
