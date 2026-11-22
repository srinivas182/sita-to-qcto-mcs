<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Validator;
use Modules\Audit\AuditLogger;
use Modules\Identity\Models\User;
use Modules\Settings\Exceptions\SettingsError;
use Modules\Settings\Models\PlatformSetting;
use Modules\Tenancy\Context\TenantContext;

/**
 * The platform owner's company details (Settings → Company). Printed on every tax
 * invoice, so the SARS tax-invoice particulars live here: registered name, VAT
 * number and address of the supplier.
 */
final class CompanySettings
{
    private const KEY = 'company';

    private const CACHE = 'settings:company';

    /** @var array<string, list<string>> */
    public const RULES = [
        'registered_name' => ['required', 'string', 'max:200'],
        'trading_name' => ['nullable', 'string', 'max:200'],
        'registration_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z\/\- ]+$/'],
        // SA VAT numbers are 10 digits starting with 4.
        'vat_number' => ['nullable', 'regex:/^4\d{9}$/'],
        'address_line1' => ['required', 'string', 'max:200'],
        'address_line2' => ['nullable', 'string', 'max:200'],
        'city' => ['required', 'string', 'max:100'],
        'province' => ['nullable', 'string', 'max:100'],
        'postal_code' => ['nullable', 'string', 'max:10'],
        'country' => ['required', 'string', 'size:2'],
        'email' => ['required', 'email:rfc', 'max:254'],
        'phone' => ['nullable', 'string', 'max:20'],
        'website' => ['nullable', 'url:https', 'max:200'],
        'invoice_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9\-]+$/'],
        'currency' => ['required', 'in:ZAR'],
        'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        'bank_details' => ['nullable', 'string', 'max:1000'],
        'invoice_footer' => ['nullable', 'string', 'max:1000'],
    ];

    /** @var array<string, mixed> */
    private const DEFAULTS = ['country' => 'ZA', 'currency' => 'ZAR', 'vat_rate' => 15, 'invoice_prefix' => 'INV'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Cache $cache,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        /** @var array<string, mixed> */
        return $this->cache->remember(self::CACHE, 3600, function (): array {
            $row = $this->context->runAsPlatform(fn () => PlatformSetting::query()->find(self::KEY));

            return [...self::DEFAULTS, ...($row instanceof PlatformSetting ? $row->value : [])];
        });
    }

    /** True once the details needed on a tax invoice are filled in. */
    public function isComplete(): bool
    {
        $company = $this->get();

        foreach (['registered_name', 'address_line1', 'city', 'email'] as $field) {
            if (($company[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(array $input, User $actor): array
    {
        $validator = Validator::make($input, self::RULES, [
            'vat_number.regex' => 'A South African VAT number has 10 digits and starts with 4.',
            'invoice_prefix.regex' => 'Use capital letters, digits and dashes only.',
        ]);

        if ($validator->fails()) {
            throw new SettingsError('Check the highlighted fields.', 'invalid', $validator->errors()->toArray());
        }

        /** @var array<string, mixed> $value */
        $value = array_filter($validator->validated(), fn (mixed $v): bool => $v !== null && $v !== '');
        $value['vat_rate'] = (float) $value['vat_rate'];
        $before = $this->get();

        $this->context->runAsPlatform(fn () => PlatformSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $value, 'updated_by' => $actor->id],
        ));
        $this->cache->forget(self::CACHE);

        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($value)]) as $field) {
            if (($before[$field] ?? null) != ($value[$field] ?? null)) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value[$field] ?? null];
            }
        }

        if ($changes !== []) {
            $this->audit->record('settings.company_updated', changes: $changes);
        }

        return $this->get();
    }
}
