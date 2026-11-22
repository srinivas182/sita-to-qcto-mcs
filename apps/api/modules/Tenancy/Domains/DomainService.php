<?php

declare(strict_types=1);

namespace Modules\Tenancy\Domains;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Tenancy\Domains\CustomHostnames\CustomHostnameProvider;
use Modules\Tenancy\Enums\DnsStatus;
use Modules\Tenancy\Enums\DomainType;
use Modules\Tenancy\Enums\SslStatus;
use Modules\Tenancy\Exceptions\DomainError;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDomain;
use Modules\Tenancy\Resolution\Hostname;
use Throwable;

/**
 * Connects hostnames to a training provider.
 *
 * Works for whichever context the caller is in: a TP owner acting for their own
 * tenant, or the platform. Writes for any other tenant are refused by the model
 * guard and by row-level security.
 *
 * Custom domain flow (one screen in the UI):
 *  1. add → we show a CNAME to our edge and a TXT ownership record
 *  2. check (live, repeatable) → DNS verified → edge certificate requested
 *  3. check again → certificate active → can be made primary
 */
final class DomainService
{
    public const MAX_CUSTOM_DOMAINS = 5;

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly CustomHostnameProvider $edge,
        private readonly ConnectionInterface $db,
    ) {}

    /** The free {code}.{base} domain every tenant gets; routable immediately. */
    public function addSubdomain(Tenant $tenant): TenantDomain
    {
        $hostname = Hostname::subdomainFor($tenant->code);

        return $this->insert(new TenantDomain([
            'tenant_id' => $tenant->id,
            'hostname' => $hostname,
            'type' => DomainType::Subdomain,
            'verification_token' => $this->token(),
            'dns_status' => DnsStatus::Verified,
            'ssl_status' => SslStatus::Active,
            'verified_at' => now(),
            'is_primary' => ! $tenant->domains()->where('is_primary', true)->exists(),
        ]), $hostname);
    }

    public function addCustomDomain(Tenant $tenant, string $hostname): TenantDomain
    {
        $host = Hostname::normalise($hostname);

        if (! Hostname::isValid($host)) {
            throw DomainError::invalid($hostname);
        }

        if (Hostname::isUnderBase($host)) {
            throw DomainError::platformDomain($host);
        }

        if ($tenant->domains()->where('type', DomainType::Custom)->count() >= self::MAX_CUSTOM_DOMAINS) {
            throw DomainError::limitReached(self::MAX_CUSTOM_DOMAINS);
        }

        return $this->insert(new TenantDomain([
            'tenant_id' => $tenant->id,
            'hostname' => $host,
            'type' => DomainType::Custom,
            'verification_token' => $this->token(),
            'dns_status' => DnsStatus::Pending,
            'ssl_status' => SslStatus::Pending,
            'is_primary' => false,
        ]), $host);
    }

    /** The records the provider must create, for display before any check. */
    public function instructions(TenantDomain $domain): DnsCheck
    {
        return new DnsCheck($this->expected($domain, withLookups: false));
    }

    /** Live DNS + certificate check; updates and returns the domain's status. */
    public function check(TenantDomain $domain): DnsCheck
    {
        if ($domain->type === DomainType::Subdomain) {
            return new DnsCheck([]);
        }

        $result = new DnsCheck($this->expected($domain, withLookups: true));

        if ($result->passed() && $domain->verified_at === null) {
            $domain->dns_status = DnsStatus::Verified;
            $domain->verified_at = now();
        } elseif (! $result->passed() && $domain->verified_at === null) {
            $domain->dns_status = DnsStatus::Pending;
        }

        if ($domain->dns_status === DnsStatus::Verified) {
            $this->syncCertificate($domain);
        }

        $domain->last_check = $result->jsonSerialize();
        $domain->last_checked_at = now();
        $domain->save();

        return $result;
    }

    public function makePrimary(TenantDomain $domain): void
    {
        if (! $domain->isRoutable() || $domain->ssl_status !== SslStatus::Active) {
            throw DomainError::notReady($domain->hostname);
        }

        $this->db->transaction(function () use ($domain): void {
            TenantDomain::query()
                ->where('tenant_id', $domain->tenant_id)
                ->where('is_primary', true)
                ->whereKeyNot($domain->getKey())
                ->get()
                ->each(fn (TenantDomain $other) => $other->update(['is_primary' => false]));

            $domain->update(['is_primary' => true]);
        });
    }

    public function remove(TenantDomain $domain): void
    {
        if ($domain->type === DomainType::Subdomain) {
            throw DomainError::cannotRemoveSubdomain();
        }

        if ($domain->is_primary) {
            throw DomainError::cannotRemovePrimary();
        }

        if ($domain->provider_ref !== null) {
            $this->edge->remove($domain->provider_ref);
        }

        $domain->delete();
    }

    /** @return list<array{type: string, name: string, expected: string, found: list<string>, ok: bool}> */
    private function expected(TenantDomain $domain, bool $withLookups): array
    {
        $edge = Hostname::normalise((string) config('platform.domains.edge_hostname'));

        /** @var list<string> $edgeIps */
        $edgeIps = config('platform.domains.edge_ips', []);
        $challenge = config('platform.domains.challenge_label').'.'.$domain->hostname;

        $records = [];

        $cname = $withLookups ? $this->dns->lookup($domain->hostname, 'CNAME') : [];
        $routeOk = in_array($edge, $cname, true);
        $found = $cname;

        if (! $routeOk && $withLookups && $edgeIps !== []) {
            $a = $this->dns->lookup($domain->hostname, 'A');
            $routeOk = array_intersect($a, $edgeIps) !== [];
            $found = array_values(array_unique([...$cname, ...$a]));
        }

        $records[] = ['type' => 'CNAME', 'name' => $domain->hostname, 'expected' => $edge, 'found' => $found, 'ok' => $routeOk];

        $txt = $withLookups ? $this->dns->lookup($challenge, 'TXT') : [];
        $records[] = [
            'type' => 'TXT',
            'name' => $challenge,
            'expected' => $domain->verification_token,
            'found' => $txt,
            'ok' => in_array($domain->verification_token, $txt, true),
        ];

        return $records;
    }

    private function syncCertificate(TenantDomain $domain): void
    {
        try {
            if ($domain->provider_ref === null) {
                $domain->provider_ref = $this->edge->register($domain->hostname);
                $domain->ssl_status = SslStatus::Issuing;
            }

            $status = $this->edge->status($domain->hostname, $domain->provider_ref);
            $domain->ssl_status = $status->ssl;
        } catch (Throwable $e) {
            // Edge outages must not break the set-up screen; the next check retries.
            Log::warning('custom-hostname.sync_failed', ['domain' => $domain->id, 'error' => $e->getMessage()]);
        }
    }

    private function insert(TenantDomain $domain, string $hostname): TenantDomain
    {
        try {
            // Savepoint, so a duplicate does not abort an outer transaction.
            $this->db->transaction(fn () => $domain->save());
        } catch (UniqueConstraintViolationException) {
            throw DomainError::taken($hostname);
        }

        return $domain;
    }

    private function token(): string
    {
        return 'skills-verify='.Str::lower(Str::random(32));
    }
}
