<?php

declare(strict_types=1);

namespace Modules\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL row-level security helpers used by module migrations.
 *
 * Every tenant-owned table gets one policy, `tenant_isolation`, that allows a row
 * only when its tenant column matches the `app.tenant_id` session setting, or the
 * session is running in the explicit platform context (`app.context = platform`).
 * With no setting at all a session sees nothing, so a forgotten context fails closed.
 *
 * FORCE ROW LEVEL SECURITY makes the policy apply to the table owner as well; the
 * application role must not be a superuser or have BYPASSRLS (see ADR-003).
 */
final class Rls
{
    public static function predicate(string $column = 'tenant_id'): string
    {
        return "current_setting('app.context', true) = 'platform' "
            ."OR {$column} = nullif(current_setting('app.tenant_id', true), '')";
    }

    /** Enable forced RLS on a table and add the tenant isolation policy. */
    public static function protect(string $table, string $column = 'tenant_id'): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $predicate = self::predicate($column);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$predicate}) WITH CHECK ({$predicate})");
    }

    /**
     * Protect a table whose rows are visible through a custom condition (e.g. users,
     * visible to a tenant only when they are its members).
     */
    public static function protectWith(string $table, string $using, ?string $check = null): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $check ??= $using;

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$using}) WITH CHECK ({$check})");
    }

    /**
     * Rows with a NULL tenant are shared catalogue entries (e.g. system roles): every
     * context may read them, only the platform may write them.
     */
    public static function protectWithGlobalRows(string $table, string $column = 'tenant_id'): void
    {
        self::protectWith($table, "{$column} IS NULL OR ".self::predicate($column), self::predicate($column));
    }

    /** Reverse of protect(), for down() migrations. */
    public static function unprotect(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
}
