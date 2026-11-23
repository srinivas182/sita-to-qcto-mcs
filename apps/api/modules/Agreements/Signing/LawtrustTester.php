<?php

declare(strict_types=1);

namespace Modules\Agreements\Signing;

use Illuminate\Support\Facades\Http;
use Modules\Settings\Contracts\IntegrationTester;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\TestResult;
use Throwable;

/** Signs in to SigningHub with the saved credentials. Sends nothing. */
final class LawtrustTester implements IntegrationTester
{
    public function test(Integration $integration): TestResult
    {
        try {
            $response = Http::asForm()->timeout(15)->post(rtrim((string) $integration->value('base_url'), '/').'/authenticate', [
                'grant_type' => 'password', 'client_id' => $integration->value('client_id'), 'client_secret' => $integration->value('client_secret'),
                'username' => $integration->value('username'), 'password' => $integration->value('password'),
            ]);
        } catch (Throwable $e) {
            return new TestResult(false, 'Could not reach SigningHub: '.$e->getMessage());
        }

        return $response->successful() && filled($response->json('access_token'))
            ? TestResult::ok('Signed in to SigningHub.')
            : new TestResult(false, 'SigningHub refused the credentials ('.$response->status().').');
    }
}
