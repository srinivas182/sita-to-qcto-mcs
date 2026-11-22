<?php

declare(strict_types=1);

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Support\Money;
use Modules\Tenancy\Models\Tenant;

final class PaymentFailed extends BillingNotification
{
    public function __construct(Tenant $tenant, public readonly Payment $payment, public readonly Invoice $invoice)
    {
        parent::__construct($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment not completed for invoice '.($this->invoice->number))
            ->greeting("Hello {$this->tenant->trading_name},")
            ->line('A payment of '.Money::format($this->payment->amount_cents).' did not go through'.($this->payment->failure_message ? ': '.$this->payment->failure_message : '.'))
            ->line('Nothing has changed in your account. You can pay with another card, Instant EFT or EFT.')
            ->action('Pay now', $this->billingUrl());
    }
}
