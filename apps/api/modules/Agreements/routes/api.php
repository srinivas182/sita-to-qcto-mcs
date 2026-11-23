<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Agreements\Http\Controllers\AgreementController;
use Modules\Agreements\Http\Controllers\SigningController;
use Modules\Agreements\Http\Controllers\TemplateController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('agreements', [AgreementController::class, 'index']);
    Route::post('agreements', [AgreementController::class, 'store']);
    Route::get('agreements/{agreement}', [AgreementController::class, 'show'])->whereUlid('agreement');
    Route::get('agreements/{agreement}/document', [AgreementController::class, 'document'])->whereUlid('agreement');
    Route::post('agreements/{agreement}/cancel', [AgreementController::class, 'cancel'])->whereUlid('agreement');
    Route::post('agreements/{agreement}/signers/{signer}/resend', [AgreementController::class, 'resend'])->whereUlid('agreement')->whereUlid('signer');
    Route::get('agreement-settings', [AgreementController::class, 'settings']);
    Route::patch('agreement-settings', [AgreementController::class, 'updateSettings']);

    Route::get('agreement-templates', [TemplateController::class, 'index']);
    Route::post('agreement-templates', [TemplateController::class, 'store']);
    Route::get('agreement-templates/{template}', [TemplateController::class, 'show'])->whereUlid('template');
    Route::patch('agreement-templates/{template}', [TemplateController::class, 'update'])->whereUlid('template');
    Route::post('agreement-templates/{template}/publish', [TemplateController::class, 'publish'])->whereUlid('template');
    Route::post('agreement-templates/{template}/retire', [TemplateController::class, 'retire'])->whereUlid('template');
});

// Public, by link. Tokens are 48 random characters; verification codes are 10.
Route::middleware('throttle:signing')->group(function (): void {
    Route::get('sign/{token}', [SigningController::class, 'show'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('sign/{token}/code', [SigningController::class, 'code'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:6,10');
    Route::post('sign/{token}', [SigningController::class, 'sign'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('sign/{token}/decline', [SigningController::class, 'decline'])->where('token', '[A-Za-z0-9]{48}');
    Route::get('sign/{token}/document', [SigningController::class, 'document'])->where('token', '[A-Za-z0-9]{48}');
    Route::get('verify/{code}', [SigningController::class, 'verify'])->where('code', '[A-Za-z0-9]{10}');
});
