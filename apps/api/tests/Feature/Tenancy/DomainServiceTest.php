<?php

declare(strict_types=1);

use Modules\Tenancy\Domains\CustomHostnames\CustomHostnameProvider;
use Modules\Tenancy\Domains\CustomHostnames\HostnameStatus;
use Modules\Tenancy\Domains\DnsResolver;
use Modules\Tenancy\Domains\DomainService;
use Modules\Tenancy\Enums\DnsStatus;
use Modules\Tenancy\Enums\DomainType;
use Modules\Tenancy\Enums\SslStatus;
use Modules\Tenancy\Exceptions\DomainError;
use Modules\Tenancy\Models\TenantDomain;
use Tests\Support\FakeDns;

beforeEach(function (): void {
    config([
        'platform.base_domain' => 'skills.test',
        'platform.domains.edge_hostname' => 'edge.skills.test',
    ]);

    $this->dns = new FakeDns;
    $this->app->instance(DnsResolver::class, $this->dns);

    $this->edge = new class implements CustomHostnameProvider
    {
        public array $registered = [];

        public array $removed = [];

        public SslStatus $ssl = SslStatus::Issuing;

        public function register(string $hostname): string
        {
            $this->registered[] = $hostname;

            return 'cf-'.count($this->registered);
        }

        public function status(string $hostname, string $reference): HostnameStatus
        {
            return new HostnameStatus($this->ssl);
        }

        public function remove(string $reference): void
        {
            $this->removed[] = $reference;
        }
    };
    $this->app->instance(CustomHostnameProvider::class, $this->edge);

    $this->tenant = makeTenant(['code' => 'tym']);
    $this->service = app(DomainService::class);
    $this->owner = fn (Closure $cb) => asTenant($this->tenant, $cb);
});

it('gives every tenant a verified primary subdomain', function (): void {
    $domain = ($this->owner)(fn () => $this->service->addSubdomain($this->tenant));

    expect($domain->hostname)->toBe('tym.skills.test')
        ->and($domain->type)->toBe(DomainType::Subdomain)
        ->and($domain->isRoutable())->toBeTrue()
        ->and($domain->ssl_status)->toBe(SslStatus::Active)
        ->and($domain->is_primary)->toBeTrue();
});

it('walks a custom domain from DNS records to an active certificate', function (): void {
    $domain = ($this->owner)(fn () => $this->service->addCustomDomain($this->tenant, ' Learn.TrainingYoungMinds.co.za. '));

    expect($domain->hostname)->toBe('learn.trainingyoungminds.co.za')
        ->and($domain->dns_status)->toBe(DnsStatus::Pending);

    $instructions = $this->service->instructions($domain)->jsonSerialize();
    expect($instructions['records'])->toHaveCount(2)
        ->and($instructions['records'][0])->toMatchArray(['type' => 'CNAME', 'expected' => 'edge.skills.test'])
        ->and($instructions['records'][1])->toMatchArray(['type' => 'TXT', 'name' => '_skills-challenge.learn.trainingyoungminds.co.za']);

    // 1. Nothing published yet.
    $check = ($this->owner)(fn () => $this->service->check($domain));
    expect($check->passed())->toBeFalse()->and($domain->dns_status)->toBe(DnsStatus::Pending)
        ->and($this->edge->registered)->toBe([]);

    // 2. Only the CNAME.
    $this->dns->set('learn.trainingyoungminds.co.za', 'CNAME', ['edge.skills.test']);
    $check = ($this->owner)(fn () => $this->service->check($domain));
    expect($check->records[0]['ok'])->toBeTrue()->and($check->records[1]['ok'])->toBeFalse();

    // 3. Both records → verified and certificate requested.
    $this->dns->set('_skills-challenge.learn.trainingyoungminds.co.za', 'TXT', [$domain->verification_token]);
    $check = ($this->owner)(fn () => $this->service->check($domain));
    expect($check->passed())->toBeTrue()
        ->and($domain->dns_status)->toBe(DnsStatus::Verified)
        ->and($domain->verified_at)->not->toBeNull()
        ->and($domain->provider_ref)->toBe('cf-1')
        ->and($domain->ssl_status)->toBe(SslStatus::Issuing)
        ->and($domain->last_check['passed'])->toBeTrue();

    // 4. Certificate issued → can be primary.
    $this->edge->ssl = SslStatus::Active;
    ($this->owner)(fn () => $this->service->check($domain));
    expect($domain->ssl_status)->toBe(SslStatus::Active)->and($this->edge->registered)->toHaveCount(1);

    ($this->owner)(fn () => $this->service->makePrimary($domain));
    expect(($this->owner)(fn () => $domain->fresh()?->is_primary))->toBeTrue();
});

