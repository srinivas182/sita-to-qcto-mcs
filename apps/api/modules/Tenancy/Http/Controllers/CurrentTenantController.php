<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Http\Resources\TenantResource;

final class CurrentTenantController
{
    /**
     * Public branding for the sign-in page (logo, colours, name). Served before sign-in
     * and cacheable at the edge.
     */
    public function branding(): JsonResponse
    {
        $tenant = $this->context()->tenant();

        if ($tenant === null) {
            return response()->json(['name' => config('app.name'), 'platform' => true, 'branding' => (object) []]);
        }

        return response()->json([
            'name' => $tenant->trading_name,
            'code' => $tenant->code,
            'platform' => false,
            'status' => $tenant->status->value,
            'branding' => $tenant->branding ?? (object) [],
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
    }

    public function show(): TenantResource
    {
        $tenant = $this->context()->tenant() ?? abort(404);

        return new TenantResource($tenant->load('domains'));
    }

    public function updateBranding(Request $request): TenantResource
    {
        Gate::authorize('tenant.settings.manage');
        $tenant = $this->context()->tenant() ?? abort(404);

        $data = $request->validate([
            'trading_name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'branding' => ['sometimes', 'array'],
            'branding.logo_url' => ['nullable', 'url:https', 'max:500'],
            'branding.primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'branding.tagline' => ['nullable', 'string', 'max:120'],
        ]);

        if (isset($data['trading_name'])) {
            $tenant->trading_name = $data['trading_name'];
        }

        if (isset($data['branding'])) {
            $tenant->branding = array_filter([...($tenant->branding ?? []), ...$data['branding']], fn ($v) => $v !== null);
        }

        $tenant->save();

        return new TenantResource($tenant->load('domains'));
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
