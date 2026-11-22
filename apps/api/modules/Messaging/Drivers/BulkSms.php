<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;

/**
 * BulkSMS JSON REST API (https://api.bulksms.com/v1), HTTP Basic with the API token
 * ID and secret. Status-report webhooks post a JSON array of message objects.
 */
final class BulkSms implements MessagingDriver
{
    public function __construct(private readonly Integration $integration) {}

    public function sendText(string $to, string $text, string $reference): SendResult
    {
        $response = $this->http()->post('/messages', array_filter([
            'to' => $to,
            'body' => $text,
            'from' => $this->integration->value('sender_id'),
            'userSuppliedId' => $reference,
        ]));

        $id = $response->json('0.id');

        return $response->successful() && $id !== null
            ? SendResult::accepted((string) $id)
            : SendResult::rejected('BulkSMS: '.($response->json('detail') ?? $response->json('title') ?? $response->status()));
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

        return array_map(fn (array $m): Receipt => new Receipt(
            providerId: isset($m['id']) ? (string) $m['id'] : null,
            status: match ((string) ($m['status']['type'] ?? '')) {
                'DELIVERED' => 'delivered',
                'FAILED', 'UNKNOWN' => 'failed',
                default => 'sent',
            },
            reference: isset($m['userSuppliedId']) ? (string) $m['userSuppliedId'] : null,
            error: isset($m['status']['subtype']) ? (string) $m['status']['subtype'] : null,
        ), array_values(array_filter((array) $request->json()->all(), 'is_array')));
    }

    public function ping(): void
    {
        if (! $this->http()->get('/profile')->successful()) {
            throw new \RuntimeException('BulkSMS refused the token ID or secret.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.bulksms.com/v1')
            ->withBasicAuth((string) $this->integration->value('token_id'), (string) $this->integration->value('token_secret'))
            ->acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }
}
