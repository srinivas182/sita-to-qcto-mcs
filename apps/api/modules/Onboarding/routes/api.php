<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\Onboarding\Http\Controllers\Admin\ApplicationAdminController;
use Modules\Onboarding\Http\Controllers\ApplicationController;

// Public, on the platform's own site.
Route::middleware('throttle:applications')->group(function (): void {
    Route::post('applications', [ApplicationController::class, 'start']);
    Route::post('applications/resume', [ApplicationController::class, 'resume']);
    Route::post('applications/resume/verify', [ApplicationController::class, 'resumeVerify']);
});
Route::get('applications/code-available', [ApplicationController::class, 'codeAvailable'])->middleware('throttle:60,1');

Route::middleware('throttle:120,1')->prefix('applications/{application}')->whereUlid('application')->group(function (): void {
    Route::get('/', [ApplicationController::class, 'show']);
    Route::patch('/', [ApplicationController::class, 'update']);
    Route::post('email-code', [ApplicationController::class, 'resendCode'])->middleware('throttle:5,10');
    Route::post('verify-email', [ApplicationController::class, 'verifyEmail']);
    Route::post('consent', [ApplicationController::class, 'consent']);
    Route::post('files', [ApplicationController::class, 'attach']);
    Route::put('files/{file}/content', [ApplicationController::class, 'upload'])->whereUlid('file');
    Route::post('files/{file}/complete', [ApplicationController::class, 'complete'])->whereUlid('file');
    Route::delete('files/{file}', [ApplicationController::class, 'detach'])->whereUlid('file');
    Route::post('submit', [ApplicationController::class, 'submit']);
});

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class, 'platform:platform.tenants.manage'])
    ->prefix('admin/applications')->group(function (): void {
        Route::get('/', [ApplicationAdminController::class, 'index']);
        Route::get('{application}', [ApplicationAdminController::class, 'show'])->whereUlid('application');
        Route::put('{application}/review', [ApplicationAdminController::class, 'review'])->whereUlid('application');
        Route::post('{application}/approve', [ApplicationAdminController::class, 'approve'])->whereUlid('application');
        Route::post('{application}/request-info', [ApplicationAdminController::class, 'requestInfo'])->whereUlid('application');
        Route::post('{application}/decline', [ApplicationAdminController::class, 'decline'])->whereUlid('application');
        Route::get('{application}/files/{file}', [ApplicationAdminController::class, 'file'])->whereUlid('application')->whereUlid('file');
    });
