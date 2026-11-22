<?php

declare(strict_types=1);

/*
 | Billing defaults. The Super Admin can change the collection rules in
 | Settings → Billing rules (stored in platform_settings.billing); these values are
 | used until then.
 */

return [
    'currency' => 'ZAR',

    // Public base URL gateways send webhooks to (defaults to APP_URL).
    'webhook_base' => env('BILLING_WEBHOOK_URL', env('APP_URL')),

    'rules' => [
        'trial_days' => 14,
        // Days an invoice is payable after it is issued.
        'invoice_due_days' => 7,
        // Automatic card retries, in days after the due date.
        'retry_days' => [1, 3, 7],
        // Overdue days before the provider becomes read-only, then suspended.
        'read_only_after_days' => 7,
        'suspend_after_days' => 21,
        // Reminder before a trial ends, in days.
        'trial_reminder_days' => 3,
    ],

    // Paths a read-only or suspended provider can still use (sign-in, billing, profile).
    'always_allowed' => ['v1/auth/*', 'v1/me', 'v1/me/*', 'v1/branding', 'v1/billing', 'v1/billing/*', 'v1/invoices', 'v1/invoices/*', 'v1/payments/*', 'v1/images/*'],
];
