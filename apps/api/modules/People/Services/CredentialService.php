<?php

declare(strict_types=1);

namespace Modules\People\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\People\Models\StaffCredential;
use Modules\Qualifications\Models\Qualification;

/**
 * The staff credential register and the rules that use it:
 * - a person's registration is checked by someone else before it counts;
 * - a moderator must also be a registered assessor for the same qualifications;
 * - only a verified, in-date credential whose scope includes the qualification
 *   lets someone facilitate, assess or moderate it.
 */
final class CredentialService
{
    /** Changing any of these sends a verified credential back for checking. */
    private const CHECKED_FIELDS = ['role', 'body', 'registration_number', 'qualification_ids', 'starts_on', 'expires_on', 'file_id'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $input */
    public function save(User $person, array $input, ?StaffCredential $existing, User $actor): StaffCredential
    {
        $data = Validator::make($input, [
            'role' => [$existing ? 'sometimes' : 'required', Rule::in(array_keys(StaffCredential::ROLES))],
            'body' => [$existing ? 'sometimes' : 'required', 'string', 'min:2', 'max:120'],
            'registration_number' => [$existing ? 'sometimes' : 'required', 'string', 'min:3', 'max:60'],
            'qualification_ids' => [$existing ? 'sometimes' : 'required', 'array', 'min:1', 'max:100'],
            'qualification_ids.*' => ['ulid', 'distinct'],
            'starts_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'file_id' => ['nullable', 'ulid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'qualification_ids.required' => 'Choose the qualifications this registration covers.',
            'expires_on.after_or_equal' => 'The expiry date is before the start date.',
        ])->validate();

        if (isset($data['qualification_ids'])) {
            $found = Qualification::query()->whereIn('id', $data['qualification_ids'])->count();
            if ($found !== count($data['qualification_ids'])) {
                throw ValidationException::withMessages(['qualification_ids' => 'Choose qualifications from the catalogue.']);
            }
        }
        if (isset($data['file_id']) && ! File::query()->whereKey($data['file_id'])->where('purpose', 'credential_document')->exists()) {
            throw ValidationException::withMessages(['file_id' => 'Upload the registration letter first.']);
        }
        if (isset($data['body'])) {
            $data['body'] = trim((string) $data['body']);
        }

        $credential = $existing ?? new StaffCredential(['user_id' => $person->id, 'added_by' => $actor->id]);
        $credential->fill($data);
        $changed = $existing !== null && $credential->isDirty(self::CHECKED_FIELDS);
        if ($changed && $credential->status !== 'pending') {
            $credential->forceFill(['status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'alerted_for' => null]);
        }
        if ($credential->isDirty('expires_on')) {
            $credential->alerted_for = null; // renewed: reminders start again
        }
        $credential->save();

        $this->audit->record($existing ? 'credential.updated' : 'credential.added', $credential, [], [
            'person' => $person->id, 'role' => $credential->role, 'by' => $actor->id, 'rechecking' => $changed,
        ]);

        return $credential;
    }

    /** Someone other than the holder checks the registration against the letter (and the body's register). */
    public function verify(StaffCredential $credential, User $actor): StaffCredential
    {
        if ($credential->user_id === $actor->id) {
            throw ValidationException::withMessages(['credential' => 'Someone else must verify your own registration.']);
        }
        if ($credential->file_id === null) {
            throw ValidationException::withMessages(['credential' => 'Upload the registration letter before verifying.']);
        }
        if ($credential->expires_on !== null && $credential->expires_on->isPast()) {
            throw ValidationException::withMessages(['credential' => 'This registration has expired.']);
        }
        if ($credential->role === 'moderator') {
            $missing = $this->assessorGaps($credential->user_id, $credential->qualification_ids);
            if ($missing !== []) {
                throw ValidationException::withMessages(['credential' => 'A moderator must also be a verified assessor for: '.implode('; ', $missing).'.']);
            }
        }

        $credential->forceFill(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()])->save();
        $this->audit->record('credential.verified', $credential, [], ['person' => $credential->user_id, 'by' => $actor->id]);

        return $credential;
    }

    public function reject(StaffCredential $credential, string $reason, User $actor): StaffCredential
    {
        $credential->forceFill(['status' => 'rejected', 'verified_by' => $actor->id, 'verified_at' => now(),
            'notes' => trim(($credential->notes ?? '')."\nNot accepted: {$reason}")])->save();
        $this->audit->record('credential.rejected', $credential, [], ['person' => $credential->user_id, 'by' => $actor->id, 'reason' => $reason]);

        return $credential;
    }

    /**
     * May this person facilitate / assess / moderate this qualification on this day?
     *
     * @return array{ok: bool, reason: string, credential_id: string|null}
     */
    public function eligibility(string $userId, string $role, string $qualificationId, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::today();
        $label = StaffCredential::ROLES[$role] ?? $role;
        $all = StaffCredential::query()->where('user_id', $userId)->where('role', $role)->get();
        $covering = $all->filter(fn (StaffCredential $c) => $c->covers($qualificationId));

        $valid = $covering->first(fn (StaffCredential $c) => $c->validOn($on));
        if ($valid === null) {
            $reason = match (true) {
                $all->isEmpty() => "No {$label} registration recorded.",
                $covering->isEmpty() => "Their {$label} registration does not cover this qualification.",
                $covering->contains(fn (StaffCredential $c) => $c->status === 'pending') => "Their {$label} registration is waiting to be verified.",
                $covering->contains(fn (StaffCredential $c) => $c->status === 'verified') => "Their {$label} registration has expired or has not started.",
                default => "Their {$label} registration was not accepted.",
            };

            return ['ok' => false, 'reason' => $reason, 'credential_id' => null];
        }

        if ($role === 'moderator' && $this->assessorGaps($userId, [$qualificationId], $on) !== []) {
            return ['ok' => false, 'reason' => 'A moderator must also hold a valid assessor registration for this qualification.', 'credential_id' => $valid->id];
        }

        return ['ok' => true, 'reason' => "Registered {$label} ({$valid->body} {$valid->registration_number})"
            .($valid->expires_on ? ' until '.$valid->expires_on->format('j M Y') : '').'.', 'credential_id' => $valid->id];
    }

    /**
     * Qualifications (titles) the person is not a valid assessor for.
     *
     * @param  list<string>  $qualificationIds
     * @return list<string>
     */
    private function assessorGaps(string $userId, array $qualificationIds, ?CarbonImmutable $on = null): array
    {
        $assessor = StaffCredential::query()->where('user_id', $userId)->where('role', 'assessor')->get()
            ->filter(fn (StaffCredential $c) => $c->validOn($on));
        $missing = array_values(array_filter($qualificationIds, fn (string $q) => ! $assessor->contains(fn (StaffCredential $c) => $c->covers($q))));

        return $missing === [] ? [] : array_values(Qualification::query()->whereIn('id', $missing)->get()->map(fn (Qualification $q): string => $q->title)->all());
    }
}
