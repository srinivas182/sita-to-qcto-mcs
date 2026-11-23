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
 */
final class EnrolmentSettings extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'enrolment_settings';

    protected $fillable = ['document_rules'];

    protected function casts(): array
    {
        return ['document_rules' => 'array'];
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
