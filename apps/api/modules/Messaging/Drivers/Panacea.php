<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Support\CallbackKey;
use Modules\Settings\Models\Integration;

/**
 * Panacea Mobile HTTPS API: https://api.panaceamobile.com/json?action=message_send
 * with username, password (or API key), to, from, text and a client reference.
 * A JSON status of 0 means accepted. Delivery reports are checked with our key.
 */
final class Panacea implements MessagingDriver
{
    public function __construct(private readonly Integration $integration) {}

    public function sendText(string $to, string $text, string $reference): SendResult
    {
        $response = Http::acceptJson()->timeout(15)->connectTimeout(5)->asForm()
            ->post('https://api.panaceamobile.com/json', array_filter([
                'action' => 'message_send',
                'username' => (string) $this->integration->value('username'),
                'password' => (string) $this->integration->value('api_key'),
                'to' => ltrim($to, '+'),
                'from' => $this->integration->value('sender_id'),
                'text' => $text,
                'client_reference' => $reference,
                'report_url' => CallbackKey::url($this->integration),
            ]));

        return $response->successful() && (int) $response->json('status', -1) === 0
            ? SendResult::accepted(($id = $response->json('details')) !== null && is_scalar($id) ? (string) $id : null)
            : SendResult::rejected('Panacea Mobile: '.($response->json('message') ?? $response->status()));
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

        $status = strtolower((string) $request->input('status', ''));

        return [new Receipt(
            providerId: $request->filled('message_id') ? (string) $request->input('message_id') : null,
            status: in_array($status, ['1', 'delivered'], true) ? 'delivered' : (in_array($status, ['2', 'failed', 'undelivered'], true) ? 'failed' : 'sent'),
            reference: $request->filled('client_reference') ? (string) $request->input('client_reference') : null,
        )];
    }
}
