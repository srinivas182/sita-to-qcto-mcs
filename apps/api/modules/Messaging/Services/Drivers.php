<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use Illuminate\Support\Collection;
use Modules\Messaging\Drivers\BulkSms;
use Modules\Messaging\Drivers\Clickatell;
use Modules\Messaging\Drivers\Infobip;
use Modules\Messaging\Drivers\MessagingDriver;
use Modules\Messaging\Drivers\MetaWhatsApp;
use Modules\Messaging\Drivers\Panacea;
use Modules\Messaging\Drivers\SmsPortal;
use Modules\Messaging\Drivers\Twilio;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Context\TenantContext;

/** The SMS and WhatsApp providers switched on in Settings, in the admin's order. */
final class Drivers
{
    /** @var array<string, class-string<MessagingDriver>> */
    public const ADAPTERS = [
        'whatsapp.meta' => MetaWhatsApp::class,
        'sms.clickatell' => Clickatell::class,
        'sms.bulksms' => BulkSms::class,
        'sms.smsportal' => SmsPortal::class,
        'sms.panacea' => Panacea::class,
        'sms.infobip' => Infobip::class,
        'sms.twilio' => Twilio::class,
    ];

    public function __construct(private readonly TenantContext $context) {}

    /** @return Collection<int, Integration> */
    public function enabled(string $channel): Collection
    {
        return $this->context->runAsPlatform(fn () => Integration::query()->active($channel)->get())
            ->filter(fn (Integration $i): bool => isset(self::ADAPTERS[$i->driver]))->values();
    }

    public function make(Integration $integration): MessagingDriver
    {
        $class = self::ADAPTERS[$integration->driver];

        return new $class($integration);
    }

    public function configured(string $driver): ?Integration
    {
        return $this->context->runAsPlatform(fn () => Integration::query()->where('driver', $driver)->first());
    }
}
