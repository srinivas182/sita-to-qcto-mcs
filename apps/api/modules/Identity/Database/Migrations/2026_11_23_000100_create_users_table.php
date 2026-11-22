<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People are global: one person may train with several providers over a lifetime.
 * Visibility to a tenant comes from membership (see user_tenants migration).
 *
 * Identity numbers (SA ID / passport) are stored twice: a keyed hash (HMAC with the
 * identity pepper) for exact lookup and uniqueness, and the encrypted value for
 * display to authorised staff. Neither is ever logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254)->nullable();
            $table->string('phone_e164', 16)->nullable();
            $table->string('id_type', 16)->nullable();
            $table->string('id_country', 2)->nullable();
            $table->string('id_number_hash', 64)->nullable()->unique();
            $table->text('id_number')->nullable();
            $table->date('dob')->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('status', 16)->default('active');
            $table->string('password')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (lower(email)) WHERE email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_phone_unique ON users (phone_e164) WHERE phone_e164 IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
