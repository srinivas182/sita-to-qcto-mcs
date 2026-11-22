<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Onboarding\Support\Identifiers;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;

/** A provider's centres: accredited delivery sites with scope and expiry. */
final class CentreController
{
    public function index(): JsonResponse
    {
        Gate::authorize('centres.view');

        return response()->json(['data' => Centre::query()->orderBy('name')->get()->map(fn (Centre $c) => $c->present())->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('centres.manage');
        $centre = Centre::query()->create($this->validated($request, null));

        return response()->json(['data' => $centre->present()], 201);
    }

    public function update(Request $request, string $centre): JsonResponse
    {
        Gate::authorize('centres.manage');
        $model = Centre::query()->findOrFail($centre);
        $data = $this->validated($request, $model);

        // A renewed accreditation starts its alerts afresh.
        if (array_key_exists('accreditation_expires_on', $data) && (string) $data['accreditation_expires_on'] !== $model->accreditation_expires_on?->toDateString()) {
            $data['alerted_for'] = null;
        }

        $model->fill($data)->save();

        return response()->json(['data' => $model->present()]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Centre $existing): array
    {
        $required = $existing === null ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->tenantId();

        $data = $request->validate([
            'name' => [$required, 'string', 'max:200', Rule::unique('centres', 'name')->where('tenant_id', $tenantId)->ignore($existing?->id)],
            'status' => ['sometimes', 'in:active,inactive'],
            'accreditation_type' => ['sometimes', 'in:sdp,ac'],
            'accreditation_number' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && ! Identifiers::qcto($value)) {
                    $fail('Use the number on the QCTO letter, e.g. 07-QCTO/SDP180825105627.');
                }
            }],
            'accreditation_expires_on' => ['nullable', 'date'],
            'scope' => ['nullable', 'array', 'max:50'],
            'scope.*' => ['string', 'regex:/^\d{4,8}$/'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'latitude' => ['nullable', 'numeric', 'between:-35,-22'],
            'longitude' => ['nullable', 'numeric', 'between:16,33'],
        ], [
            'scope.*.regex' => 'Use SAQA qualification IDs (digits only).',
            'latitude.between' => 'That location is outside South Africa.',
            'longitude.between' => 'That location is outside South Africa.',
        ]);

        if (isset($data['accreditation_number'])) {
            $data['accreditation_number'] = Identifiers::normalise($data['accreditation_number']);
        }

        return $data;
    }
}
