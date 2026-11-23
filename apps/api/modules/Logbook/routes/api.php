<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\Logbook\Http\Controllers\LogbookController;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('logbook-rules', [LogbookController::class, 'index']);
    Route::get('modules/{module}/logbook-rules', [LogbookController::class, 'rules'])->whereUlid('module');
    Route::put('modules/{module}/logbook-rules', [LogbookController::class, 'saveRules'])->whereUlid('module');
    Route::get('cohorts/{cohort}/learner-placements', [LogbookController::class, 'placements'])->whereUlid('cohort');
    Route::post('cohorts/{cohort}/learner-placements', [LogbookController::class, 'place'])->whereUlid('cohort');
    Route::post('learner-placements/{placement}/end', [LogbookController::class, 'endPlacement'])->whereUlid('placement');
    Route::get('cohorts/{cohort}/logbook', [LogbookController::class, 'summary'])->whereUlid('cohort');
    Route::get('cohorts/{cohort}/logbook/{user}', [LogbookController::class, 'learner'])->whereUlid('cohort')->whereUlid('user');
    Route::get('me/logbook', [LogbookController::class, 'mine']);
    Route::post('me/logbook', [LogbookController::class, 'record'])->middleware('throttle:120,60');
    Route::post('me/logbook/{entry}/correct', [LogbookController::class, 'correct'])->whereUlid('entry')->middleware('throttle:60,60');
});
