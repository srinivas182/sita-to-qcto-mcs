<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenancy\Exceptions\CrossTenantWrite;
use Modules\Tenancy\Models\Centre;

/*
 | These tests prove isolation at the database itself, not only through Eloquent:
 | raw SQL in one tenant's context can neither read nor change another's rows.
 */

beforeEach(function (): void {
    $this->a = makeTenant(['code' => 'tenant-a']);
    $this->b = makeTenant(['code' => 'tenant-b']);

    asPlatform(function (): void {
        Centre::factory()->count(2)->create(['tenant_id' => $this->a->id]);
        Centre::factory()->count(3)->create(['tenant_id' => $this->b->id]);
    });
});

it('runs as a database role that cannot bypass row-level security', function (): void {
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
});

it('forces row-level security on every table that has a tenant_id column', function (): void {
    $unprotected = DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace and n.nspname = current_schema()
        join pg_attribute a on a.attrelid = c.oid and a.attname = 'tenant_id' and not a.attisdropped
        where c.relkind in ('r', 'p')
          and not (c.relrowsecurity and c.relforcerowsecurity
                   and exists (select 1 from pg_policy p where p.polrelid = c.oid))
        SQL);

    expect(array_column($unprotected, 'relname'))->toBe([]);
});

it('shows a tenant only its own rows, even through raw SQL', function (): void {
    asTenant($this->a, function (): void {
        expect(Centre::query()->count())->toBe(2)
            ->and(DB::selectOne('select count(*) as n from centres')->n)->toBe(2)
            ->and(DB::selectOne('select count(*) as n from centres where tenant_id = ?', [$this->b->id])->n)->toBe(0)
            ->and(DB::table('tenants')->pluck('id')->all())->toBe([$this->a->id]);
    });
});

it('shows nothing when no tenant context is set', function (): void {
    expect(DB::selectOne('select count(*) as n from centres')->n)->toBe(0)
        ->and(DB::selectOne('select count(*) as n from tenants')->n)->toBe(0)
        ->and(Centre::query()->count())->toBe(0);
});

it('shows everything in the platform context', function (): void {
    asPlatform(function (): void {
        expect(Centre::query()->count())->toBe(5)
            ->and(DB::selectOne('select count(*) as n from tenants')->n)->toBe(2);
    });
});

it('rejects a raw insert of another tenant row', function (): void {
    asTenant($this->a, function (): void {
        DB::transaction(function (): void {
            DB::insert(
                'insert into centres (id, tenant_id, name, status, created_at, updated_at) values (?, ?, ?, ?, now(), now())',
                [(string) Str::ulid(), $this->b->id, 'Intruder centre', 'active'],
            );
        });
    });
})->throws(QueryException::class, 'row-level security');

it('refuses an Eloquent write for another tenant before it reaches the database', function (): void {
    asTenant($this->a, fn () => Centre::query()->create(['tenant_id' => $this->b->id, 'name' => 'Intruder']));
})->throws(CrossTenantWrite::class);

it('fills the current tenant on create', function (): void {
    $centre = asTenant($this->a, fn () => Centre::query()->create(['name' => 'Soweto Campus']));

    expect($centre->tenant_id)->toBe($this->a->id);
});

it('cannot update or delete another tenant rows with raw SQL', function (): void {
    asTenant($this->a, function (): void {
        expect(DB::update('update centres set name = name || ? where tenant_id = ?', [' (x)', $this->b->id]))->toBe(0)
            ->and(DB::delete('delete from centres where tenant_id = ?', [$this->b->id]))->toBe(0)
            ->and(DB::delete('delete from tenants where id = ?', [$this->b->id]))->toBe(0);
    });

    asPlatform(fn () => expect(Centre::query()->where('tenant_id', $this->b->id)->count())->toBe(3));
});

it('cannot move its own row to another tenant', function (): void {
    asTenant($this->a, function (): void {
        DB::transaction(fn () => DB::update('update centres set tenant_id = ? where tenant_id = ?', [$this->b->id, $this->a->id]));
    });
})->throws(QueryException::class, 'row-level security');

it('restores the outer context after nested runs', function (): void {
    asTenant($this->a, function (): void {
        asPlatform(fn () => expect(Centre::query()->count())->toBe(5));
        asTenant($this->b, fn () => expect(Centre::query()->count())->toBe(3));

        expect(DB::selectOne('select count(*) as n from centres')->n)->toBe(2);
    });

    expect(DB::selectOne("select current_setting('app.tenant_id', true) as t")->t)->toBe('');
});

it('applies the context to a newly established connection', function (): void {
    config(['database.connections.pgsql_fresh' => config('database.connections.pgsql')]);

    asTenant($this->a, function (): void {
        $fresh = DB::connection('pgsql_fresh');

        expect($fresh->selectOne("select current_setting('app.tenant_id', true) as t")->t)->toBe($this->a->id);
    });
});

it('keeps tenant ids opaque ULIDs', function (): void {
    expect(Str::isUlid($this->a->id))->toBeTrue();
});
