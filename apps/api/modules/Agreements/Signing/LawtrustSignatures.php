<?php

declare(strict_types=1);

namespace Modules\Agreements\Signing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Services\AgreementDocuments;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Context\TenantContext;
use RuntimeException;

/**
 * Advanced electronic signatures through LAWtrust's SigningHub (Ascertia) API.
 * The document is uploaded as a package, each signer is added to its workflow and
 * SigningHub emails them; we poll for completion and keep the signed PDF.
 *
 * Endpoints follow the SigningHub v4 API guide. They must be checked against the
 * LAWtrust sandbox before the connection is enabled live.
 */
final class LawtrustSignatures implements SignatureProvider
{
    public function __construct(private readonly AgreementDocuments $documents) {}

    public function level(): string
    {
        return 'advanced';
    }

    public function available(): bool
    {
        return $this->integration() !== null;
    }

    public function start(Agreement $agreement): void
    {
        $api = $this->api();
        $package = $api->post('/v4/packages', ['package_name' => mb_substr($agreement->title, 0, 120)])->throw()->json('package_id');
        $bytes = $this->documents->renderUnsigned($agreement);
        // A copy: withBody() would otherwise stick to the requests that follow.
        (clone $api)->withHeaders(['x-file-name' => 'agreement-'.$agreement->verify_code.'.pdf', 'x-source' => 'API'])
            ->withBody($bytes, 'application/octet-stream')
            ->post("/v4/packages/{$package}/documents")->throw();
        $users = array_values($agreement->signers()->get()->map(fn ($s): array => [
            'user_email' => $s->email, 'user_name' => $s->name, 'role' => 'SIGNER', 'email_notification' => true,
        ])->all());
        $api->post("/v4/packages/{$package}/workflow/users", $users)->throw();
        $api->post("/v4/packages/{$package}/workflow")->throw();
        $agreement->forceFill(['provider_ref' => (string) $package])->save();
    }

    public function sync(Agreement $agreement): bool
    {
        if ($agreement->provider_ref === null) {
            return false;
        }
        $api = $this->api();
        $package = $api->get("/v4/packages/{$agreement->provider_ref}")->throw()->json();
        $status = strtoupper((string) ($package['package_status'] ?? ''));
        if ($status === 'DECLINED') {
            $agreement->forceFill(['status' => 'declined'])->save();

            return false;
        }
        if ($status !== 'COMPLETED') {
            return false;
        }
        $document = $package['documents'][0]['document_id'] ?? null;
        if ($document === null) {
            throw new RuntimeException('SigningHub returned no document for a completed package.');
        }
        $signed = $api->get("/v4/packages/{$agreement->provider_ref}/documents/{$document}")->throw()->body();
        $this->documents->storeSigned($agreement, $signed);

        return true;
    }

    private function integration(): ?Integration
    {
        return app(TenantContext::class)->runAsPlatform(fn () => Integration::query()->active('esign')->where('driver', 'esign.lawtrust')->first());
    }

    private function api(): PendingRequest
    {
        $i = $this->integration() ?? throw new RuntimeException('LAWtrust is not connected.');
        $base = rtrim((string) $i->value('base_url'), '/');
        $token = Http::asForm()->timeout(20)->post($base.'/authenticate', [
            'grant_type' => 'password', 'client_id' => $i->value('client_id'), 'client_secret' => $i->value('client_secret'),
            'username' => $i->value('username'), 'password' => $i->value('password'),
        ])->throw()->json('access_token');

        return Http::baseUrl($base)->withToken((string) $token)->acceptJson()->timeout(30);
    }
}
