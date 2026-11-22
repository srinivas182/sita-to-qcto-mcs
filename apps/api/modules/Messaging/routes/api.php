<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\Messaging\Http\Controllers\Admin\MessageAdminController;
use Modules\Messaging\Http\Controllers\WebhookController;

Route::get('webhooks/messaging/{driver}', [WebhookController::class, 'verify'])->where('driver', '(sms|whatsapp)\.[a-z0-9_]+');
Route::post('webhooks/messaging/{driver}', [WebhookController::class, 'receive'])
    ->where('driver', '(sms|whatsapp)\.[a-z0-9_]+')->middleware('throttle:600,1');

$secured = ['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class];

Route::middleware([...$secured, 'platform:platform.settings.manage'])->group(function (): void {
    Route::get('admin/messages', [MessageAdminController::class, 'index']);
    Route::get('admin/messages/summary', [MessageAdminController::class, 'summary']);
    Route::post('admin/settings/integrations/{integration}/send-test', [MessageAdminController::class, 'sendTest'])
        ->whereUlid('integration')->middleware('throttle:10,1');
});
