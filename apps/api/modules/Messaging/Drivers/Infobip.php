<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;

/**
 * Infobip SMS API v3: POST {base URL}/sms/3/messages with "App {api key}".
 * Delivery reports post results[] with messageId, callbackData and status.groupName.
 */
final class Infobip implements MessagingDriver
{
    public function __construct(private readonly Integration $integration) {}

    public function sendText(string $to, string $text, string $reference): SendResult
    {
        $response = $this->http()->post('/sms/3/messages', ['messages' => [array_filter([
            'sender' => $this->integration->value('sender_id'),
            'destinations' => [['to' => ltrim($to, '+'), 'messageId' => $reference]],
            'content' => ['text' => $text],
            'webhooks' => ['delivery' => ['url' => CallbackKey::url($this->integration)], 'callbackData' => $reference],
        ])]]);

        $id = $response->json('messages.0.messageId');

        return $response->successful() && is_string($id)
            ? SendResult::accepted($id)
            : SendResult::rejected('Infobip: '.($response->json('requestError.serviceException.text') ?? $response->json('errorMessage') ?? $response->status()));
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

        return array_map(fn (array $r): Receipt => new Receipt(
            providerId: isset($r['messageId']) ? (string) $r['messageId'] : null,
            status: match (strtoupper((string) ($r['status']['groupName'] ?? ''))) {
                'DELIVERED' => 'delivered',
                'UNDELIVERABLE', 'EXPIRED', 'REJECTED' => 'failed',
                default => 'sent',
            },
            reference: isset($r['callbackData']) ? (string) $r['callbackData'] : null,
            error: isset($r['error']['name']) && ($r['error']['groupName'] ?? 'OK') !== 'OK' ? (string) $r['error']['name'] : null,
        ), array_values((array) $request->json('results', [])));
    }

    public function ping(): void
    {
        if (! $this->http()->get('/account/1/balance')->successful()) {
            throw new \RuntimeException('Infobip refused the base URL or API key.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->integration->value('base_url'), '/'))
            ->withHeaders(['Authorization' => 'App '.$this->integration->value('api_key')])
            ->acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }
}
