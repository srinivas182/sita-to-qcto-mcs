<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cohorts\Http\Controllers\CohortController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->prefix('cohorts')->group(function (): void {
    Route::get('/', [CohortController::class, 'index']);
    Route::post('/', [CohortController::class, 'store']);
    Route::get('{cohort}', [CohortController::class, 'show'])->whereUlid('cohort');
    Route::patch('{cohort}', [CohortController::class, 'update'])->whereUlid('cohort');
    Route::get('{cohort}/readiness', [CohortController::class, 'readiness'])->whereUlid('cohort');
    Route::get('{cohort}/options', [CohortController::class, 'options'])->whereUlid('cohort');
    Route::put('{cohort}/staff', [CohortController::class, 'staff'])->whereUlid('cohort');
    Route::put('{cohort}/placements', [CohortController::class, 'placements'])->whereUlid('cohort');
    Route::post('{cohort}/open', [CohortController::class, 'open'])->whereUlid('cohort');
    Route::post('{cohort}/{outcome}', [CohortController::class, 'finish'])->whereUlid('cohort')->whereIn('outcome', ['close', 'cancel']);
});
