<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Domains\DomainService;
use Modules\Tenancy\Http\Resources\DomainResource;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDomain;

/**
 * Brand & domain screen. Used by TP owners (tenant context, own provider only) and
 * by Super Admins under /v1/admin (platform context, any provider).
 */
final class DomainController
{
    public function index(string $tenant): JsonResponse
    {
        $owner = $this->tenant($tenant);

        return response()->json(['data' => DomainResource::collection(
            TenantDomain::query()->where('tenant_id', $owner->id)->orderByDesc('is_primary')->orderBy('hostname')->get(),
        )]);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        $owner = $this->tenant($tenant);
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253']]);

        return (new DomainResource($this->domains()->addCustomDomain($owner, $data['hostname'])))->response()->setStatusCode(201);
    }

    /** Live DNS + certificate check (the "Check now" button). */
    public function verify(string $tenant, string $domain): JsonResponse
    {
        $record = $this->domain($tenant, $domain);
        $check = $this->domains()->check($record);

        return response()->json(['check' => $check, 'domain' => new DomainResource($record)]);
    }

    public function status(string $tenant, string $domain): DomainResource
    {
        return new DomainResource($this->domain($tenant, $domain));
    }

    public function primary(string $tenant, string $domain): DomainResource
    {
        $record = $this->domain($tenant, $domain);
        $this->domains()->makePrimary($record);

        return new DomainResource($record->refresh());
    }

    public function destroy(string $tenant, string $domain): Response
    {
        $this->domains()->remove($this->domain($tenant, $domain));

        return response()->noContent();
    }

    private function tenant(string $id): Tenant
    {
        // Tenant context: only its own id resolves (and RLS agrees). Platform: any.
        if (! $this->context()->isPlatform()) {
            abort_unless($this->context()->tenantId() === $id, 404);
            Gate::authorize('tenant.settings.manage');
        }

        return Tenant::query()->findOrFail($id);
    }

    private function domain(string $tenant, string $domain): TenantDomain
    {
        $owner = $this->tenant($tenant);

        return TenantDomain::query()->where('tenant_id', $owner->id)->findOrFail($domain);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function domains(): DomainService
    {
        return app(DomainService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
