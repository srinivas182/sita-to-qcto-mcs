<?php

declare(strict_types=1);

namespace Modules\Tenancy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;

final class AccreditationExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    private readonly string $provider;

    private readonly string $code;

    private readonly string $centreName;

    private readonly ?string $number;

    private readonly string $date;

    /** Copies what the email needs: queued mail must not re-load tenant rows (RLS). */
    public function __construct(Tenant $tenant, Centre $centre, public readonly int $days)
    {
        $this->provider = $tenant->trading_name;
        $this->code = $tenant->code;
        $this->centreName = $centre->name;
        $this->number = $centre->accreditation_number;
        $this->date = $centre->accreditation_expires_on?->format('j F Y') ?? '';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->date;
        $subject = $this->days < 0
            ? "Accreditation expired: {$this->centreName}"
            : "Accreditation for {$this->centreName} ends in {$this->days} days";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$this->provider},")
            ->line("The QCTO accreditation {$this->number} for {$this->centreName} ".($this->days < 0 ? "ended on {$date}." : "ends on {$date}."))
            ->line('Apply for re-accreditation in good time: learners enrolled on programmes outside a valid accreditation cannot be certificated. When renewed, update the expiry date on the centre.')
            ->action('Open centres', 'https://'.$this->code.'.'.config('platform.base_domain').'/centres');
    }
}
