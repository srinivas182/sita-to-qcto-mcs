<?php

declare(strict_types=1);

namespace Modules\Audit;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Tenancy\Context\TenantContext;

/**
 * Writes audit entries. The tenant of an entry is, in order: the explicit tenant,
 * the subject's own tenant, then the current context. RLS rejects an entry for a
 * tenant other than the one being acted for, outside the platform context.
 */
final class AuditLogger
{
    private bool $enabled = true;

    /** @var array<string, mixed> meta added to every entry of this request */
    private array $shared = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $meta
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        array $meta = [],
        ?string $tenantId = null,
    ): ?AuditLog {
        if (! $this->enabled) {
            return null;
        }

        $request = app()->bound('request') ? app('request') : null;
        $request = $request instanceof Request && ! app()->runningInConsole() ? $request : null;

        $actorId = $this->auth->guard()->id();

        $entry = new AuditLog;
        $entry->fill([
            'tenant_id' => $tenantId ?? $this->tenantOf($subject) ?? $this->context->tenantId(),
            'actor_id' => is_string($actorId) ? $actorId : null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'changes' => $changes === [] ? null : $changes,
            'meta' => array_filter([
                'request_id' => $request?->headers->get('X-Request-Id'),
                'platform' => $this->context->isPlatform() ?: null,
                ...$this->shared,
                ...$meta,
            ], static fn (mixed $v): bool => $v !== null) ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 500, '') : null,
        ]);
        // Platform-level entries (no tenant) are written by trusted platform code even
        // when the request itself has no context, e.g. a Super Admin signing in.
        if ($entry->tenant_id === null && ! $this->context->isPlatform()) {
            $this->context->runAsPlatform(fn () => $entry->save());
        } else {
            $entry->save();
        }

        return $entry;
    }

    /** @param  array<string, mixed>  $meta */
    public function share(array $meta): void
    {
        $this->shared = [...$this->shared, ...$meta];
    }

    /**
     * Run a callback without writing audit entries (bulk imports, seeding).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutAuditing(callable $callback): mixed
    {
        $previous = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $previous;
        }
    }

    private function tenantOf(?Model $subject): ?string
    {
        if ($subject !== null && method_exists($subject, 'auditTenantId')) {
            $id = $subject->auditTenantId();

            return is_string($id) ? $id : null;
        }

        return null;
    }
}
