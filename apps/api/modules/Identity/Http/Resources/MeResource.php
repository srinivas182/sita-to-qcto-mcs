<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        /** @var list<array{role: string, label: string, scope: string, centre_id: string|null}> $assignments */
        $assignments = $access->tenantAssignments($user)->map(fn ($a): array => [
            'role' => $a->role,
            'label' => config("access.roles.{$a->role}.label"),
            'scope' => $catalogue->scope($a->role),
            'centre_id' => $a->centre_id,
        ])->values()->all();

        /** @var list<string> $permissions */
        $permissions = array_values(array_filter(
            array_keys((array) config('access.permissions', [])),
            fn (string $p): bool => $access->can($user, $p),
        ));

        /** @var bool $totp */
        $totp = app(TotpService::class)->hasConfirmed($user);
        /** @var int $passkeys */
        $passkeys = $user->passkeys()->count();
        /** @var bool $required */
        $required = $access->requiresMfa($user);
        /** @var list<string> $platformRoles */
        $platformRoles = $access->platformRoles($user);
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
                /** @var array<string, string> */
                'branding' => $tenant->branding ?? (object) [],
                // Plan status for the console banner (null: no plan).
                'billing' => $this->billing(),
            ],
            /** @var list<array{role: string, label: string, scope: string, centre_id: string|null}> */
            'roles' => $assignments,
            /** @var list<string> */
            'platform_roles' => $platformRoles,
            /** @var list<string> */
            'permissions' => $permissions,
            'security' => [
                /** @var bool */
                'totp' => $totp,
                'passkeys' => $passkeys,
                /** @var bool */
                'mfa_required' => $required,
                'mfa_setup_required' => $required && ! $totp && $passkeys === 0,
            ],
            'support_access' => $grant === null ? null : ['grant_id' => $grant->id, 'ends_at' => $grant->ends_at?->toIso8601String()],
            'session' => ['mode' => $request->hasSession() ? 'session' : 'token'],
        ];
    }

    /** @return array{status: string, trial_ends_at: string|null}|null */
    private function billing(): ?array
    {
        // Read through the provider's RLS context; Billing owns the table.
        $row = DB::table('subscriptions')->select(['status', 'trial_ends_at'])->first();

        return $row === null ? null : [
            'status' => (string) $row->status,
            'trial_ends_at' => $row->trial_ends_at !== null ? Carbon::parse((string) $row->trial_ends_at)->toIso8601String() : null,
        ];
    }
}
