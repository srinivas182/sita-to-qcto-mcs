<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Employers\Http\Controllers\EmployerController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->prefix('employers')->group(function (): void {
    Route::get('/', [EmployerController::class, 'index']);
    Route::post('/', [EmployerController::class, 'store']);
    Route::get('{employer}', [EmployerController::class, 'show'])->whereUlid('employer');
    Route::patch('{employer}', [EmployerController::class, 'update'])->whereUlid('employer');
    Route::post('{employer}/sites', [EmployerController::class, 'storeSite'])->whereUlid('employer');
    Route::patch('{employer}/sites/{site}', [EmployerController::class, 'updateSite'])->whereUlid('employer')->whereUlid('site');
    Route::post('{employer}/mentors', [EmployerController::class, 'storeMentor'])->whereUlid('employer');
    Route::patch('{employer}/mentors/{mentor}', [EmployerController::class, 'updateMentor'])->whereUlid('employer')->whereUlid('mentor');
    Route::post('{employer}/members', [EmployerController::class, 'link'])->whereUlid('employer');
    Route::delete('{employer}/members/{user}', [EmployerController::class, 'unlink'])->whereUlid('employer')->whereUlid('user');
});
