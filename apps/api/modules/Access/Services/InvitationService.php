<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Access\Exceptions\AccessError;
use Modules\Access\Models\Invitation;
use Modules\Access\Notifications\InvitationNotification;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Otp\OtpDispatcher;
use Modules\Identity\Support\MembershipStatus;
use Modules\Identity\Support\PhoneNumber;
use Modules\Tenancy\Context\TenantContext;

/**
 * Invite people into a training provider with a role (and optionally a centre).
 * The link carries a random token; only its hash is stored. Accepting creates the
 * person if new, or links the existing person (same email / phone) to this provider.
 */
final class InvitationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessService $access,
        private readonly OtpDispatcher $messages,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  array{email?: string|null, phone?: string|null, first_name?: string|null, last_name?: string|null, role: string, centre_id?: string|null}  $data
     * @return array{invitation: Invitation, token: string, url: string}
     */
    public function invite(User $by, array $data): array
    {
        $tenant = $this->context->tenant() ?? throw AccessError::notMember();
        $role = $data['role'];

        if (! $this->access->mayDelegate($by, $role)) {
            throw AccessError::cannotDelegate($role);
        }

        $email = isset($data['email']) && $data['email'] !== '' ? strtolower(trim($data['email'])) : null;
        $phone = isset($data['phone']) && $data['phone'] !== '' ? PhoneNumber::normalise($data['phone']) : null;

        if ($email === null && $phone === null) {
            throw ValidationException::withMessages(['email' => 'Give an email address or a mobile number.']);
        }

        if (isset($data['phone']) && $data['phone'] !== '' && $phone === null) {
            throw ValidationException::withMessages(['phone' => 'That mobile number is not valid.']);
        }

        // Validates role scope and centre ownership without needing a member yet.
        $this->access->guard($role, $data['centre_id'] ?? null);

        $token = Str::random(48);
        $invitation = Invitation::query()->create([
            'email' => $email,
            'phone_e164' => $phone,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'role' => $role,
            'centre_id' => $data['centre_id'] ?? null,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $by->id,
            'expires_at' => now()->addDays((int) config('identity.invitations.ttl_days', 7)),
        ]);

        $host = $tenant->domains()->where('is_primary', true)->value('hostname');
        $url = 'https://'.(is_string($host) ? $host : $tenant->code.'.'.config('platform.base_domain')).'/invite/'.$token;

        if ($email !== null) {
            Notification::route('mail', $email)->notify(new InvitationNotification($tenant->trading_name, $role, $url, $by->fullName()));
        }

        if ($phone !== null) {
            $this->messages->deliver($phone, "{$by->fullName()} invited you to {$tenant->trading_name}. Join here: {$url}");
        }

        return ['invitation' => $invitation, 'token' => $token, 'url' => $url];
    }

    /** Look up an open invitation by its token (for the acceptance screen). */
    public function find(string $token): Invitation
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();

        if ($invitation === null || ! $invitation->isOpen()) {
            throw AccessError::invitationInvalid();
        }

        return $invitation;
    }

    /**
     * Accept an invitation. A signed-in person must match the invited email/phone;
     * otherwise a new person is created from the given details.
     *
     * @param  array{first_name?: string|null, last_name?: string|null, password?: string|null}  $details
     */
    public function accept(string $token, ?User $current, array $details): User
    {
        $invitation = $this->find($token);

        return $this->db->transaction(function () use ($invitation, $current, $details): User {
            $user = $current ?? $this->context->runAsPlatform(fn (): ?User => $this->existingPerson($invitation));

            if ($current !== null && ! $this->matches($current, $invitation)) {
                throw AccessError::invitationMismatch();
            }

            if ($user === null) {
                $user = $this->context->runAsPlatform(fn (): User => User::query()->create([
                    'first_name' => $details['first_name'] ?? $invitation->first_name ?? '',
                    'last_name' => $details['last_name'] ?? $invitation->last_name ?? '',
                    'email' => $invitation->email,
                    'phone_e164' => $invitation->phone_e164,
                    'password' => $details['password'] ?? null,
                    'email_verified_at' => $invitation->email !== null ? now() : null,
                ]));
            }

            $membership = UserTenant::query()->firstOrNew(['user_id' => $user->id]);
            $membership->fill([
                'status' => MembershipStatus::Active,
                'centre_id' => $membership->centre_id ?? $invitation->centre_id,
                'invited_by' => $membership->invited_by ?? $invitation->invited_by,
                'joined_at' => $membership->joined_at ?? now(),
            ])->save();

            $this->access->forget($user);
            $this->access->assign($user, $invitation->role, $invitation->centre_id);

            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            return $user;
        });
    }

    public function revoke(Invitation $invitation): void
    {
        if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();
        }
    }

    private function existingPerson(Invitation $invitation): ?User
    {
        if ($invitation->email !== null) {
            $user = User::query()->whereRaw('lower(email) = ?', [$invitation->email])->first();

            if ($user !== null) {
                return $user;
            }
        }

        return $invitation->phone_e164 !== null ? User::query()->where('phone_e164', $invitation->phone_e164)->first() : null;
    }

    private function matches(User $user, Invitation $invitation): bool
    {
        return ($invitation->email !== null && strtolower((string) $user->email) === $invitation->email)
            || ($invitation->phone_e164 !== null && $user->phone_e164 === $invitation->phone_e164);
    }
}
