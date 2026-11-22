<?php

declare(strict_types=1);

namespace Modules\Access\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Models\SupportAccessGrant;
use Modules\Access\Services\SupportAccessService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Models\Tenant;

final class SupportAccessController
{
    /** Provider: requests and windows for this provider. */
    public function index(): JsonResponse
    {
        Gate::authorize('tenant.support.approve');

        return response()->json(['data' => SupportAccessGrant::query()->latest()->limit(100)->get()->map(fn ($g) => $this->present($g))]);
    }

    public function approve(Request $request, string $grant): JsonResponse
    {
        Gate::authorize('tenant.support.approve');

        return response()->json($this->present($this->support()->approve($this->grant($grant), $this->user($request))));
    }

    public function deny(Request $request, string $grant): JsonResponse
    {
        Gate::authorize('tenant.support.approve');

        return response()->json($this->present($this->support()->deny($this->grant($grant), $this->user($request))));
    }

    public function revoke(string $grant): JsonResponse
    {
        Gate::authorize('tenant.support.approve');

        return response()->json($this->present($this->support()->revoke($this->grant($grant))));
    }

    /** Platform: ask a provider for access. */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'minutes' => ['required', 'integer'],
        ]);

        $tenant = Tenant::query()->findOrFail((string) $data['tenant_id']);

        return response()->json($this->present($this->support()->request($this->user($request), $tenant, $data['reason'], (int) $data['minutes'])), 201);
    }

    /** Platform: the caller's own requests across providers. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => SupportAccessGrant::query()->where('granted_to', $this->user($request)->id)
            ->latest()->limit(100)->get()->map(fn ($g) => $this->present($g))]);
    }

    private function grant(string $id): SupportAccessGrant
    {
        return SupportAccessGrant::query()->findOrFail($id);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /** @return array<string, mixed> */
    private function present(SupportAccessGrant $g): array
    {
        return [
            'id' => $g->id,
            'tenant_id' => $g->tenant_id,
            'granted_to' => $g->granted_to,
            'reason' => $g->reason,
            'minutes' => $g->minutes,
            'status' => $g->isActive() ? 'active' : ($g->status === 'approved' ? 'expired' : $g->status),
            'starts_at' => $g->starts_at?->toIso8601String(),
            'ends_at' => $g->ends_at?->toIso8601String(),
            'created_at' => $g->created_at?->toIso8601String(),
        ];
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function support(): SupportAccessService
    {
        return app(SupportAccessService::class);
    }
}
