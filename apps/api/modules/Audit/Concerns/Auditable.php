<?php

declare(strict_types=1);

namespace Modules\Audit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Audit\AuditLogger;

/**
 * Records created / updated / deleted events of a model in the audit log.
 *
 * Hidden attributes and those listed in `$auditRedact` are replaced by "[redacted]";
 * timestamps are left out. Actions are named "<model>.<event>", e.g. "tenant.updated".
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            /** @var Model&self $model */
            $model->writeAudit('created', $model->auditValues($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            /** @var Model&self $model */
            $changes = [];
            $after = $model->auditValues($model->getChanges());

            foreach ($after as $key => $value) {
                $changes[$key] = ['from' => $model->auditValues([$key => $model->getRawOriginal($key)])[$key] ?? null, 'to' => $value];
            }

            if ($changes !== []) {
                $model->writeAudit('updated', $changes);
            }
        });

        static::deleted(function (Model $model): void {
            /** @var Model&self $model */
            $model->writeAudit('deleted', []);
        });
    }

    public function auditAction(string $event): string
    {
        return Str::snake(class_basename($this)).'.'.$event;
    }

    /** @param  array<string, mixed>  $changes */
    protected function writeAudit(string $event, array $changes): void
    {
        app(AuditLogger::class)->record($this->auditAction($event), $this, $changes);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function auditValues(array $values): array
    {
        $redact = array_merge($this->getHidden(), property_exists($this, 'auditRedact') ? $this->auditRedact : []);
        $skip = [$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()];
        $out = [];

        foreach ($values as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $out[$key] = in_array($key, $redact, true) ? '[redacted]' : $value;
        }

        return $out;
    }
}
