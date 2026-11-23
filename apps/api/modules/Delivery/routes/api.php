<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\AttendanceController;
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

    Route::get('class-sessions/{session}/qr', [AttendanceController::class, 'qr'])->whereUlid('session');
    Route::get('class-sessions/{session}/attendance', [AttendanceController::class, 'roster'])->whereUlid('session');
    Route::post('class-sessions/{session}/attendance', [AttendanceController::class, 'mark'])->whereUlid('session');
    Route::post('attendances/{attendance}/review', [AttendanceController::class, 'review'])->whereUlid('attendance');
    Route::get('cohorts/{cohort}/attendance', [AttendanceController::class, 'report'])->whereUlid('cohort');
    Route::get('delivery-settings', [AttendanceController::class, 'settings']);
    Route::patch('delivery-settings', [AttendanceController::class, 'updateSettings']);
    Route::post('me/check-ins', [AttendanceController::class, 'checkIn'])->middleware('throttle:30,1');
    Route::post('me/class-sessions/{session}/note', [AttendanceController::class, 'note'])->whereUlid('session')->middleware('throttle:20,60');
});
