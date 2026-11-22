<?php

declare(strict_types=1);

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Support\Money;
use Modules\Tenancy\Models\Tenant;

final class InvoiceIssued extends BillingNotification
{
    public function __construct(Tenant $tenant, public readonly Invoice $invoice)
    {
        parent::__construct($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tax invoice {$this->invoice->number}: ".Money::format($this->invoice->total_cents))
            ->greeting("Hello {$this->tenant->trading_name},")
            ->line('Your tax invoice '.$this->invoice->number.' for '.Money::format($this->invoice->total_cents).' (incl. VAT) is ready.')
            ->line('It is due on '.$this->invoice->due_at->timezone('Africa/Johannesburg')->format('j F Y').'. Pay online, or by EFT with reference '.$this->invoice->eft_reference.'.')
            ->action('View and pay', $this->billingUrl());
    }
}
