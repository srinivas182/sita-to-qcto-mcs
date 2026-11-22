<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use Illuminate\Support\Collection;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\IntegrationCatalogue;
use Modules\Tenancy\Context\TenantContext;

/**
 * The payment gateways the Super Admin has switched on, built from Settings.
 * Adapters are added here as they are built; the catalogue lists all gateways.
 */
final class Gateways
{
    /** @var array<string, class-string<PaymentGateway>> */
    public const ADAPTERS = [
        'payment.paystack' => PaystackGateway::class,
        'payment.payfast' => PayFastGateway::class,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly IntegrationCatalogue $catalogue,
    ) {}

    /**
     * Enabled gateways that can take a payment now, in the admin's order.
     *
     * @return list<array{driver: string, label: string, capabilities: list<string>, mode: string}>
     */
    public function available(): array
    {
        return array_values($this->enabled()
            ->filter(fn (Integration $i): bool => isset(self::ADAPTERS[$i->driver]))
            ->map(fn (Integration $i): array => [
                'driver' => $i->driver,
                'label' => $this->catalogue->driver($i->driver)['label'],
                'capabilities' => $this->catalogue->driver($i->driver)['capabilities'],
                'mode' => $i->mode,
            ])->all());
    }

    public function get(string $driver): PaymentGateway
    {
        $integration = $this->enabled()->firstWhere('driver', $driver);

        if ($integration === null || ! isset(self::ADAPTERS[$driver])) {
            throw new GatewayError('This payment option is not available.');
        }

        return $this->make($integration);
    }

    /** Any configured gateway (enabled or not), e.g. to accept a late webhook. */
    public function configured(string $driver): ?PaymentGateway
    {
        $integration = $this->context->runAsPlatform(fn () => Integration::query()->where('driver', $driver)->first());

        return $integration !== null && isset(self::ADAPTERS[$driver]) ? $this->make($integration) : null;
    }

    public function make(Integration $integration): PaymentGateway
    {
        $class = self::ADAPTERS[$integration->driver];

        return new $class($integration);
    }

    /** @return Collection<int, Integration> */
    private function enabled(): Collection
    {
        return $this->context->runAsPlatform(fn () => Integration::query()->active('payment')->get());
    }
}