it('accepts apex domains that point A records at the edge IPs', function (): void {
    config(['platform.domains.edge_ips' => ['203.0.113.10']]);
    $domain = ($this->owner)(fn () => $this->service->addCustomDomain($this->tenant, 'trainingyoungminds.co.za'));

    $this->dns->set('trainingyoungminds.co.za', 'A', ['203.0.113.10'])
        ->set('_skills-challenge.trainingyoungminds.co.za', 'TXT', [$domain->verification_token]);

    expect(($this->owner)(fn () => $this->service->check($domain))->passed())->toBeTrue();
});

it('keeps a domain verified if DNS later wobbles', function (): void {
    $domain = ($this->owner)(fn () => $this->service->addCustomDomain($this->tenant, 'learn.example.co.za'));
    $this->dns->set('learn.example.co.za', 'CNAME', ['edge.skills.test'])
        ->set('_skills-challenge.learn.example.co.za', 'TXT', [$domain->verification_token]);
    ($this->owner)(fn () => $this->service->check($domain));

    $this->dns->set('learn.example.co.za', 'CNAME', []);
    $check = ($this->owner)(fn () => $this->service->check($domain));

    expect($check->passed())->toBeFalse()->and($domain->dns_status)->toBe(DnsStatus::Verified);
});

it('rejects invalid, platform and duplicate domains', function (string $host, string $reason): void {
    $other = makeTenant(['code' => 'rival']);
    asTenant($other, fn () => app(DomainService::class)->addCustomDomain($other, 'taken.co.za'));

    try {
        ($this->owner)(fn () => $this->service->addCustomDomain($this->tenant, $host));
        $this->fail('Expected a DomainError');
    } catch (DomainError $e) {
        expect($e->reason)->toBe($reason);
    }
})->with([
    ['not a domain', 'domain_invalid'],
    ['10.0.0.1', 'domain_invalid'],
    ['hijack.skills.test', 'domain_reserved'],
    ['taken.co.za', 'domain_taken'],
]);

it('limits the number of custom domains', function (): void {
    ($this->owner)(function (): void {
        foreach (range(1, DomainService::MAX_CUSTOM_DOMAINS) as $i) {
            $this->service->addCustomDomain($this->tenant, "site{$i}.example.co.za");
        }

        $this->service->addCustomDomain($this->tenant, 'one-more.example.co.za');
    });
})->throws(DomainError::class, 'up to 5');

it('will not make an unverified domain primary', function (): void {
    $domain = ($this->owner)(fn () => $this->service->addCustomDomain($this->tenant, 'learn.example.co.za'));

    ($this->owner)(fn () => $this->service->makePrimary($domain));
})->throws(DomainError::class, 'must pass DNS');

it('removes a custom domain at the edge and locks the subdomain', function (): void {
    $sub = ($this->owner)(fn () => $this->service->addSubdomain($this->tenant));
    $domain = ($this->owner)(fn () => $this->service->addCustomDomain($this->tenant, 'learn.example.co.za'));
    ($this->owner)(fn () => $domain->forceFill(['provider_ref' => 'cf-9'])->save());

    ($this->owner)(fn () => $this->service->remove($domain));
    expect($this->edge->removed)->toBe(['cf-9'])
        ->and(asPlatform(fn () => TenantDomain::query()->whereKey($domain->id)->exists()))->toBeFalse();

    expect(fn () => ($this->owner)(fn () => $this->service->remove($sub)))
        ->toThrow(DomainError::class, 'cannot be removed');
});

it('keeps the set-up screen working when the edge provider is down', function (): void {
    $this->app->instance(CustomHostnameProvider::class, new class implements CustomHostnameProvider
    {
        public function register(string $hostname): string
        {
            throw new RuntimeException('edge down');
        }

        public function status(string $hostname, string $reference): HostnameStatus
        {
            throw new RuntimeException('edge down');
        }

        public function remove(string $reference): void {}
    });
    $service = app(DomainService::class);

    $domain = ($this->owner)(fn () => $service->addCustomDomain($this->tenant, 'learn.example.co.za'));
    $this->dns->set('learn.example.co.za', 'CNAME', ['edge.skills.test'])
        ->set('_skills-challenge.learn.example.co.za', 'TXT', [$domain->verification_token]);

    $check = ($this->owner)(fn () => $service->check($domain));

    expect($check->passed())->toBeTrue()
        ->and($domain->dns_status)->toBe(DnsStatus::Verified)
        ->and($domain->ssl_status)->toBe(SslStatus::Pending)
        ->and($domain->provider_ref)->toBeNull();
});
