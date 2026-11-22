<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\Settings\Http\Controllers\Admin\CompanyController;
use Modules\Settings\Http\Controllers\Admin\IntegrationController;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class, 'platform:platform.settings.manage'])
    ->prefix('admin/settings')
    ->group(function (): void {
        Route::get('company', [CompanyController::class, 'show']);
        Route::put('company', [CompanyController::class, 'update']);

        Route::get('integrations', [IntegrationController::class, 'index']);
        Route::put('integrations/order', [IntegrationController::class, 'reorder']);
        Route::put('integrations/{driver}', [IntegrationController::class, 'save'])->where('driver', '[a-z]+\.[a-z0-9_]+');
        Route::post('integrations/{integration}/enable', [IntegrationController::class, 'enable'])->whereUlid('integration');
        Route::post('integrations/{integration}/disable', [IntegrationController::class, 'disable'])->whereUlid('integration');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'test'])->whereUlid('integration');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->whereUlid('integration');
    });
