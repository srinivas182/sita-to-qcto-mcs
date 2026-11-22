<?php

declare(strict_types=1);

namespace Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tenancy\Models\Tenant;

/**
 * Billing messages to the provider's billing email. Email now; WhatsApp and SMS
 * channels are added in Sprint 2c.
 */
abstract class BillingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract public function toMail(object $notifiable): MailMessage;

    /** The provider's own console, where billing lives. */
    protected function billingUrl(): string
    {
        $host = $this->tenant->code.'.'.config('platform.base_domain');

        return 'https://'.$host.'/billing';
    }
}
