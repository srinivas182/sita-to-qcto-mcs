<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Domains\DomainService;
use Modules\Tenancy\Enums\DnsStatus;
use Modules\Tenancy\Enums\TenantStatus;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\TenantDomain;
use Modules\Tenancy\Resolution\TenantResolver;
use Modules\Tenancy\Services\TenantService;

beforeEach(function (): void {
    config(['platform.base_domain' => 'skills.test']);

    Route::middleware('api')->prefix('v1')->get('_probe', function (Request $request) {
        $context = app(TenantContext::class);

        return [
            'tenant' => $context->tenant()?->code,
            'platform_host' => (bool) $request->attributes->get('tenancy.platform_host'),
            'centres' => DB::selectOne('select count(*) as n from centres')->n,
        ];
    });

    $this->tym = makeTenant(['code' => 'tym', 'trading_name' => 'Training Young Minds']);
    $this->other = makeTenant(['code' => 'other']);

    asPlatform(function (): void {
        $domains = app(DomainService::class);
        $domains->addSubdomain($this->tym);
        $domains->addSubdomain($this->other);

        TenantDomain::factory()->create(['tenant_id' => $this->tym->id, 'hostname' => 'learn.trainingyoungminds.co.za']);
        TenantDomain::factory()->create([
            'tenant_id' => $this->tym->id,
            'hostname' => 'pending.trainingyoungminds.co.za',
            'dns_status' => DnsStatus::Pending,
            'verified_at' => null,
        ]);

        Centre::factory()->create(['tenant_id' => $this->tym->id]);
        Centre::factory()->count(2)->create(['tenant_id' => $this->other->id]);
    });
});

it('resolves a tenant from its platform subdomain', function (): void {
    $this->getJson('http://tym.skills.test/v1/_probe')
        ->assertOk()
        ->assertJson(['tenant' => 'tym', 'platform_host' => false, 'centres' => 1]);
});

it('resolves a tenant from its verified custom domain, ignoring case and port', function (): void {
    $this->getJson('http://LEARN.TrainingYoungMinds.co.za:8443/v1/_probe')
        ->assertOk()
        ->assertJson(['tenant' => 'tym', 'centres' => 1]);
});

it('does not route a custom domain before DNS is verified', function (): void {
    $this->getJson('http://pending.trainingyoungminds.co.za/v1/_probe')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'tenant_not_found');
});

it('returns 404 for unknown hosts', function (): void {
    $this->getJson('http://nobody.skills.test/v1/_probe')->assertNotFound();
    $this->getJson('http://random-site.co.za/v1/_probe')->assertNotFound();
});

it('blocks suspended tenants', function (): void {
    app(TenantService::class)->suspend($this->tym, 'Accreditation lapsed');

    $this->getJson('http://tym.skills.test/v1/_probe')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'tenant_suspended');
});

it('serves platform hosts without a tenant', function (): void {
    $this->getJson('http://api.skills.test/v1/_probe')
        ->assertOk()
        ->assertJson(['tenant' => null, 'platform_host' => true, 'centres' => 0]);
});

it('uses the X-Tenant header from the mobile app on platform hosts', function (): void {
    $this->getJson('http://api.skills.test/v1/_probe', ['X-Tenant' => 'TYM'])
        ->assertOk()
        ->assertJson(['tenant' => 'tym', 'centres' => 1]);

    $this->getJson('http://api.skills.test/v1/_probe', ['X-Tenant' => 'missing'])->assertNotFound();
});

it('lets the host win over the X-Tenant header on tenant hosts', function (): void {
    $this->getJson('http://tym.skills.test/v1/_probe', ['X-Tenant' => 'other'])
        ->assertOk()
        ->assertJson(['tenant' => 'tym', 'centres' => 1]);
});

it('clears the context when the request ends', function (): void {
    $this->getJson('http://tym.skills.test/v1/_probe')->assertOk();

    expect(app(TenantContext::class)->isEmpty())->toBeTrue()
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as t")->t)->toBe('');
});

it('resolves warm hosts from cache without touching the database', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->byHost('tym.skills.test');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $tenant = $resolver->byHost('tym.skills.test');
    $miss = $resolver->byHost('ghost.skills.test');
    $missAgain = $resolver->byHost('ghost.skills.test');

    $queries = collect(DB::getQueryLog())->pluck('query')->reject(fn (string $q) => str_contains($q, 'set_config'));

    expect($tenant?->code)->toBe('tym')
        ->and($tenant?->status)->toBe(TenantStatus::Active)
        ->and($miss)->toBeNull()
        ->and($missAgain)->toBeNull()
        ->and($queries)->toHaveCount(1); // only the first miss
});

it('drops cached resolution when a tenant is suspended or a domain removed', function (): void {
    $resolver = app(TenantResolver::class);

    expect($resolver->byHost('learn.trainingyoungminds.co.za')?->status)->toBe(TenantStatus::Active);

    app(TenantService::class)->suspend($this->tym, 'Review');
    expect($resolver->byHost('learn.trainingyoungminds.co.za')?->status)->toBe(TenantStatus::Suspended);

    asPlatform(fn () => TenantDomain::query()->where('hostname', 'learn.trainingyoungminds.co.za')->firstOrFail()->delete());
    expect($resolver->byHost('learn.trainingyoungminds.co.za'))->toBeNull();
});

it('rejects malformed host names without a lookup', function (string $host): void {
    expect(app(TenantResolver::class)->byHost($host))->toBeNull();
})->with(['127.0.0.1', 'localhost', '-bad-.co.za', str_repeat('a', 64).'.co.za', 'under_score.co.za']);
