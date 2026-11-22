<?php

declare(strict_types=1);

namespace Modules\Billing\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Validator;
use Modules\Audit\AuditLogger;
use Modules\Identity\Models\User;
use Modules\Settings\Exceptions\SettingsError;
use Modules\Settings\Models\PlatformSetting;
use Modules\Tenancy\Context\TenantContext;

/** Collection rules (Settings → Billing rules), defaulting to config/billing.php. */
final class BillingRules
{
    private const KEY = 'billing';

    private const CACHE = 'settings:billing';

    public function __construct(
        private readonly TenantContext $context,
        private readonly Cache $cache,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{trial_days: int, invoice_due_days: int, retry_days: list<int>, read_only_after_days: int, suspend_after_days: int, trial_reminder_days: int} */
    public function get(): array
    {
        /** @var array{trial_days: int, invoice_due_days: int, retry_days: list<int>, read_only_after_days: int, suspend_after_days: int, trial_reminder_days: int} */
        return $this->cache->remember(self::CACHE, 3600, function (): array {
            $row = $this->context->runAsPlatform(fn () => PlatformSetting::query()->find(self::KEY));

            return [...(array) config('billing.rules'), ...($row instanceof PlatformSetting ? $row->value : [])];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(array $input, User $actor): array
    {
        $validator = Validator::make($input, [
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'invoice_due_days' => ['required', 'integer', 'min:0', 'max:60'],
            'retry_days' => ['required', 'array', 'max:6'],
            'retry_days.*' => ['integer', 'min:0', 'max:60', 'distinct'],
            'read_only_after_days' => ['required', 'integer', 'min:1', 'max:90'],
            'suspend_after_days' => ['required', 'integer', 'min:1', 'max:180', 'gt:read_only_after_days'],
            'trial_reminder_days' => ['required', 'integer', 'min:0', 'max:14'],
        ], ['suspend_after_days.gt' => 'Suspension must come after read-only.']);

        if ($validator->fails()) {
            throw new SettingsError('Check the highlighted fields.', 'invalid', $validator->errors()->toArray());
        }

        $value = $validator->validated();
        $retry = array_map('intval', (array) $value['retry_days']);
        sort($retry);
        $value['retry_days'] = $retry;

        $this->context->runAsPlatform(fn () => PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $value, 'updated_by' => $actor->id]));
        $this->cache->forget(self::CACHE);
        $this->audit->record('settings.billing_rules_updated', changes: $value);

        return $this->get();
    }
}
