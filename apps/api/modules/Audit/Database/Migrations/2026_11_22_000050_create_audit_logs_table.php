<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Partitions;
use Modules\Support\Database\Rls;

/**
 * Append-only audit trail, range-partitioned by month on created_at.
 *
 * - A DEFAULT partition catches rows if a monthly partition is ever missing;
 *   `audit:partitions` (scheduled) keeps the coming months created in advance.
 * - A trigger rejects UPDATE and DELETE so history cannot be rewritten; retention
 *   works by detaching/dropping whole old partitions.
 * - RLS: a tenant sees its own rows; platform rows (tenant_id null) only in the
 *   platform context.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id char(26) NOT NULL,
                tenant_id char(26) NULL,
                actor_id char(26) NULL,
                action varchar(100) NOT NULL,
                subject_type varchar(150) NULL,
                subject_id varchar(64) NULL,
                changes jsonb NULL,
                meta jsonb NULL,
                ip inet NULL,
                user_agent varchar(512) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
            SQL);

        DB::statement('CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT');
        Rls::protect('audit_logs_default');
        DB::statement('CREATE INDEX audit_logs_tenant_time ON audit_logs (tenant_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_subject ON audit_logs (subject_type, subject_id)');
        DB::statement('CREATE INDEX audit_logs_actor_time ON audit_logs (actor_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_action ON audit_logs (action)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (% rejected)', TG_OP USING ERRCODE = '42501';
            END
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_logs_no_change BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only()
            SQL);

        Rls::protect('audit_logs');

        Partitions::ensure(Carbon::now()->startOfMonth()->subMonth(), 4);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_logs CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_append_only()');
    }
};
