<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\TimetableController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('cohorts/{cohort}/sessions', [TimetableController::class, 'index'])->whereUlid('cohort');
    Route::post('cohorts/{cohort}/sessions', [TimetableController::class, 'store'])->whereUlid('cohort');
    Route::patch('class-sessions/{session}', [TimetableController::class, 'update'])->whereUlid('session');
    Route::post('class-sessions/{session}/cancel', [TimetableController::class, 'cancel'])->whereUlid('session');
    Route::get('centres/{centre}/rooms', [TimetableController::class, 'rooms'])->whereUlid('centre');
    Route::post('centres/{centre}/rooms', [TimetableController::class, 'saveRoom'])->whereUlid('centre');
    Route::patch('centres/{centre}/rooms/{room}', [TimetableController::class, 'saveRoom'])->whereUlid('centre')->whereUlid('room');
    Route::get('me/timetable', [TimetableController::class, 'mine']);
});
