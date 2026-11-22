<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Identity\Models\User;
use Modules\Tenancy\Cells\CellPlacement;
use Modules\Tenancy\Enums\TenantStatus;
use Modules\Tenancy\Http\Resources\TenantResource;
use Modules\Tenancy\Models\Cell;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantService;

/** Super Admin: training providers. Runs in the platform context. */
final class TenantAdminController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
            'cell' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::query()->withCount('centres')->with('domains')->latest();

        if (! empty($data['search'])) {
            $term = '%'.mb_strtolower($data['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(trading_name) like ?', [$term])
                ->orWhereRaw('lower(legal_name) like ?', [$term])->orWhere('code', 'like', $term));
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['cell'])) {
            $query->where('cell_id', $data['cell']);
        }

        return TenantResource::collection($query->paginate((int) ($data['per_page'] ?? 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'legal_name' => ['required', 'string', 'max:200'],
            'trading_name' => ['nullable', 'string', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:40'],
            'package_code' => ['nullable', 'string', 'max:40'],
            'cell_id' => ['nullable', 'string', 'max:32'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $cell = isset($data['cell_id']) ? Cell::query()->findOrFail((string) $data['cell_id']) : null;

        /** @var array{code: string, legal_name: string, trading_name?: string|null, registration_number?: string|null, package_code?: string|null} $data */
        $tenant = $this->tenants()->register($data, $cell, $actor->id);

        return (new TenantResource($tenant->load('domains')))->response()->setStatusCode(201);
    }

    public function show(string $tenant): TenantResource
    {
        return new TenantResource(Tenant::query()->withCount('centres')->with('domains')->findOrFail($tenant));
    }

    public function update(Request $request, string $tenant): TenantResource
    {
        $record = Tenant::query()->findOrFail($tenant);
        $data = $request->validate([
            'legal_name' => ['sometimes', 'string', 'max:200'],
            'trading_name' => ['sometimes', 'string', 'max:200'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'package_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        $record->fill($data)->save();

        return new TenantResource($record->load('domains'));
    }

    public function verify(string $tenant): TenantResource
    {
        return new TenantResource($this->tenants()->verify(Tenant::query()->findOrFail($tenant)));
    }

    public function approve(string $tenant): TenantResource
    {
        return new TenantResource($this->tenants()->approve(Tenant::query()->findOrFail($tenant)));
    }

    public function suspend(Request $request, string $tenant): TenantResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new TenantResource($this->tenants()->suspend(Tenant::query()->findOrFail($tenant), $data['reason']));
    }

    public function reactivate(string $tenant): TenantResource
    {
        return new TenantResource($this->tenants()->reactivate(Tenant::query()->findOrFail($tenant)));
    }

    public function placeInCell(Request $request, string $tenant): TenantResource
    {
        $data = $request->validate(['cell_id' => ['required', 'string', 'max:32']]);

        return new TenantResource($this->tenants()->placeInCell(Tenant::query()->findOrFail($tenant), Cell::query()->findOrFail((string) $data['cell_id'])));
    }

    public function cells(): JsonResponse
    {
        return response()->json(['data' => $this->placement()->overview()]);
    }

    /** Live counts for the Super Admin overview. */
    public function overview(): JsonResponse
    {
        $byStatus = Tenant::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'tenants' => collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'users' => User::query()->count(),
            'cells' => count($this->placement()->overview()),
        ]);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function tenants(): TenantService
    {
        return app(TenantService::class);
    }

    /** Resolved per request: controllers are cached on routes and must not keep request-scoped services. */
    private function placement(): CellPlacement
    {
        return app(CellPlacement::class);
    }
}
