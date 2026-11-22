<?php

declare(strict_types=1);

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Models\Subscription;
use Modules\Tenancy\Models\Tenant;

final class TrialEnding extends BillingNotification
{
    public function __construct(Tenant $tenant, public readonly Subscription $subscription)
    {
        parent::__construct($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->subscription->trial_ends_at?->timezone('Africa/Johannesburg')->format('j F Y') ?? '';

        return (new MailMessage)
            ->subject("Your trial ends on {$date}")
            ->greeting("Hello {$this->tenant->trading_name},")
            ->line("Your trial ends on {$date}. Your first tax invoice is issued then.")
            ->line('Save a card to pay automatically, or pay each invoice by Instant EFT or EFT.')
            ->action('Review your plan', $this->billingUrl());
    }
}
