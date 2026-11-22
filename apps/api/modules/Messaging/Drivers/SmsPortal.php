<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;

/**
 * SMSPortal REST API (https://rest.smsportal.com). A token from GET /Authentication
 * (Basic client ID and secret) is used for POST /v1/bulkmessages. Webhooks return our
 * CustomerId as UserReference.
 */
final class SmsPortal implements MessagingDriver
{
    public function __construct(private readonly Integration $integration) {}

    public function sendText(string $to, string $text, string $reference): SendResult
    {
        $response = Http::withToken($this->token())->acceptJson()->asJson()->timeout(15)->connectTimeout(5)
            ->post('https://rest.smsportal.com/v1/bulkmessages', [
                'messages' => [['content' => $text, 'destination' => ltrim($to, '+'), 'customerId' => $reference]],
            ]);

        return $response->successful()
            ? SendResult::accepted(($id = $response->json('eventId')) !== null ? (string) $id : null)
            : SendResult::rejected('SMSPortal: '.($response->json('errorMessage') ?? $response->status()));
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

        // One receipt object, or a list of them.
        $decoded = json_decode($request->getContent(), true);
        $items = is_array($decoded) ? (array_is_list($decoded) ? $decoded : [$decoded]) : [];

        return array_map(fn (array $r): Receipt => new Receipt(
            providerId: isset($r['EventId']) ? (string) $r['EventId'] : null,
            status: match (strtolower((string) ($r['Status'] ?? $r['DeliveryStatus'] ?? ''))) {
                'delivered' => 'delivered',
                'failed', 'expired', 'rejected', 'undelivered' => 'failed',
                default => 'sent',
            },
            reference: isset($r['UserReference']) ? (string) $r['UserReference'] : null,
        ), array_values(array_filter($items, 'is_array')));
    }

    public function ping(): void
    {
        $this->token(fresh: true);
    }

    private function token(bool $fresh = false): string
    {
        $key = 'smsportal-token:'.$this->integration->id.':'.$this->integration->updated_at->timestamp;
        if ($fresh) {
            Cache::forget($key);
        }

        return (string) Cache::remember($key, 3000, function (): string {
            $response = Http::withBasicAuth((string) $this->integration->value('client_id'), (string) $this->integration->value('api_secret'))
                ->acceptJson()->timeout(15)->connectTimeout(5)->get('https://rest.smsportal.com/Authentication');
            $token = $response->json('token');

            if (! $response->successful() || ! is_string($token)) {
                throw new \RuntimeException('SMSPortal refused the client ID or secret.');
            }

            return $token;
        });
    }
}
