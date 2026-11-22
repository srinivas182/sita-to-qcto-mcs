<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;

/**
 * Clickatell One API: POST https://platform.clickatell.com/v1/message with the API
 * key in Authorization. Status callbacks carry event.messageStatusUpdate[] with
 * messageId, clientMessageId and status; they are checked with our callback key.
 */
final class Clickatell implements MessagingDriver
{
    public function __construct(private readonly Integration $integration) {}

    public function sendText(string $to, string $text, string $reference): SendResult
    {
        $message = array_filter([
            'channel' => 'sms',
            'to' => ltrim($to, '+'),
            'content' => $text,
            'clientMessageId' => $reference,
            'from' => $this->integration->value('sender_id'),
        ]);

        $response = Http::withHeaders(['Authorization' => (string) $this->integration->value('api_key')])
            ->acceptJson()->asJson()->timeout(15)->connectTimeout(5)
            ->post('https://platform.clickatell.com/v1/message', ['messages' => [$message]]);

        $first = (array) $response->json('messages.0', []);

        return $response->successful() && ($first['accepted'] ?? false)
            ? SendResult::accepted(isset($first['apiMessageId']) ? (string) $first['apiMessageId'] : null)
            : SendResult::rejected('Clickatell: '.($first['error']['description'] ?? $response->json('error.description') ?? $response->status()));
    }

    public function sendCode(string $to, string $code, string $text, string $reference): SendResult
    {
        return $this->sendText($to, $text, $reference);
    }

    public function receipts(Request $request): ?array
    {
        if (! CallbackKey::valid($this->integration, $request->query('key'))) {
            return null;
        }

        return array_map(fn (array $u): Receipt => new Receipt(
            providerId: isset($u['messageId']) ? (string) $u['messageId'] : null,
            status: match (strtoupper((string) ($u['status'] ?? ''))) {
                'DELIVERED_TO_GATEWAY', 'RECEIVED_BY_RECIPIENT', 'DEVICE_ACK', 'DELIVERED' => 'delivered',
                'READ' => 'read',
                'ERROR_DELIVERING', 'ROUTING_ERROR', 'EXPIRED', 'CANCELLED', 'REJECTED', 'FAILED' => 'failed',
                default => 'sent',
            },
            reference: isset($u['clientMessageId']) ? (string) $u['clientMessageId'] : null,
        ), array_values((array) $request->json('event.messageStatusUpdate', [])));
    }
}
