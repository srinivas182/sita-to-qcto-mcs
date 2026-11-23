<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Assessment\Http\Controllers\AssessmentController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('assessment-plan', [AssessmentController::class, 'plan']);
    Route::get('modules/{module}/instruments', [AssessmentController::class, 'instruments'])->whereUlid('module');
    Route::put('modules/{module}/instruments', [AssessmentController::class, 'saveInstruments'])->whereUlid('module');
    Route::get('assessment-settings', [AssessmentController::class, 'settings']);
    Route::put('assessment-settings', [AssessmentController::class, 'saveSettings']);
    Route::get('cohorts/{cohort}/results', [AssessmentController::class, 'cohort'])->whereUlid('cohort');
    Route::get('cohorts/{cohort}/results/{enrolment}', [AssessmentController::class, 'learner'])->whereUlid('cohort')->whereUlid('enrolment');
    Route::post('assessment-results', [AssessmentController::class, 'decide'])->middleware('throttle:240,60');
    Route::get('me/results', [AssessmentController::class, 'mine']);
    Route::get('me/assessing', [AssessmentController::class, 'assessing']);
});
