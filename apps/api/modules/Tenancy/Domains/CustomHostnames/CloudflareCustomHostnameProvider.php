<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains\CustomHostnames;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Modules\Tenancy\Enums\SslStatus;
use RuntimeException;
use Throwable;

/**
 * Cloudflare for SaaS custom hostnames: Cloudflare issues and renews a DV certificate
 * for each tenant domain and proxies it to our fallback origin.
 *
 * @see https://developers.cloudflare.com/api/resources/custom_hostnames/
 */
final class CloudflareCustomHostnameProvider implements CustomHostnameProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly string $api,
        private readonly string $zoneId,
        private readonly string $token,
        private readonly int $timeout = 5,
    ) {}

    public function register(string $hostname): string
    {
        $response = $this->client()->post($this->path(), [
            'hostname' => $hostname,
            'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']],
        ]);

        $id = $this->result($response)['id'] ?? null;

        if (! is_string($id)) {
            throw new RuntimeException('Cloudflare did not return a custom hostname id.');
        }

        return $id;
    }

    public function status(string $hostname, string $reference): HostnameStatus
    {
        $result = $this->result($this->client()->get($this->path().'/'.$reference));
        $ssl = is_array($result['ssl'] ?? null) ? $result['ssl'] : [];
        $state = is_string($ssl['status'] ?? null) ? $ssl['status'] : 'unknown';

        $status = match (true) {
            $state === 'active' => SslStatus::Active,
            in_array($state, ['initializing', 'pending_validation', 'pending_issuance', 'pending_deployment'], true) => SslStatus::Issuing,
            in_array($state, ['expired', 'deleted', 'validation_timed_out', 'issuance_timed_out', 'deployment_timed_out'], true) => SslStatus::Failed,
            default => SslStatus::Pending,
        };

        $errors = is_array($ssl['validation_errors'] ?? null) ? $ssl['validation_errors'] : [];
        $first = is_array($errors[0] ?? null) && is_string($errors[0]['message'] ?? null) ? $errors[0]['message'] : null;

        return new HostnameStatus($status, $first);
    }

    public function remove(string $reference): void
    {
        $response = $this->client()->delete($this->path().'/'.$reference);

        if ($response->status() !== 404) {
            $this->result($response);
        }
    }

    private function client(): PendingRequest
    {
        // Retry only transient failures (network, 5xx, 429); client errors are final.
        return $this->http->withToken($this->token)->acceptJson()->timeout($this->timeout)->retry(
            2,
            200,
            fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
            throw: false,
        );
    }

    private function path(): string
    {
        return rtrim($this->api, '/').'/zones/'.$this->zoneId.'/custom_hostnames';
    }

    /** @return array<string, mixed> */
    private function result(Response $response): array
    {
        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? false) !== true) {
            $message = is_array($body) && is_array($body['errors'] ?? null) && is_array($body['errors'][0] ?? null)
                ? (string) ($body['errors'][0]['message'] ?? 'unknown error')
                : 'HTTP '.$response->status();

            throw new RuntimeException('Cloudflare custom hostname request failed: '.$message);
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }
}
