<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Exceptions\TenantUnavailable;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Services\TenantService;

final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<array{tenant: string|null, platform: bool, centres: int}> */
    public static array $runs = [];

    public function handle(TenantContext $context): void
    {
        self::$runs[] = [
            'tenant' => $context->tenant()?->code,
            'platform' => $context->isPlatform(),
            'centres' => (int) DB::selectOne('select count(*) as n from centres')->n,
        ];
    }
}

beforeEach(function (): void {
    RecordTenantJob::$runs = [];
    $this->a = makeTenant(['code' => 'tenant-a']);
    $this->b = makeTenant(['code' => 'tenant-b']);

    asPlatform(function (): void {
        Centre::factory()->create(['tenant_id' => $this->a->id]);
        Centre::factory()->count(2)->create(['tenant_id' => $this->b->id]);
    });
});

it('runs a job in the tenant it was dispatched for and restores the caller', function (): void {
    asTenant($this->b, function (): void {
        // Statement form: PendingDispatch dispatches when it goes out of scope.
        asTenant($this->a, function (): void {
            RecordTenantJob::dispatch();
        });

        expect(app(TenantContext::class)->tenant()?->code)->toBe('tenant-b')
            ->and(DB::selectOne('select count(*) as n from centres')->n)->toBe(2);
    });

    asPlatform(function (): void {
        RecordTenantJob::dispatch();
    });
    RecordTenantJob::dispatch();

    expect(RecordTenantJob::$runs)->toBe([
        ['tenant' => 'tenant-a', 'platform' => false, 'centres' => 1],
        ['tenant' => null, 'platform' => true, 'centres' => 3],
        ['tenant' => null, 'platform' => false, 'centres' => 0],
    ]);
});

it('does not run jobs for a suspended tenant', function (): void {
    $tenant = $this->a;
    app(TenantService::class)->suspend($tenant, 'Review');

    // The job was queued while the tenant was active; it is picked up after suspension.
    $error = null;

    asTenant($tenant, function () use (&$error): void {
        try {
            RecordTenantJob::dispatch();
        } catch (TenantUnavailable $e) {
            $error = $e;
        }

        expect(app(TenantContext::class)->tenant()?->code)->toBe('tenant-a');
    });

    expect($error)->toBeInstanceOf(TenantUnavailable::class)
        ->and(RecordTenantJob::$runs)->toBe([]);
});
