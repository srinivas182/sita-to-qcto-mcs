<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Settings\Models\Integration;

/**
 * WhatsApp Cloud API (Meta).
 *  - Sign-in codes use an approved authentication template: the code goes in the body
 *    parameter and in the copy-code (URL) button parameter.
 *  - Webhooks: X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(app secret, raw body);
 *    statuses arrive in entry[].changes[].value.statuses[].
 *  - Free-form text is only allowed inside a customer's 24-hour window, so the
 *    platform sends WhatsApp only through templates.
 */
final class MetaWhatsApp implements MessagingDriver
{
    public function __construct(private readonly Integration $integration) {}

    public function sendText(string $to, string $text, string $reference): SendResult
    {
        return SendResult::rejected('WhatsApp needs an approved template for messages the business starts.');
    }

    public function sendCode(string $to, string $code, string $text, string $reference): SendResult
    {
        $response = $this->http()->post('/'.$this->integration->value('phone_number_id').'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'biz_opaque_callback_data' => $reference,
            'template' => [
                'name' => (string) $this->integration->value('otp_template', 'sign_in_code'),
                'language' => ['code' => (string) $this->integration->value('otp_language', 'en')],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ],
            ],
        ]);

        $id = $response->json('messages.0.id');

        return $response->successful() && is_string($id)
            ? SendResult::accepted($id)
            : SendResult::rejected('WhatsApp: '.($response->json('error.message') ?? $response->status()));
    }

    public function receipts(Request $request): ?array
    {
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $this->integration->value('app_secret'));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $receipts = [];
        foreach ((array) $request->json('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $receipts[] = new Receipt(
                        providerId: isset($status['id']) ? (string) $status['id'] : null,
                        status: match ($status['status'] ?? '') {
                            'delivered' => 'delivered',
                            'read' => 'read',
                            'failed' => 'failed',
                            default => 'sent',
                        },
                        reference: isset($status['biz_opaque_callback_data']) ? (string) $status['biz_opaque_callback_data'] : null,
                        error: isset($status['errors'][0]) ? (string) ($status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'failed') : null,
                    );
                }
            }
        }

        return $receipts;
    }

    /** Webhook set-up handshake: echo the challenge when the verify token matches. */
    public function verifyChallenge(Request $request, string $expectedToken): ?string
    {
        if ($request->query('hub_mode', $request->query('hub.mode')) !== 'subscribe') {
            return null;
        }

        $token = (string) $request->query('hub_verify_token', (string) $request->query('hub.verify_token', ''));

        return hash_equals($expectedToken, $token) ? (string) $request->query('hub_challenge', (string) $request->query('hub.challenge', '')) : null;
    }

    public function ping(): void
    {
        $response = $this->http()->get('/'.$this->integration->value('phone_number_id'), ['fields' => 'display_phone_number,verified_name']);

        if (! $response->successful()) {
            throw new \RuntimeException('Meta refused the access token or phone number ID: '.($response->json('error.message') ?? $response->status()));
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.$this->integration->value('graph_version', 'v21.0'))
            ->withToken((string) $this->integration->value('access_token'))->acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }
}
