<?php

declare(strict_types=1);

namespace Modules\Messaging\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Support\PhoneNumber;
use Modules\Messaging\Models\Message;
use Modules\Messaging\Services\MessageService;
use Modules\Settings\Exceptions\SettingsError;
use Modules\Settings\Models\Integration;
use Modules\Tenancy\Models\Tenant;

/** Super Admin: message log, delivery and cost per provider and per training provider. */
final class MessageAdminController
{
    public function index(Request $request): JsonResponse
    {
        $query = Message::query()->orderByDesc('created_at');
        foreach (['channel', 'status', 'driver', 'purpose'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, (string) $value);
            }
        }
        if ($phone = trim((string) $request->query('phone', ''))) {
            $e164 = PhoneNumber::normalise($phone);
            $query->where('destination_hash', $e164 !== null ? hash_hmac('sha256', $e164, (string) config('identity.pepper')) : '-');
        }

        $page = $query->paginate(50);
        $tenants = Tenant::query()->whereIn('id', collect($page->items())->pluck('tenant_id')->filter())->pluck('trading_name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Message $m) => [...$m->present(), 'tenant' => $m->tenant_id ? $tenants->get($m->tenant_id) : null])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** This month: messages, delivery rate and cost by provider and by training provider. */
    public function summary(): JsonResponse
    {
        $since = now()->startOfMonth();
        $base = fn () => Message::query()->toBase()->where('created_at', '>=', $since);

        $byDriver = $base()->selectRaw("driver, channel, count(*) as sent, count(*) filter (where status in ('delivered','read')) as delivered, count(*) filter (where status in ('failed','undelivered')) as failed, coalesce(sum(cost_cents), 0) as cost_cents")
            ->groupBy('driver', 'channel')->orderByDesc('sent')->get();
        $byTenant = $base()->selectRaw('tenant_id, count(*) as sent, coalesce(sum(cost_cents), 0) as cost_cents')
            ->groupBy('tenant_id')->orderByDesc('cost_cents')->limit(20)->get();
        $names = Tenant::query()->whereIn('id', $byTenant->pluck('tenant_id')->filter())->pluck('trading_name', 'id');

        return response()->json([
            'since' => $since->toIso8601String(),
            'by_driver' => $byDriver->map(fn ($r) => ['driver' => $r->driver, 'channel' => $r->channel, 'sent' => (int) $r->sent, 'delivered' => (int) $r->delivered, 'failed' => (int) $r->failed, 'cost_cents' => (int) $r->cost_cents])->all(),
            'by_tenant' => $byTenant->map(fn ($r) => ['tenant' => $r->tenant_id ? ($names[$r->tenant_id] ?? null) : 'Platform', 'sent' => (int) $r->sent, 'cost_cents' => (int) $r->cost_cents])->all(),
        ]);
    }

    /** Send a real test message through one provider. */
    public function sendTest(Request $request, string $integration): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:20']]);
        $model = Integration::query()->whereIn('type', ['sms', 'whatsapp'])->findOrFail($integration);
        $to = PhoneNumber::normalise($data['to']) ?? throw new SettingsError('Enter a valid mobile number.', 'invalid', ['to' => ['Enter a valid mobile number.']]);

        $result = app(MessageService::class)->test($model, $to);

        return response()->json(['sent' => $result->sent, 'error' => $result->error, 'message' => $result->message?->present()]);
    }
}
