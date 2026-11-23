<?php

declare(strict_types=1);

namespace Modules\Agreements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\AgreementSettings;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Models\Template;
use Modules\Agreements\Services\AgreementDocuments;
use Modules\Agreements\Services\AgreementService;
use Modules\Agreements\Signing\LawtrustSignatures;
use Modules\Files\Models\File;
use Modules\Files\Storage\ContentDisposition;
use Modules\Identity\Models\User;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Agreements a provider sends for signing, and its signing settings. */
final class AgreementController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('agreements.view');
        $query = Agreement::query()->latest('sent_at');
        if (in_array($status = (string) $request->query('status'), ['sent', 'completed', 'declined', 'cancelled', 'expired'], true)) {
            $query->where('status', $status);
        }
        if (is_string($employer = $request->query('employer_id')) && $employer !== '') {
            $query->where('employer_id', $employer);
        }
        $rows = $query->limit(200)->get();
        $signers = Signer::query()->whereIn('agreement_id', $rows->pluck('id'))->orderBy('sort')->get()->groupBy('agreement_id');
        $counts = Agreement::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => $rows->map(fn (Agreement $a) => $this->summary($a) + [
                'signers' => ($signers->get($a->id) ?? collect())->map(fn (Signer $s) => ['role' => $s->role, 'name' => $s->name, 'status' => $s->status])->values()->all(),
            ])->values()->all(),
            'counts' => $counts,
        ]);
    }

    public function show(string $agreement): JsonResponse
    {
        Gate::authorize('agreements.view');

        return response()->json(['data' => $this->detail(Agreement::query()->findOrFail($agreement))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('agreements.manage');
        /** @var User $user */
        $user = $request->user();
        $a = app(AgreementService::class)->send($request->all(), $user);

        return response()->json(['data' => $this->detail($a)], 201);
    }

    public function cancel(Request $request, string $agreement): JsonResponse
    {
        Gate::authorize('agreements.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $a = Agreement::query()->findOrFail($agreement);
        app(AgreementService::class)->cancel($a, $data['reason']);

        return response()->json(['data' => $this->detail($a->refresh())]);
    }

    public function resend(string $agreement, string $signer): JsonResponse
    {
        Gate::authorize('agreements.manage');
        $s = Signer::query()->where('agreement_id', $agreement)->findOrFail($signer);
        app(AgreementService::class)->resend($s);

        return response()->json(['data' => $this->detail(Agreement::query()->findOrFail($agreement))]);
    }

    public function document(string $agreement): Response
    {
        Gate::authorize('agreements.view');
        $a = Agreement::query()->findOrFail($agreement);

        return self::pdf($a);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('agreements.view');

        return response()->json(['data' => $this->presentSettings()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('agreements.manage');
        $data = $request->validate(['advanced_by_default' => ['sometimes', 'boolean'], 'valid_days' => ['sometimes', 'integer', 'between:3,90']]);
        AgreementSettings::current()->fill($data)->save();

        return response()->json(['data' => $this->presentSettings()]);
    }

    /** The sealed PDF, for staff or the signer's own page. */
    public static function pdf(Agreement $agreement): Response
    {
        $file = $agreement->sealed_file_id ? File::query()->find($agreement->sealed_file_id) : null;
        abort_if($file === null, 404);

        return response(app(AgreementDocuments::class)->bytes($file), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($file->original_name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function presentSettings(): array
    {
        $s = AgreementSettings::current();

        return ['advanced_by_default' => $s->advanced_by_default, 'valid_days' => $s->valid_days, 'advanced_available' => app(LawtrustSignatures::class)->available()];
    }

    /** @return array<string, mixed> */
    private function summary(Agreement $a): array
    {
        return $a->only(['id', 'title', 'kind', 'status', 'signature_level', 'employer_id', 'site_id', 'qualification_id', 'verify_code']) + [
            'sent_at' => $a->sent_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'expires_at' => $a->expires_at?->toIso8601String(),
            'has_document' => $a->sealed_file_id !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Agreement $a): array
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->findOrFail($a->tenant_id));
        $template = Template::query()->find($a->template_id);

        return $this->summary($a) + [
            'body' => $a->body,
            'text_sha256' => $a->body_sha256,
            'document_sha256' => $a->sealed_sha256,
            'verify_url' => AgreementDocuments::verifyUrl($a, $tenant),
            'template' => $template ? ['id' => $template->id, 'name' => $template->name, 'version' => $template->version] : null,
            'signers' => $a->signers()->get()->map(fn (Signer $s) => $s->only(['id', 'role', 'name', 'status', 'decline_reason', 'reminders_sent']) + [
                'contact' => $s->maskedPhone() ?? $s->maskedEmail(),
                'signed_at' => $s->signed_at?->toIso8601String(),
                'evidence' => $s->status === 'signed' ? $s->evidence : null,
            ])->values()->all(),
        ];
    }
}
