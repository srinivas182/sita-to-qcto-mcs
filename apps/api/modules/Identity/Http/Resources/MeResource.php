<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Access\Catalogue;
use Modules\Access\Services\AccessService;
use Modules\Identity\Mfa\TotpService;
use Modules\Identity\Models\User;
use Modules\Identity\Support\PhoneNumber;
use Modules\Tenancy\Context\TenantContext;

/**
 * The signed-in person in the current place: who they are, where they are, what
 * they may do, and what security steps are outstanding. One call powers the shell.
 *
 * @mixin User
 */
final class MeResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $access = app(AccessService::class);
        $catalogue = app(Catalogue::class);
        $context = app(TenantContext::class);
        $tenant = $context->tenant();

        $assignments = $access->tenantAssignments($user)->map(fn ($a): array => [
            'role' => $a->role,
            'label' => config("access.roles.{$a->role}.label"),
            'scope' => $catalogue->scope($a->role),
            'centre_id' => $a->centre_id,
        ])->values()->all();

        $permissions = array_values(array_filter(
            array_keys((array) config('access.permissions', [])),
            fn (string $p): bool => $access->can($user, $p),
        ));

        $totp = app(TotpService::class)->hasConfirmed($user);
        $passkeys = $user->passkeys()->count();
        $required = $access->requiresMfa($user);
        $grant = $access->activeGrant();

        return [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone_e164 !== null ? PhoneNumber::mask($user->phone_e164) : null,
                'id_number' => $user->maskedIdNumber(),
                'locale' => $user->locale,
            ],
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
                'code' => $tenant->code,
                'name' => $tenant->trading_name,
                'status' => $tenant->status->value,
                'branding' => $tenant->branding ?? (object) [],
            ],
            'roles' => $assignments,
            'platform_roles' => $access->platformRoles($user),
            'permissions' => $permissions,
            'security' => [
                'totp' => $totp,
                'passkeys' => $passkeys,
                'mfa_required' => $required,
                'mfa_setup_required' => $required && ! $totp && $passkeys === 0,
            ],
            'support_access' => $grant === null ? null : ['grant_id' => $grant->id, 'ends_at' => $grant->ends_at?->toIso8601String()],
            'session' => ['mode' => $request->hasSession() ? 'session' : 'token'],
        ];
    }
}
