<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('session_id')->constrained('class_sessions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 10); // present, late, left_early, absent, excused
            $t->timestampTz('checked_in_at')->nullable();
            $t->timestampTz('checked_out_at')->nullable();
            $t->unsignedSmallInteger('minutes_late')->default(0);
            $t->string('method', 12); // qr, facilitator, note
            $t->boolean('offline')->default(false);
            // Location is kept only as a distance from the centre and a verdict, never coordinates.
            $t->unsignedInteger('distance_m')->nullable();
            $t->boolean('location_ok')->nullable();
            $t->boolean('needs_review')->default(false);
            $t->string('review_reason', 200)->nullable();
            $t->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reason', 300)->nullable();
            $t->foreignUlid('note_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamps();
            $t->unique(['session_id', 'user_id']);
            $t->index(['tenant_id', 'user_id']);
        });

        Schema::create('delivery_settings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('radius_m')->default(200);
            $t->unsignedSmallInteger('late_after_minutes')->default(10);
            $t->unsignedTinyInteger('threshold_pct')->default(80);
            $t->unsignedSmallInteger('offline_sync_hours')->default(72);
            $t->timestamps();
        });

        foreach (['attendances', 'delivery_settings'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_settings');
        Schema::dropIfExists('attendances');
    }
};
