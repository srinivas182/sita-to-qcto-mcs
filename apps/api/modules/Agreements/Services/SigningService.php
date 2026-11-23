<?php

declare(strict_types=1);

namespace Modules\Agreements\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Notifications\AgreementMail;
use Modules\Audit\AuditLogger;
use Modules\Identity\Otp\OtpDispatcher;
use Modules\Identity\Support\IdentityHasher;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Public signing by link. A signer reads the document, asks for a PIN (sent to the
 * phone or email the provider entered), types their full name, agrees and signs.
 */
final class SigningService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const MAX_CODES = 8;

    public const RESEND_AFTER_SECONDS = 30;

    public function __construct(
        private readonly OtpDispatcher $otp,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly AgreementService $agreements,
    ) {}

    public function find(string $token): Signer
    {
        return Signer::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    /** @return array{channel: string, destination: string|null} */
    public function sendCode(Signer $signer): array
    {
        $agreement = $this->open($signer);
        if ($signer->code_sent_at !== null && $signer->code_sent_at->gt(now()->subSeconds(self::RESEND_AFTER_SECONDS))) {
            throw ValidationException::withMessages(['code' => 'Wait a moment before asking for another PIN.']);
        }
        if ($signer->codes_sent >= self::MAX_CODES) {
            throw ValidationException::withMessages(['code' => 'Too many PINs have been sent. Ask the training provider to send the agreement again.']);
        }
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $tenant = $this->tenant($agreement);
        $channel = null;
        if ($signer->phone !== null) {
            $channel = $this->otp->deliver($signer->phone, "{$code} is your PIN to sign \"{$agreement->title}\" for {$tenant->trading_name}. Do not share it.", null, $code);
        }
        if ($channel === null && $signer->email !== null) {
            Notification::route('mail', $signer->email)->notify(new AgreementMail('code', $tenant->trading_name, $agreement->title, $signer->name, '', $code));
            $channel = 'email';
        }
        if ($channel === null) {
            throw ValidationException::withMessages(['code' => 'We could not send a PIN just now. Try again in a minute.']);
        }
        $signer->forceFill([
            'code_hash' => IdentityHasher::hash('agreement-otp:'.$signer->id, $code), 'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'code_sent_at' => now(), 'code_attempts' => 0, 'codes_sent' => $signer->codes_sent + 1,
            'evidence' => ['channel' => $channel, 'destination' => $channel === 'email' ? $signer->maskedEmail() : $signer->maskedPhone()],
        ])->save();

        return ['channel' => $channel, 'destination' => $signer->evidence['destination'] ?? null];
    }

    public function sign(Signer $signer, string $code, string $typedName, bool $agree, ?string $ip, ?string $userAgent): Agreement
    {
        $agreement = $this->open($signer);
        if (! $agree) {
            throw ValidationException::withMessages(['agree' => 'Tick the box to confirm you agree to sign.']);
        }
        if ($signer->code_hash === null || $signer->code_expires_at === null || $signer->code_expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'Ask for a PIN first, or a new one if it has expired.']);
        }
        if ($signer->code_attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'Too many wrong PINs. Ask for a new one.']);
        }
        if (! hash_equals($signer->code_hash, IdentityHasher::hash('agreement-otp:'.$signer->id, preg_replace('/\D/', '', $code) ?? ''))) {
            $signer->forceFill(['code_attempts' => $signer->code_attempts + 1])->save();
            throw ValidationException::withMessages(['code' => 'That PIN is not right.']);
        }
        if (self::normalName($typedName) !== self::normalName($signer->name)) {
            throw ValidationException::withMessages(['name' => "Type your full name as it appears here: {$signer->name}."]);
        }

        $completed = DB::transaction(function () use ($signer, $agreement, $typedName, $ip, $userAgent): bool {
            $signer->forceFill([
                'status' => 'signed', 'signed_at' => now(), 'signed_name' => trim($typedName), 'ip' => $ip, 'user_agent' => mb_substr((string) $userAgent, 0, 300),
                'code_hash' => null, 'code_expires_at' => null,
                'evidence' => [...($signer->evidence ?? []), 'method' => 'one-time PIN', 'document_sha256' => $agreement->body_sha256, 'code_sent_at' => $signer->code_sent_at?->toIso8601String()],
            ])->save();

            return ! Signer::query()->where('agreement_id', $agreement->id)->where('status', '!=', 'signed')->lockForUpdate()->exists();
        });
        $this->audit->record('agreement.signed', $agreement, [], ['role' => $signer->role, 'ip' => $ip]);
        if ($completed) {
            $this->agreements->complete($agreement);
        }

        return $agreement->refresh();
    }

    public function decline(Signer $signer, string $reason): void
    {
        $agreement = $this->open($signer);
        $signer->forceFill(['status' => 'declined', 'decline_reason' => $reason])->save();
        $agreement->forceFill(['status' => 'declined'])->save();
        $this->audit->record('agreement.declined', $agreement, [], ['role' => $signer->role]);
    }

    /**
     * What the public verification page shows.
     *
     * @return array<string, mixed>
     */
    public function verification(string $code): array
    {
        $agreement = Agreement::query()->where('verify_code', strtolower($code))->firstOrFail();

        return [
            'title' => $agreement->title,
            'provider' => $this->tenant($agreement)->trading_name,
            'status' => $agreement->status,
            'signature_level' => $agreement->signature_level,
            'sent_at' => $agreement->sent_at?->toIso8601String(),
            'completed_at' => $agreement->completed_at?->toIso8601String(),
            'text_sha256' => $agreement->body_sha256,
            'document_sha256' => $agreement->sealed_sha256,
            'signers' => $agreement->signers()->get()->map(fn (Signer $s): array => [
                'role' => $s->role, 'name' => $s->name, 'status' => $s->status, 'signed_at' => $s->signed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function open(Signer $signer): Agreement
    {
        $agreement = $signer->agreement()->firstOrFail();
        if ($signer->status === 'signed') {
            throw ValidationException::withMessages(['status' => 'You have already signed this agreement.']);
        }
        if ($agreement->status !== 'sent' || $agreement->signature_level !== 'simple') {
            throw ValidationException::withMessages(['status' => 'This agreement can no longer be signed here.']);
        }
        if ($agreement->expires_at !== null && $agreement->expires_at->isPast()) {
            throw ValidationException::withMessages(['status' => 'This signing link has expired. Ask the training provider to send it again.']);
        }

        return $agreement;
    }

    private function tenant(Agreement $agreement): Tenant
    {
        /** @var Tenant */
        return $this->context->runAsPlatform(fn () => Tenant::query()->findOrFail($agreement->tenant_id));
    }

    private static function normalName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
