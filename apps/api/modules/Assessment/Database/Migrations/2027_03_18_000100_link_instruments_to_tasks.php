<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A summative test or assignment may be taken as an online task (Sprint 5); the assessor still decides.
        Schema::table('assessment_instruments', function (Blueprint $t): void {
            $t->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_instruments', fn (Blueprint $t) => $t->dropConstrainedForeignId('task_id'));
    }
};
