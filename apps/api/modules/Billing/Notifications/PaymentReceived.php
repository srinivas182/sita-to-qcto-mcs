<?php

declare(strict_types=1);

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Support\Money;
use Modules\Tenancy\Models\Tenant;

final class PaymentReceived extends BillingNotification
{
    public function __construct(Tenant $tenant, public readonly Payment $payment, public readonly Invoice $invoice)
    {
        parent::__construct($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment received: '.Money::format($this->payment->amount_cents))
            ->greeting("Thank you, {$this->tenant->trading_name}.")
            ->line('We received '.Money::format($this->payment->amount_cents).' for invoice '.($this->invoice->number).'.')
            ->action('View billing', $this->billingUrl());
    }
}
