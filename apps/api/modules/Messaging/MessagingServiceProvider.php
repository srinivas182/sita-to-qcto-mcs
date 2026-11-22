<?php

declare(strict_types=1);

namespace Modules\Messaging;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Modules\Messaging\Mail\PlatformMailer;
use Modules\Messaging\Services\Drivers;
use Modules\Messaging\Services\DriverTester;
use Modules\Messaging\Services\MessagingOtpChannel;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\IntegrationCallbacks;
use Modules\Settings\Services\IntegrationTesters;
use Modules\Support\ModuleServiceProvider;

/** Messaging: SMS and WhatsApp providers with failover, receipts and costs; the platform mailer. */
final class MessagingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // OTP channels from Identity ("messaging" driver) send through Settings' providers.
        $this->app->bind('otp.channel.messaging', fn ($app, array $params) => new MessagingOtpChannel((string) ($params['channel'] ?? 'sms')));
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        $testers = $this->app->make(IntegrationTesters::class);
        $callbacks = $this->app->make(IntegrationCallbacks::class);

        foreach (Drivers::ADAPTERS as $driver => $class) {
            if (method_exists($class, 'ping')) {
                $testers->register($driver, DriverTester::class);
            }
            $callbacks->register($driver, fn (Integration $i): string => CallbackKey::url($i));
        }

        $this->app->afterResolving(MailManager::class, function (MailManager $mail): void {
            $mail->extend('platform', fn () => $this->app->make(PlatformMailer::class)->transport());
        });
        Event::listen(MessageSending::class, fn (MessageSending $e) => $this->app->make(PlatformMailer::class)->applySender($e));

        // A changed email service applies to the next message, even in long-lived workers.
        Integration::saved(function (Integration $i): void {
            if ($i->type === 'email' && $this->app->resolved('mail.manager')) {
                $this->app->make('mail.manager')->purge('platform');
            }
        });
    }
}
