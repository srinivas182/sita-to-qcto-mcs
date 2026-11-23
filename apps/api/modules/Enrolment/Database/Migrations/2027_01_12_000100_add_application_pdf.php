<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $t): void {
            $t->foreignUlid('pdf_file_id')->nullable()->constrained('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropConstrainedForeignId('pdf_file_id'));
    }
};
