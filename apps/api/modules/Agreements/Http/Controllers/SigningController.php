<?php

declare(strict_types=1);

namespace Modules\Agreements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Services\AgreementDocuments;
use Modules\Agreements\Services\SigningService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Public: signing by link, and verifying a signed document. No account needed. */
final class SigningController
{
    public function show(string $token): JsonResponse
    {
        $signer = $this->service()->find($token);

        return response()->json(['data' => $this->present($signer)]);
    }

    public function code(string $token): JsonResponse
    {
        $result = $this->service()->sendCode($this->service()->find($token));

        return response()->json(['data' => $result]);
    }

    public function sign(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'name' => ['required', 'string', 'max:150'],
            'agree' => ['required', 'boolean'],
        ]);
        $signer = $this->service()->find($token);
        $this->service()->sign($signer, $data['code'], $data['name'], (bool) $data['agree'], $request->ip(), $request->userAgent());

        return response()->json(['data' => $this->present($signer->refresh())]);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $signer = $this->service()->find($token);
        $this->service()->decline($signer, $data['reason']);

        return response()->json(['data' => $this->present($signer->refresh())]);
    }

    public function document(string $token): Response
    {
        $signer = $this->service()->find($token);
        $agreement = $signer->agreement()->firstOrFail();
        abort_unless($agreement->status === 'completed', 404);

        return AgreementController::pdf($agreement);
    }

    public function verify(string $code): JsonResponse
    {
        return response()->json(['data' => $this->service()->verification($code)]);
    }

    /** @return array<string, mixed> */
    private function present(Signer $signer): array
    {
        $agreement = $signer->agreement()->firstOrFail();
        /** @var Tenant $tenant */
        $tenant = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->findOrFail($agreement->tenant_id));
        $expired = $agreement->expires_at !== null && $agreement->expires_at->isPast();

        return [
            'agreement' => [
                'title' => $agreement->title,
                'provider' => $tenant->trading_name,
                'status' => $expired && $agreement->status === 'sent' ? 'expired' : $agreement->status,
                'signature_level' => $agreement->signature_level,
                'body' => $agreement->body,
                'text_sha256' => $agreement->body_sha256,
                'expires_at' => $agreement->expires_at?->toIso8601String(),
                'verify_url' => AgreementDocuments::verifyUrl($agreement, $tenant),
                'has_document' => $agreement->sealed_file_id !== null,
            ],
            'you' => [
                'name' => $signer->name, 'role' => $signer->role, 'status' => $signer->status,
                'pin_to' => $signer->maskedPhone() ?? $signer->maskedEmail(),
                'signed_at' => $signer->signed_at?->toIso8601String(),
            ],
            'parties' => $agreement->signers()->get()->map(fn (Signer $s) => ['role' => $s->role, 'name' => $s->name, 'status' => $s->status])->values()->all(),
        ];
    }

    private function service(): SigningService
    {
        return app(SigningService::class);
    }
}
