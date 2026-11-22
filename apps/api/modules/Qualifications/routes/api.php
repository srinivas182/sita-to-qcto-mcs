<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\Qualifications\Http\Controllers\Admin\QualificationAdminController;
use Modules\Qualifications\Http\Controllers\ProgrammeController;

$secured = ['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class];

// Catalogue editors (platform).
Route::middleware([...$secured, 'platform:platform.catalogue.manage'])->prefix('admin')->group(function (): void {
    Route::get('qualifications', [QualificationAdminController::class, 'index']);
    Route::post('qualifications', [QualificationAdminController::class, 'store']);
    Route::get('qualifications/{qualification}', [QualificationAdminController::class, 'show'])->whereUlid('qualification');
    Route::patch('qualifications/{qualification}', [QualificationAdminController::class, 'update'])->whereUlid('qualification');
    Route::post('qualifications/{qualification}/modules', [QualificationAdminController::class, 'importModules'])->whereUlid('qualification');
    Route::post('qualifications/{qualification}/publish', [QualificationAdminController::class, 'publish'])->whereUlid('qualification');
    Route::post('qualifications/{qualification}/withdraw', [QualificationAdminController::class, 'withdraw'])->whereUlid('qualification');
    Route::get('catalogue-suggestions', [QualificationAdminController::class, 'suggestions']);
    Route::post('catalogue-suggestions/{suggestion}/decide', [QualificationAdminController::class, 'decide'])->whereUlid('suggestion');
});

// Inside a provider.
Route::middleware($secured)->group(function (): void {
    Route::get('catalogue', [ProgrammeController::class, 'catalogue']);
    Route::get('catalogue/{qualification}', [ProgrammeController::class, 'qualification'])->whereUlid('qualification');
    Route::get('catalogue-suggestions', [ProgrammeController::class, 'suggestions']);
    Route::post('catalogue-suggestions', [ProgrammeController::class, 'suggest'])->middleware('throttle:20,60');
    Route::get('programmes', [ProgrammeController::class, 'index']);
    Route::post('programmes', [ProgrammeController::class, 'store']);
    Route::patch('programmes/{programme}', [ProgrammeController::class, 'update'])->whereUlid('programme');
    Route::get('centres/{centre}/qualifications', [ProgrammeController::class, 'centreScope'])->whereUlid('centre');
    Route::put('centres/{centre}/qualifications', [ProgrammeController::class, 'setCentreScope'])->whereUlid('centre');
});
