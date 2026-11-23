<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Enrolment\Support\DocumentTypes;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property array<string, array{certified?: bool, max_age_days?: int|null}>|null $document_rules
 * @property int $qcto_days
 * @property bool $qcto_working_days
 * @property int $applicant_retention_months
 * @property bool $identity_auto
 */
final class EnrolmentSettings extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'enrolment_settings';

    protected $fillable = ['document_rules', 'qcto_days', 'qcto_working_days', 'applicant_retention_months', 'identity_auto'];

    protected $attributes = ['qcto_days' => 21, 'qcto_working_days' => false, 'applicant_retention_months' => 12, 'identity_auto' => false];

    protected function casts(): array
    {
        return ['document_rules' => 'array', 'qcto_days' => 'integer', 'qcto_working_days' => 'boolean', 'applicant_retention_months' => 'integer', 'identity_auto' => 'boolean'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['document_rules' => []]);
    }

    /** @return array{label: string, certified: bool, max_age_days: int|null} the provider's rule, else the default */
    public function rule(string $type): array
    {
        $default = DocumentTypes::ALL[$type] ?? DocumentTypes::ALL['other'];
        $own = $this->document_rules[$type] ?? [];

        return ['label' => $default['label'], 'certified' => (bool) ($own['certified'] ?? $default['certified']),
            'max_age_days' => array_key_exists('max_age_days', $own) ? $own['max_age_days'] : $default['max_age_days']];
    }
}
