<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use Illuminate\Validation\ValidationException;
use Modules\Access\Exceptions\AccessError;
use Modules\Access\Models\SupportAccessGrant;
use Modules\Identity\Models\User;
use Modules\Tenancy\Models\Tenant;

/**
 * Consent-based support access. Platform staff ask; the provider's owner decides;
 * access is time-boxed and can be ended early. Unlike typical "impersonate"
 * buttons, nothing happens without the provider's approval, and the provider sees
 * every action taken under the grant in its own audit trail.
 */
final class SupportAccessService
{
    /** Platform context: a staff member asks a provider for access. */
    public function request(User $staff, Tenant $tenant, string $reason, int $minutes): SupportAccessGrant
    {
        $max = (int) config('identity.support_access.max_minutes', 240);

        if ($minutes < 15 || $minutes > $max) {
            throw ValidationException::withMessages(['minutes' => "Choose between 15 and {$max} minutes."]);
        }

        return SupportAccessGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_to' => $staff->id,
            'reason' => mb_substr(trim($reason), 0, 500),
            'minutes' => $minutes,
            'status' => 'pending',
        ]);
    }

    /** Tenant context: the owner approves; the window starts now. */
    public function approve(SupportAccessGrant $grant, User $owner): SupportAccessGrant
    {
        $this->expect($grant, 'pending');

        $grant->forceFill([
            'status' => 'approved',
            'approved_by' => $owner->id,
            'decided_at' => now(),
            'starts_at' => now(),
            'ends_at' => now()->addMinutes($grant->minutes),
        ])->save();

        return $grant;
    }

    public function deny(SupportAccessGrant $grant, User $owner): SupportAccessGrant
    {
        $this->expect($grant, 'pending');

        $grant->forceFill(['status' => 'denied', 'approved_by' => $owner->id, 'decided_at' => now()])->save();

        return $grant;
    }

    /** End an approved window early (owner or the staff member). */
    public function revoke(SupportAccessGrant $grant): SupportAccessGrant
    {
        $this->expect($grant, 'approved');

        $grant->forceFill(['status' => 'revoked', 'ends_at' => now()])->save();

        return $grant;
    }

    private function expect(SupportAccessGrant $grant, string $status): void
    {
        if ($grant->status !== $status || ($status === 'approved' && $grant->ends_at?->isPast())) {
            throw AccessError::grantState($grant->ends_at?->isPast() && $grant->status === 'approved' ? 'expired' : $grant->status);
        }
    }
}
