<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\Admin\BillingAdminController;
use Modules\Billing\Http\Controllers\BillingController;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\PackageController;
use Modules\Billing\Http\Controllers\WebhookController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

// Public.
Route::get('packages', PackageController::class);
Route::post('webhooks/payments/{driver}', WebhookController::class)
    ->where('driver', 'payment\.[a-z0-9_]+')
    ->middleware('throttle:120,1');

$secured = ['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class];

// Inside a training provider.
Route::middleware($secured)->group(function (): void {
    Route::get('billing', [BillingController::class, 'show']);
    Route::put('billing/profile', [BillingController::class, 'profile']);
    Route::post('billing/subscribe', [BillingController::class, 'subscribe']);
    Route::post('billing/change', [BillingController::class, 'change']);
    Route::post('billing/cancel', [BillingController::class, 'cancel']);
    Route::post('billing/resume', [BillingController::class, 'resume']);
    Route::put('billing/collection', [BillingController::class, 'collection']);
    Route::delete('billing/payment-method', [BillingController::class, 'removeCard']);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereUlid('invoice');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereUlid('invoice');
    Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->whereUlid('invoice')->middleware('throttle:20,1');
    Route::get('credit-notes/{note}/pdf', [InvoiceController::class, 'creditNotePdf'])->whereUlid('note');
    Route::get('payments/{reference}', [InvoiceController::class, 'payment'])->where('reference', 'PAY-[A-Z0-9]{26}');
});

// Super Admin.
Route::middleware([...$secured, 'platform:platform.billing.manage'])->prefix('admin/billing')->group(function (): void {
    Route::get('overview', [BillingAdminController::class, 'overview']);
    Route::get('packages', [BillingAdminController::class, 'packages']);
    Route::post('packages', [BillingAdminController::class, 'storePackage']);
    Route::patch('packages/{package}', [BillingAdminController::class, 'updatePackage'])->whereUlid('package');
    Route::get('subscriptions', [BillingAdminController::class, 'subscriptions']);
    Route::get('invoices', [BillingAdminController::class, 'invoices']);
    Route::get('invoices/{invoice}/pdf', [BillingAdminController::class, 'pdf'])->whereUlid('invoice');
    Route::post('invoices/{invoice}/eft', [BillingAdminController::class, 'recordEft'])->whereUlid('invoice');
    Route::post('invoices/{invoice}/credit', [BillingAdminController::class, 'credit'])->whereUlid('invoice');
});
Route::middleware([...$secured, 'platform:platform.settings.manage'])->group(function (): void {
    Route::get('admin/settings/billing-rules', [BillingAdminController::class, 'rules']);
    Route::put('admin/settings/billing-rules', [BillingAdminController::class, 'updateRules']);
});
