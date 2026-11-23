<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Enrolment\Identity\IdentityVerifier;
use Modules\Enrolment\Identity\TestIdentityVerifier;
use Modules\Enrolment\Models\IdentityCheck;
use Modules\Identity\Models\User;
use Modules\Identity\Support\IdType;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Context\TenantContext;
use Throwable;

/**
 * Optional Home Affairs ID checks (about R1 each through a reseller). Requests are queued
 * and run in one batch off-peak; results are compared with what the person gave us. Off
 * unless the platform has connected a reseller.
 */
final class IdentityCheckService
{
    /** @var array<string, class-string<IdentityVerifier>> */
    public const DRIVERS = ['identity.test' => TestIdentityVerifier::class];

    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function connected(): ?Integration
    {
        return $this->context->runAsPlatform(fn () => Integration::query()->active('identity')->whereIn('driver', array_keys(self::DRIVERS))->first());
    }

    /** Queue a check (once per person while one is waiting); runs in the next batch. */
    public function request(User $person, ?User $by, string $reason): IdentityCheck
    {
        if ($this->connected() === null) {
            throw ValidationException::withMessages(['identity' => 'Home Affairs ID checks are not connected for this platform.']);
        }
        if ($person->id_type !== IdType::SaId || $person->id_number === null) {
            throw ValidationException::withMessages(['identity' => 'Only a South African ID number can be checked with Home Affairs.']);
        }
        $waiting = IdentityCheck::query()->where('user_id', $person->id)->where('status', 'queued')->first();
        if ($waiting !== null) {
            return $waiting;
        }
        $check = IdentityCheck::query()->create(['user_id' => $person->id, 'requested_by' => $by?->id, 'reason' => $reason]);
        $this->audit->record('identity.check_requested', $check, [], ['reason' => $reason]);

        return $check;
    }

    /** Run up to $limit queued checks of the current provider. */
    public function runQueued(int $limit = 500): int
    {
        $integration = $this->connected();
        if ($integration === null) {
            return 0;
        }
        /** @var IdentityVerifier $verifier */
        $verifier = app(self::DRIVERS[$integration->driver]);
        $done = 0;
        foreach (IdentityCheck::query()->where('status', 'queued')->orderBy('created_at')->limit($limit)->get() as $check) {
            $this->run($check, $verifier, $integration);
            $done++;
        }

        return $done;
    }

    private function run(IdentityCheck $check, IdentityVerifier $verifier, Integration $integration): void
    {
        /** @var User|null $person */
        $person = $this->context->runAsPlatform(fn () => User::query()->whereKey($check->user_id)->first());
        if ($person === null || $person->id_number === null) {
            $check->forceFill(['status' => 'failed', 'result' => ['problem' => 'No ID number on record'], 'checked_at' => now()])->save();

            return;
        }
        try {
            $r = $verifier->verify($integration, (string) $person->id_number);
        } catch (Throwable $e) {
            report($e);
            $attempts = $check->attempts + 1;
            $check->forceFill(['attempts' => $attempts, 'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'driver' => $integration->driver])->save();

            return;
        }
        $problems = [];
        if ($r['found'] && $r['surname'] !== null && ! self::same($r['surname'], $person->last_name)) {
            $problems[] = "Home Affairs has the surname {$r['surname']}.";
        }
        if ($r['found'] && $r['first_names'] !== null && ! self::firstMatches($r['first_names'], $person->first_name)) {
            $problems[] = "Home Affairs has the first names {$r['first_names']}.";
        }
        $status = match (true) {
            ! $r['found'] => 'not_found',
            $r['alive'] === false => 'deceased',
            $problems !== [] => 'mismatch',
            default => 'verified',
        };
        $check->forceFill(['status' => $status, 'driver' => $integration->driver, 'checked_at' => now(), 'cost_cents' => $r['cost_cents'], 'attempts' => $check->attempts + 1,
            'result' => ['first_names' => $r['first_names'], 'surname' => $r['surname'], 'dob' => $r['dob'], 'alive' => $r['alive'], 'problems' => $problems]])->save();
        $this->audit->record('identity.checked', $check, [], ['status' => $status]);
    }

    /** @return array<string, mixed>|null the latest check, for staff */
    public static function latest(string $userId): ?array
    {
        $c = IdentityCheck::query()->where('user_id', $userId)->latest()->first();

        return $c === null ? null : ['id' => $c->id, 'status' => $c->status, 'checked_at' => $c->checked_at?->toIso8601String(),
            'requested_at' => $c->created_at?->toIso8601String(), 'problems' => $c->result['problems'] ?? []];
    }

    private static function same(string $a, string $b): bool
    {
        $n = fn (string $s) => strtolower((string) preg_replace('/[^a-z]/i', '', $s));

        return $n($a) === $n($b);
    }

    /** The name the person uses is one of their registered first names. */
    private static function firstMatches(string $registered, string $given): bool
    {
        $words = preg_split('/\s+/', strtolower(trim($registered))) ?: [];

        return in_array(strtolower(trim(explode(' ', trim($given))[0])), $words, true);
    }
}
