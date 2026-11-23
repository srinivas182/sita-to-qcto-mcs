<?php

declare(strict_types=1);

namespace Modules\Agreements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Services\AgreementDocuments;
use Modules\Agreements\Services\SigningService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * The signed-in person's own documents to sign, for the app. Signing still needs a
 * one-time PIN, exactly as by link, and the evidence records that it was in the app.
 */
final class MyAgreementsController
{
    public function index(Request $request): JsonResponse
    {
        $signers = Signer::query()->where('user_id', $this->me($request)->id)->latest()->limit(100)->get();
        $agreements = Agreement::query()->whereIn('id', $signers->pluck('agreement_id'))->get()->keyBy('id');

        return response()->json(['data' => $signers->map(function (Signer $s) use ($agreements): ?array {
            $a = $agreements->get($s->agreement_id);

            return $a === null ? null : [
                'id' => $s->id, 'title' => $a->title, 'role' => $s->role, 'status' => $s->status, 'agreement_status' => $this->status($a),
                'sent_at' => $a->sent_at?->toIso8601String(), 'signed_at' => $s->signed_at?->toIso8601String(), 'can_sign' => $this->canSign($s, $a),
            ];
        })->filter()->values()->all()]);
    }

    public function show(Request $request, string $signer): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $signer))]);
    }

    public function code(Request $request, string $signer): JsonResponse
    {
        return response()->json(['data' => app(SigningService::class)->sendCode($this->find($request, $signer))]);
    }

    public function sign(Request $request, string $signer): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:150'], 'agree' => ['required', 'boolean']]);
        $s = $this->find($request, $signer);
        $device = $this->me($request)->currentAccessToken()->device_id ?? null;
        app(SigningService::class)->sign($s, $data['code'], $data['name'], (bool) $data['agree'], $request->ip(), $request->userAgent(),
            array_filter(['via' => 'app', 'device' => is_string($device) ? $device : null]));

        return response()->json(['data' => $this->present($s->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Signer $s): array
    {
        $a = $s->agreement()->firstOrFail();
        /** @var Tenant $tenant */
        $tenant = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->whereKey($a->tenant_id)->firstOrFail());

        return [
            'id' => $s->id, 'title' => $a->title, 'provider' => $tenant->trading_name, 'body' => $a->body, 'text_sha256' => $a->body_sha256,
            'agreement_status' => $this->status($a), 'expires_at' => $a->expires_at?->toIso8601String(),
            'you' => ['name' => $s->name, 'role' => $s->role, 'status' => $s->status, 'pin_to' => $s->maskedPhone() ?? $s->maskedEmail(), 'signed_at' => $s->signed_at?->toIso8601String()],
            'parties' => $a->signers()->get()->map(fn (Signer $p) => ['role' => $p->role, 'name' => $p->name, 'status' => $p->status])->values()->all(),
            'can_sign' => $this->canSign($s, $a),
            'verify_url' => AgreementDocuments::verifyUrl($a, $tenant),
        ];
    }

    private function status(Agreement $a): string
    {
        return $a->status === 'sent' && $a->expires_at !== null && $a->expires_at->isPast() ? 'expired' : $a->status;
    }

    private function canSign(Signer $s, Agreement $a): bool
    {
        return $s->status === 'pending' && $a->signature_level === 'simple' && $this->status($a) === 'sent';
    }

    private function find(Request $request, string $id): Signer
    {
        return Signer::query()->where('user_id', $this->me($request)->id)->whereKey($id)->firstOrFail();
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
