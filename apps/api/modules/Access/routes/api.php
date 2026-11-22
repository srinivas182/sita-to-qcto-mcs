<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\Admin\StaffController;
use Modules\Access\Http\Controllers\InvitationController;
use Modules\Access\Http\Controllers\RoleController;
use Modules\Access\Http\Controllers\SupportAccessController;
use Modules\Audit\Http\Controllers\AuditLogController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\Tenancy\Http\Controllers\Admin\TenantAdminController;
use Modules\Tenancy\Http\Controllers\CurrentTenantController;
use Modules\Tenancy\Http\Controllers\DomainController;

// Public, tenant-aware.
Route::get('branding', [CurrentTenantController::class, 'branding']);
Route::middleware('throttle:auth')->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show']);
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);
});

$secured = ['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class];

// Inside a training provider.
Route::middleware($secured)->group(function (): void {
    Route::get('tenant', [CurrentTenantController::class, 'show']);
    Route::patch('tenant/branding', [CurrentTenantController::class, 'updateBranding']);

    Route::get('tenants/{tenant}/domains', [DomainController::class, 'index']);
    Route::post('tenants/{tenant}/domains', [DomainController::class, 'store']);
    Route::post('tenants/{tenant}/domains/{domain}/verify', [DomainController::class, 'verify']);
    Route::get('tenants/{tenant}/domains/{domain}/status', [DomainController::class, 'status']);
    Route::post('tenants/{tenant}/domains/{domain}/primary', [DomainController::class, 'primary']);
    Route::delete('tenants/{tenant}/domains/{domain}', [DomainController::class, 'destroy']);

    Route::get('roles', [RoleController::class, 'roles']);
    Route::get('users', [RoleController::class, 'users']);
    Route::put('users/{user}/roles', [RoleController::class, 'sync']);

    Route::get('invitations', [InvitationController::class, 'index']);
    Route::post('tenants/{tenant}/invitations', [InvitationController::class, 'store']);
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->whereUlid('invitation');

    Route::get('support-access', [SupportAccessController::class, 'index']);
    Route::post('support-access/{grant}/approve', [SupportAccessController::class, 'approve']);
    Route::post('support-access/{grant}/deny', [SupportAccessController::class, 'deny']);
    Route::post('support-access/{grant}/revoke', [SupportAccessController::class, 'revoke']);

    Route::get('audit-logs', [AuditLogController::class, 'index']);
});

// Super Admin console (platform host, platform context).
Route::middleware($secured)->prefix('admin')->group(function (): void {
    Route::middleware('platform:platform.tenants.view')->group(function (): void {
        Route::get('overview', [TenantAdminController::class, 'overview']);
        Route::get('tenants', [TenantAdminController::class, 'index']);
        Route::get('tenants/{tenant}', [TenantAdminController::class, 'show']);
    });

    Route::middleware('platform:platform.tenants.manage')->group(function (): void {
        Route::post('tenants', [TenantAdminController::class, 'store']);
        Route::patch('tenants/{tenant}', [TenantAdminController::class, 'update']);
        Route::post('tenants/{tenant}/verify', [TenantAdminController::class, 'verify']);
        Route::post('tenants/{tenant}/approve', [TenantAdminController::class, 'approve']);
        Route::post('tenants/{tenant}/suspend', [TenantAdminController::class, 'suspend']);
        Route::post('tenants/{tenant}/reactivate', [TenantAdminController::class, 'reactivate']);
        Route::post('tenants/{tenant}/cell', [TenantAdminController::class, 'placeInCell']);
        Route::post('tenants/{tenant}/invitations', [StaffController::class, 'inviteToTenant']);

        Route::get('tenants/{tenant}/domains', [DomainController::class, 'index']);
        Route::post('tenants/{tenant}/domains', [DomainController::class, 'store']);
        Route::post('tenants/{tenant}/domains/{domain}/verify', [DomainController::class, 'verify']);
        Route::get('tenants/{tenant}/domains/{domain}/status', [DomainController::class, 'status']);
        Route::post('tenants/{tenant}/domains/{domain}/primary', [DomainController::class, 'primary']);
        Route::delete('tenants/{tenant}/domains/{domain}', [DomainController::class, 'destroy']);
    });

    Route::get('cells', [TenantAdminController::class, 'cells'])->middleware('platform:platform.cells.view');
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('platform:platform.audit.view');

    Route::middleware('platform:platform.support.request')->group(function (): void {
        Route::get('support-access', [SupportAccessController::class, 'mine']);
        Route::post('support-access', [SupportAccessController::class, 'request']);
        Route::post('support-access/{grant}/revoke', [SupportAccessController::class, 'revoke']);
    });

    Route::middleware('platform:platform.users.manage')->group(function (): void {
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::delete('staff/{user}/roles/{role}', [StaffController::class, 'destroy']);
    });
});
