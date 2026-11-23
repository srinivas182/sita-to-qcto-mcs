<?php

declare(strict_types=1);

namespace Modules\Enrolment\Support;

/** The documents a provider can ask applicants for, with their default rules. */
final class DocumentTypes
{
    /** @var array<string, array{label: string, certified: bool, max_age_days: int|null}> */
    public const ALL = [
        'id_document' => ['label' => 'ID document or passport', 'certified' => true, 'max_age_days' => 90],
        'highest_qualification' => ['label' => 'Highest school or other qualification', 'certified' => true, 'max_age_days' => 90],
        'proof_of_address' => ['label' => 'Proof of address', 'certified' => false, 'max_age_days' => 90],
        'birth_certificate' => ['label' => 'Birth certificate (learners under 18)', 'certified' => true, 'max_age_days' => 90],
        'guardian_id' => ['label' => "Parent or guardian's ID (learners under 18)", 'certified' => true, 'max_age_days' => 90],
        'cv' => ['label' => 'CV', 'certified' => false, 'max_age_days' => null],
        'other' => ['label' => 'Other', 'certified' => false, 'max_age_days' => null],
    ];

    public static function label(string $type): string
    {
        return self::ALL[$type]['label'] ?? $type;
    }
}
