<?php

declare(strict_types=1);

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Support\SubscriptionStatus;
use Modules\Tenancy\Models\Tenant;

final class AccountRestricted extends BillingNotification
{
    public function __construct(Tenant $tenant, public readonly SubscriptionStatus $status)
    {
        parent::__construct($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Hello {$this->tenant->trading_name},");

        if ($this->status === SubscriptionStatus::Suspended) {
            return $mail->subject('Your account is suspended until payment')
                ->line('Because an invoice is still unpaid, only billing is available until it is paid. All your records are kept safely; nothing has been deleted.')
                ->action('Pay now', $this->billingUrl());
        }

        return $mail->subject('Your account is read-only until payment')
            ->line('Because an invoice is overdue, your team and learners can still sign in and see everything, but changes are paused until it is paid.')
            ->action('Pay now', $this->billingUrl());
    }
}
