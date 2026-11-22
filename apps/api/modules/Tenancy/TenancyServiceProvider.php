<?php

declare(strict_types=1);

namespace Modules\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Modules\Support\ModuleServiceProvider;
use Modules\Tenancy\Console\AccreditationAlerts;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Domains\CustomHostnames\CloudflareCustomHostnameProvider;
use Modules\Tenancy\Domains\CustomHostnames\CustomHostnameProvider;
use Modules\Tenancy\Domains\CustomHostnames\LogCustomHostnameProvider;
use Modules\Tenancy\Domains\DnsResolver;
use Modules\Tenancy\Domains\NativeDnsResolver;
use Modules\Tenancy\Exceptions\TenantUnavailable;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDomain;
use Modules\Tenancy\Resolution\TenantCache;
use RuntimeException;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // One context per request / job; Octane and queue workers reset scoped bindings.
        $this->app->scoped(TenantContext::class);

        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);

        $this->app->singleton(CustomHostnameProvider::class, function (Application $app): CustomHostnameProvider {
            $driver = config('platform.domains.provider', 'log');

            if ($driver === 'cloudflare') {
                $zone = config('platform.domains.cloudflare.zone_id');
                $token = config('platform.domains.cloudflare.token');

                if (! is_string($zone) || $zone === '' || ! is_string($token) || $token === '') {
                    throw new RuntimeException('Cloudflare custom hostnames need CLOUDFLARE_ZONE_ID and CLOUDFLARE_API_TOKEN.');
                }

                return new CloudflareCustomHostnameProvider(
                    $app->make(Http::class),
                    (string) config('platform.domains.cloudflare.api'),
                    $zone,
                    $token,
                    (int) config('platform.domains.cloudflare.timeout', 5),
                );
            }

            return new LogCustomHostnameProvider;
        });
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $this->propagateContextToDatabase();
        $this->propagateContextToQueues();
        $this->invalidateResolutionCache();

        if ($this->app->runningInConsole()) {
            $this->commands([AccreditationAlerts::class]);
        }
        Schedule::command('centres:accreditation-alerts')->dailyAt('06:10')->onOneServer();
    }

    /** Re-apply the context whenever a database connection is (re)established. */
    private function propagateContextToDatabase(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            if (! $this->app->resolved(TenantContext::class)) {
                return;
            }

            $context = $this->app->make(TenantContext::class);

            if (! $context->isEmpty()) {
                $context->apply($event->connection);
            }
        });
    }

    /**
     * Jobs carry the tenant they were dispatched for and run in it. The previous
     * context is restored afterwards, so a sync job inside a request does not change
     * the request's tenant and a worker never carries one job's tenant into the next.
     */
    private function propagateContextToQueues(): void
    {
        /** @var array<string, array{0: Tenant|null, 1: bool}> $previous keyed by job uuid */
        $previous = [];

        Queue::createPayloadUsing(function (): array {
            return ['tenancy' => $this->app->make(TenantContext::class)->snapshot()];
        });

        Event::listen(function (JobProcessing $event) use (&$previous): void {
            $context = $this->app->make(TenantContext::class);
            $previous[$this->jobKey($event->job)] = [$context->tenant(), $context->isPlatform()];

            $payload = $event->job->payload();
            $tenancy = is_array($payload['tenancy'] ?? null) ? $payload['tenancy'] : [];

            if (($tenancy['platform'] ?? false) === true) {
                $context->setPlatform();

                return;
            }

            $tenantId = $tenancy['tenant_id'] ?? null;

            if (! is_string($tenantId)) {
                $context->clear();

                return;
            }

            $tenant = $context->runAsPlatform(fn (): ?Tenant => Tenant::query()->find($tenantId));

            if ($tenant === null || ! $tenant->status->allowsAccess()) {
                // Throwing (rather than fail()) stops both sync and worker queues from
                // firing the job; workers then retry or fail it as configured.
                $context->clear();

                throw TenantUnavailable::forJob($tenantId);
            }

            $context->setTenant($tenant);
        });

        $restore = function (JobProcessed|JobExceptionOccurred|JobFailed $event) use (&$previous): void {
            $key = $this->jobKey($event->job);

            if (! array_key_exists($key, $previous)) {
                return;
            }

            [$tenant, $platform] = $previous[$key];
            unset($previous[$key]);
            $context = $this->app->make(TenantContext::class);

            match (true) {
                $platform => $context->setPlatform(),
                $tenant !== null => $context->setTenant($tenant),
                default => $context->clear(),
            };
        };

        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
        Event::listen(JobFailed::class, $restore);
    }

    private function jobKey(Job $job): string
    {
        return $job->uuid() ?? spl_object_hash($job);
    }

    private function invalidateResolutionCache(): void
    {
        $forgetDomain = fn (TenantDomain $domain) => $this->app->make(TenantCache::class)->forgetDomain($domain);
        TenantDomain::saved($forgetDomain);
        TenantDomain::deleted($forgetDomain);

        $forgetTenant = fn (Tenant $tenant) => $this->app->make(TenantCache::class)->forgetTenant($tenant);
        Tenant::saved($forgetTenant);
        Tenant::deleted($forgetTenant);
    }
}
