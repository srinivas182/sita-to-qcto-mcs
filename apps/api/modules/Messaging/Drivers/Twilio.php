<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;

/**
 * Twilio Messaging: POST /2010-04-01/Accounts/{sid}/Messages.json (form, Basic auth).
 * Status callbacks are signed: X-Twilio-Signature = base64(HMAC-SHA1(auth token,
 * full URL + each POST parameter name and value, sorted by name)).
 */
final class Twilio implements MessagingDriver
{
    public function __construct(private readonly Integration $integration) {}

    public function sendText(string $to, string $text, string $reference): SendResult
    {
        $from = (string) $this->integration->value('from');
        $response = $this->http()->asForm()->post('/Messages.json', [
            'To' => $to,
            str_starts_with($from, 'MG') ? 'MessagingServiceSid' : 'From' => $from,
            'Body' => $text,
            'StatusCallback' => CallbackKey::url($this->integration),
        ]);

        $sid = $response->json('sid');

        return $response->successful() && is_string($sid)
            ? SendResult::accepted($sid)
            : SendResult::rejected('Twilio: '.($response->json('message') ?? $response->status()));
    }

    public function sendCode(string $to, string $code, string $text, string $reference): SendResult
    {
        return $this->sendText($to, $text, $reference);
    }

    public function receipts(Request $request): ?array
    {
        /** @var array<string, string> $params */
        $params = array_map('strval', $request->request->all());
        ksort($params);
        $data = CallbackKey::url($this->integration);
        foreach ($params as $name => $value) {
            $data .= $name.$value;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, (string) $this->integration->value('auth_token'), true));

        if (! hash_equals($expected, (string) $request->header('X-Twilio-Signature', ''))) {
            return null;
        }

        return [new Receipt(
            providerId: $params['MessageSid'] ?? null,
            status: match ($params['MessageStatus'] ?? '') {
                'delivered' => 'delivered',
                'read' => 'read',
                'failed' => 'failed',
                'undelivered' => 'undelivered',
                default => 'sent',
            },
            error: isset($params['ErrorCode']) ? 'Twilio error '.$params['ErrorCode'] : null,
        )];
    }

    public function ping(): void
    {
        if (! $this->http()->get('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode((string) $this->integration->value('account_sid')).'.json')->successful()) {
            throw new \RuntimeException('Twilio refused the account SID or auth token.');
        }
    }

    private function http(): PendingRequest
    {
        $sid = (string) $this->integration->value('account_sid');

        return Http::baseUrl('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid))
            ->withBasicAuth($sid, (string) $this->integration->value('auth_token'))->acceptJson()->timeout(15)->connectTimeout(5);
    }
}
