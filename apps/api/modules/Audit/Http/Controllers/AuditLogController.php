<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Models\AuditLog;
use Modules\Tenancy\Context\TenantContext;

/**
 * Filterable audit trail. In a provider it shows that provider's entries (RLS); on
 * the platform it shows everything and can filter by provider.
 */
final class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        if (! $this->context()->isPlatform()) {
            Gate::authorize('tenant.audit.view');
        }

        $data = $request->validate([
            'tenant_id' => ['nullable', 'string', 'size:26'],
            'actor_id' => ['nullable', 'string', 'size:26'],
            'action' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:150'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // Bounded by time so each query hits only a few monthly partitions.
        $from = isset($data['from']) ? Carbon::parse($data['from']) : now()->subDays(30);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();

        $query = AuditLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        foreach (['tenant_id', 'actor_id', 'subject_type', 'subject_id'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        if (! empty($data['action'])) {
            $query->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $data['action']).'%');
        }

        $page = $query->cursorPaginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'tenant_id' => $log->tenant_id,
                'actor_id' => $log->actor_id,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'changes' => $log->changes,
                'meta' => $log->meta,
                'ip' => $log->ip,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
