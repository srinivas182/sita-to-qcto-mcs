<?php

declare(strict_types=1);

namespace Modules\Messaging\Services;

use Illuminate\Support\Str;
use Modules\Identity\Support\PhoneNumber;
use Modules\Messaging\Drivers\MessagingDriver;
use Modules\Messaging\Drivers\Receipt;
use Modules\Messaging\Drivers\SendResult;
use Modules\Messaging\Models\Message;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Context\TenantContext;
use Throwable;

/**
 * Sends SMS and WhatsApp messages through the providers switched on in Settings,
 * trying each in the admin's order until one accepts. Every attempt is logged with
 * its provider, status and cost; the text itself is never stored.
 */
final class MessageService
{
    public function __construct(
        private readonly Drivers $drivers,
        private readonly TenantContext $context,
    ) {}

    public function hasProviders(string $channel): bool
    {
        return $this->drivers->enabled($channel)->isNotEmpty();
    }

    /** Send plain text by SMS. */
    public function sms(string $to, string $text, string $purpose): MessageResult
    {
        return $this->deliver('sms', $to, $purpose, fn ($driver, string $ref): SendResult => $driver->sendText($to, $text, $ref));
    }

    /** Send a sign-in code on one channel (WhatsApp template or SMS text). */
    public function code(string $channel, string $to, string $code, string $text): MessageResult
    {
        return $this->deliver($channel, $to, 'otp', fn ($driver, string $ref): SendResult => $driver->sendCode($to, $code, $text, $ref));
    }

    /** Send through one specific provider (Settings → Send a test). */
    public function test(Integration $integration, string $to): MessageResult
    {
        $text = 'Test message from '.config('app.name').'. Your '.$integration->type.' set-up works.';
        $driver = $this->drivers->make($integration);
        $ref = $this->reference();

        try {
            $result = $integration->type === 'whatsapp'
                ? $driver->sendCode($to, '123456', $text, $ref)
                : $driver->sendText($to, $text, $ref);
        } catch (Throwable $e) {
            $result = SendResult::rejected($e->getMessage());
        }

        $message = $this->log($integration, $to, 'test', $result, null);

        return new MessageResult($result->accepted, $message, $result->error);
    }

    /**
     * Apply delivery receipts from a provider's callback. Platform context.
     *
     * @param  list<Receipt>  $receipts
     */
    public function applyReceipts(string $driver, array $receipts): int
    {
        return (int) $this->context->runAsPlatform(function () use ($driver, $receipts): int {
            $updated = 0;
            /** @var Receipt $receipt */
            foreach ($receipts as $receipt) {
                $message = Message::query()->where('driver', $driver)
                    ->where(fn ($q) => $q->when($receipt->providerId, fn ($q) => $q->where('provider_message_id', $receipt->providerId))
                        ->when($receipt->reference, fn ($q) => $q->orWhere('id', Str::lower((string) $receipt->reference))))
                    ->first();

                if ($message === null || $this->rank($receipt->status) < $this->rank($message->status)) {
                    continue;
                }

                $message->fill([
                    'status' => $receipt->status,
                    'error' => $receipt->error ?? $message->error,
                    'delivered_at' => in_array($receipt->status, ['delivered', 'read'], true) ? ($message->delivered_at ?? now()) : $message->delivered_at,
                ])->save();
                $updated++;
            }

            return $updated;
        });
    }

    /**
     * @param  callable(MessagingDriver, string): SendResult  $send
     */
    private function deliver(string $channel, string $to, string $purpose, callable $send): MessageResult
    {
        $previous = null;
        $lastError = 'No '.$channel.' provider is switched on.';

        foreach ($this->drivers->enabled($channel) as $integration) {
            $ref = $this->reference();

            try {
                $result = $send($this->drivers->make($integration), $ref);
            } catch (Throwable $e) {
                report($e);
                $result = SendResult::rejected($e->getMessage());
            }

            $message = $this->log($integration, $to, $purpose, $result, $previous, $ref);

            if ($result->accepted) {
                return new MessageResult(true, $message);
            }

            $previous = $message->id;
            $lastError = (string) $result->error;
        }

        return new MessageResult(false, null, $lastError);
    }

    private function log(Integration $integration, string $to, string $purpose, SendResult $result, ?string $fallbackOf, ?string $id = null): Message
    {
        $attributes = [
            'id' => $id ?? $this->reference(),
            'tenant_id' => $this->context->tenantId(),
            'channel' => $integration->type,
            'driver' => $integration->driver,
            'purpose' => $purpose,
            'destination_hash' => hash_hmac('sha256', $to, (string) config('identity.pepper')),
            'destination_masked' => PhoneNumber::mask($to),
            'status' => $result->accepted ? 'sent' : 'failed',
            'provider_message_id' => $result->providerId,
            'cost_cents' => $result->accepted && $integration->value('price_cents') !== null ? (int) $integration->value('price_cents') : null,
            'error' => $result->error,
            'fallback_of' => $fallbackOf,
            'sent_at' => $result->accepted ? now() : null,
        ];

        return $this->context->tenantId() === null && ! $this->context->isPlatform()
            ? $this->context->runAsPlatform(fn () => Message::query()->create($attributes))
            : Message::query()->create($attributes);
    }

    /** Our own ID doubles as the reference providers echo back. */
    private function reference(): string
    {
        return Str::lower((string) Str::ulid());
    }

    private function rank(string $status): int
    {
        return ['sent' => 1, 'failed' => 2, 'undelivered' => 2, 'delivered' => 3, 'read' => 4][$status] ?? 0;
    }
}
