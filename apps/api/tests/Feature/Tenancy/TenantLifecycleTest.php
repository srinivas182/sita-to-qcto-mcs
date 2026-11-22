<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Modules\Audit\Models\AuditLog;
use Modules\Tenancy\Cells\CellPlacement;
use Modules\Tenancy\Enums\CellStatus;
use Modules\Tenancy\Enums\TenantStatus;
use Modules\Tenancy\Events\TenantRegistered;
use Modules\Tenancy\Events\TenantStatusChanged;
use Modules\Tenancy\Exceptions\TenantError;
use Modules\Tenancy\Models\Cell;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantService;

beforeEach(function (): void {
    config(['platform.base_domain' => 'skills.test']);
    $this->service = app(TenantService::class);

    asPlatform(function (): void {
        Cell::factory()->create(['id' => 'cell-1', 'capacity_tenants' => 2]);
        Cell::factory()->create(['id' => 'cell-2', 'capacity_tenants' => 2]);
    });
});

it('registers a training provider as pending with its subdomain', function (): void {
    Event::fake([TenantRegistered::class]);

    $tenant = $this->service->register([
        'code' => ' TYM ',
        'legal_name' => 'Training Young Minds (Pty) Ltd',
        'trading_name' => 'Training Young Minds',
        'branding' => ['primary' => '#4338ca'],
    ]);

    $domains = asPlatform(fn () => $tenant->domains()->get());

    expect($tenant->code)->toBe('tym')
        ->and($tenant->status)->toBe(TenantStatus::Pending)
        ->and($tenant->branding)->toBe(['primary' => '#4338ca'])
        ->and($domains->pluck('hostname')->all())->toBe(['tym.skills.test'])
        ->and($domains->first()?->is_primary)->toBeTrue();

    Event::assertDispatched(TenantRegistered::class);
});

it('places new tenants in the least-loaded shared cell', function (): void {
    asPlatform(fn () => Cell::factory()->create(['id' => 'cell-big', 'dedicated' => true, 'capacity_tenants' => 1000]));

    $first = $this->service->register(['code' => 'one', 'legal_name' => 'One']);
    $second = $this->service->register(['code' => 'two', 'legal_name' => 'Two']);
    $third = $this->service->register(['code' => 'three', 'legal_name' => 'Three']);

    expect([$first->cell_id, $second->cell_id])->toEqualCanonicalizing(['cell-1', 'cell-2'])
        ->and($third->cell_id)->not->toBe('cell-big');

    $this->service->register(['code' => 'four', 'legal_name' => 'Four']);

    expect(fn () => $this->service->register(['code' => 'five', 'legal_name' => 'Five']))
        ->toThrow(TenantError::class, 'No cell');

    $overview = collect(app(CellPlacement::class)->overview())->keyBy('id');
    expect($overview['cell-1']['tenants'])->toBe(2)->and($overview['cell-1']['load'])->toBe(1.0);
});

it('skips cells that are full or draining', function (): void {
    asPlatform(fn () => Cell::query()->whereKey('cell-1')->update(['status' => CellStatus::Draining]));

    expect($this->service->register(['code' => 'tym', 'legal_name' => 'TYM'])->cell_id)->toBe('cell-2');
});

it('rejects bad, reserved and duplicate codes', function (string $code, string $reason): void {
    $this->service->register(['code' => 'taken', 'legal_name' => 'Existing']);

    try {
        $this->service->register(['code' => $code, 'legal_name' => 'Applicant']);
        $this->fail('Expected TenantError');
    } catch (TenantError $e) {
        expect($e->reason)->toBe($reason);
    }
})->with([
    ['a', 'tenant_code_invalid'],
    ['with space', 'tenant_code_invalid'],
    ['double--hyphen', 'tenant_code_invalid'],
    ['-start', 'tenant_code_invalid'],
    ['admin', 'tenant_code_reserved'],
    ['www', 'tenant_code_reserved'],
    ['taken', 'tenant_code_taken'],
]);

it('moves through the approval lifecycle and records each step', function (): void {
    Event::fake([TenantStatusChanged::class]);
    $tenant = $this->service->register(['code' => 'tym', 'legal_name' => 'TYM']);

    $this->service->verify($tenant);
    $this->service->approve($tenant);
    expect($tenant->status)->toBe(TenantStatus::Active)->and($tenant->approved_at)->not->toBeNull();

    $this->service->suspend($tenant, 'Accreditation lapsed');
    expect($tenant->status)->toBe(TenantStatus::Suspended)->and($tenant->suspension_reason)->toBe('Accreditation lapsed');

    $this->service->reactivate($tenant);
    expect($tenant->status)->toBe(TenantStatus::Active)->and($tenant->suspended_at)->toBeNull();

    Event::assertDispatchedTimes(TenantStatusChanged::class, 4);

    $statuses = asPlatform(fn () => AuditLog::query()
        ->where('subject_id', $tenant->id)
        ->where('action', 'tenant.updated')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->map(fn (AuditLog $log) => $log->changes['status']['to'] ?? null)
        ->all());

    expect($statuses)->toBe(['verified', 'active', 'suspended', 'active']);
});

it('refuses invalid transitions', function (): void {
    $tenant = $this->service->register(['code' => 'tym', 'legal_name' => 'TYM']);
    $this->service->approve($tenant);

    $this->service->verify($tenant);
})->throws(TenantError::class, 'cannot become verified');

it('moves a tenant to another cell', function (): void {
    $tenant = $this->service->register(['code' => 'tym', 'legal_name' => 'TYM'], asPlatform(fn () => Cell::query()->findOrFail('cell-1')));
    $this->service->placeInCell($tenant, asPlatform(fn () => Cell::query()->findOrFail('cell-2')));

    expect(asPlatform(fn () => Tenant::query()->findOrFail($tenant->id)->cell_id))->toBe('cell-2');
});
