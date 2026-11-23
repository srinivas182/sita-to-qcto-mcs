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
        Schema::create('materials', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('qualification_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('module_id')->nullable()->constrained('qualification_modules')->nullOnDelete();
            $t->string('title', 150);
            $t->string('description', 1000)->nullable();
            $t->string('kind', 10); // document, video, image, link
            $t->foreignUlid('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->string('url', 500)->nullable();
            $t->foreignUlid('low_file_id')->nullable()->constrained('files')->nullOnDelete(); // low-data video
            $t->string('low_status', 10)->nullable(); // queued, ready, failed, none
            $t->unsignedInteger('duration_s')->nullable();
            $t->unsignedInteger('sort')->default(0);
            $t->string('status', 10)->default('draft'); // draft, published
            $t->unsignedInteger('version')->default(1);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'qualification_id', 'status']);
        });

        Schema::create('material_progress', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('material_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['material_id', 'user_id']);
        });

        foreach (['materials', 'material_progress'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_progress');
        Schema::dropIfExists('materials');
    }
};
