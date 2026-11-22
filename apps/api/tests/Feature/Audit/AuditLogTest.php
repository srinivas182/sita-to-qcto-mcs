<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Audit\AuditLogger;
use Modules\Audit\Models\AuditLog;
use Modules\Tenancy\Domains\DomainService;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\TenantDomain;

beforeEach(function (): void {
    $this->a = makeTenant(['code' => 'tenant-a']);
    $this->b = makeTenant(['code' => 'tenant-b']);
});

it('records model changes with the subject tenant', function (): void {
    $centre = asTenant($this->a, fn () => Centre::query()->create(['name' => 'Soweto']));
    asTenant($this->a, fn () => $centre->update(['name' => 'Soweto Campus']));

    $logs = asTenant($this->a, fn () => AuditLog::query()->where('subject_id', $centre->id)->orderBy('id')->get());

    expect($logs->pluck('action')->all())->toBe(['centre.created', 'centre.updated'])
        ->and($logs->pluck('tenant_id')->unique()->all())->toBe([$this->a->id])
        ->and($logs[1]->changes)->toEqual(['name' => ['from' => 'Soweto', 'to' => 'Soweto Campus']])
        ->and($logs[0]->changes)->not->toHaveKey('created_at');
});

it('redacts secrets', function (): void {
    $domain = asTenant($this->a, fn () => app(DomainService::class)->addCustomDomain($this->a, 'learn.example.co.za'));

    $log = asTenant($this->a, fn () => AuditLog::query()->where('subject_id', $domain->id)->firstOrFail());

    expect($log->changes['verification_token'])->toBe('[redacted]')
        ->and($log->changes['hostname'])->toBe('learn.example.co.za');
});

it('shows each tenant only its own trail and the platform everything', function (): void {
    asTenant($this->a, fn () => Centre::query()->create(['name' => 'A1']));
    asTenant($this->b, fn () => Centre::query()->create(['name' => 'B1']));
    asPlatform(fn () => app(AuditLogger::class)->record('platform.settings_changed'));

    $forA = asTenant($this->a, fn () => AuditLog::query()->pluck('tenant_id')->unique()->all());
    $all = asPlatform(fn () => AuditLog::query()->count());
    $platformOnly = asPlatform(fn () => AuditLog::query()->whereNull('tenant_id')->count());

    expect($forA)->toBe([$this->a->id])
        ->and($platformOnly)->toBeGreaterThanOrEqual(1)
        ->and($all)->toBeGreaterThan(count($forA));
});

it('cannot write an entry for another tenant', function (): void {
    asTenant($this->a, fn () => DB::transaction(fn () => app(AuditLogger::class)->record('forged', tenantId: $this->b->id)));
})->throws(QueryException::class, 'row-level security');

it('is append-only, even for raw SQL in the platform context', function (string $sql): void {
    asPlatform(function () use ($sql): void {
        app(AuditLogger::class)->record('platform.test');
        DB::transaction(fn () => DB::statement($sql));
    });
})->with([
    'update' => "update audit_logs set action = 'tampered'",
    'delete' => 'delete from audit_logs',
])->throws(QueryException::class, 'append-only');

it('refuses to change an entry through the model', function (): void {
    $entry = asPlatform(fn () => app(AuditLogger::class)->record('platform.test'));
    $entry->action = 'tampered';

    $entry->save();
})->throws(LogicException::class, 'immutable');

it('records platform-context writes as such', function (): void {
    $domain = asPlatform(fn () => TenantDomain::factory()->create(['tenant_id' => $this->a->id]));

    $log = asTenant($this->a, fn () => AuditLog::query()->where('subject_id', $domain->id)->firstOrFail());

    expect($log->tenant_id)->toBe($this->a->id)->and($log->meta['platform'] ?? null)->toBeTrue();
});

it('lands entries in the monthly partition', function (): void {
    $entry = asPlatform(fn () => app(AuditLogger::class)->record('platform.test'));

    $partition = asPlatform(fn () => DB::selectOne('select tableoid::regclass::text as p from audit_logs where id = ?', [$entry->id])->p);

    expect($partition)->toBe('audit_logs_'.Carbon::now()->format('Y_m'));
});

it('prepares future partitions idempotently', function (): void {
    Artisan::call('audit:partitions', ['--months' => 6]);
    Artisan::call('audit:partitions', ['--months' => 6]);

    $name = 'audit_logs_'.Carbon::now()->startOfMonth()->addMonthsNoOverflow(6)->format('Y_m');

    expect(DB::selectOne('select to_regclass(?) as r', [$name])->r)->toBe($name)
        ->and(Artisan::output())->toContain('already in place');
});

it('can be switched off for bulk work', function (): void {
    app(AuditLogger::class)->withoutAuditing(fn () => asTenant($this->a, fn () => Centre::query()->create(['name' => 'Bulk'])));

    expect(asTenant($this->a, fn () => AuditLog::query()->where('action', 'centre.created')->count()))->toBe(0);
});
