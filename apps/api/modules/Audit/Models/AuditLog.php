<?php

declare(strict_types=1);

namespace Modules\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One immutable entry in the audit trail.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @param  array<string, mixed>  $options */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Audit log entries are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Audit log entries are immutable.');
    }
}
