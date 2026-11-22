<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Identity\Models\User;
use Modules\Settings\Exceptions\SettingsError;
use Modules\Settings\Models\Integration;
use Throwable;

/**
 * Sets up, enables, orders and tests integrations. Platform context only.
 *
 * Secret values are write-only: they are stored encrypted and never returned. An
 * update that leaves a secret out keeps the saved one; sending null (or an empty
 * string, which Laravel turns into null) clears it.
 */
final class IntegrationService
{
    public function __construct(
        private readonly IntegrationCatalogue $catalogue,
        private readonly IntegrationTesters $testers,
    ) {}

    /**
     * Create or update the integration for a driver.
     *
     * @param  array{values?: array<string, mixed>, mode?: string, enabled?: bool}  $input
     */
    public function save(string $driver, array $input, User $actor): Integration
    {
        if (! $this->catalogue->has($driver)) {
            throw new SettingsError('This integration is not available.', 'unknown_driver');
        }

        $type = $this->catalogue->typeOf($driver);
        $validator = Validator::make($input, [
            'values' => ['sometimes', 'array'],
            'mode' => ['sometimes', 'in:sandbox,live'],
            'enabled' => ['sometimes', 'boolean'],
            ...$this->catalogue->rules($driver),
        ]);

        if ($validator->fails()) {
            throw new SettingsError('Check the highlighted fields.', 'invalid', $validator->errors()->toArray());
        }

        $integration = Integration::query()->firstOrNew(['driver' => $driver], [
            'type' => $type,
            'mode' => $this->catalogue->type($type)['modes'] ? 'sandbox' : 'live',
            'priority' => $this->nextPriority($type),
            'enabled' => false,
        ]);

        $this->applyValues($integration, $input['values'] ?? []);

        if (isset($input['mode']) && $this->catalogue->type($type)['modes']) {
            $integration->mode = $input['mode'];
        }

        if ($integration->isDirty(['credentials', 'settings', 'mode'])) {
            // New keys have not been tested yet.
            $integration->fill(['last_tested_at' => null, 'last_test_ok' => null, 'last_test_message' => null]);
        }

        $integration->updated_by = $actor->id;

        if (($input['enabled'] ?? null) === true) {
            return $this->enable($integration, $actor);
        }

        if (($input['enabled'] ?? null) === false) {
            $integration->enabled = false;
        } elseif ($integration->enabled) {
            // Changing a live integration must keep it complete.
            $this->assertComplete($integration);
        }

        $integration->save();

        return $integration;
    }

    /** Enable after checking required fields; a tester, if any, must pass first. */
    public function enable(Integration $integration, User $actor): Integration
    {
        $this->assertComplete($integration);

        if ($this->testers->has($integration->driver)) {
            $result = $this->runTest($integration);

            if (! $result->ok) {
                $integration->save();

                throw new SettingsError('The connection test failed, so it was not switched on: '.$result->message, 'test_failed');
            }
        }

        DB::transaction(function () use ($integration, $actor): void {
            if ($this->catalogue->type($integration->type)['single']) {
                Integration::query()->where('type', $integration->type)->where('driver', '!=', $integration->driver)
                    ->where('enabled', true)->get()
                    ->each(fn (Integration $other) => $other->forceFill(['enabled' => false, 'updated_by' => $actor->id])->save());
            }

            $integration->enabled = true;
            $integration->updated_by = $actor->id;
            $integration->save();
        });

        return $integration;
    }

    public function disable(Integration $integration, User $actor): Integration
    {
        $integration->forceFill(['enabled' => false, 'updated_by' => $actor->id])->save();

        return $integration;
    }

    /** Run the driver's connection test and keep the result. */
    public function test(Integration $integration): TestResult
    {
        if (! $this->testers->has($integration->driver)) {
            throw new SettingsError('A connection test for this integration arrives with its module.', 'test_unavailable');
        }

        $result = $this->runTest($integration);
        $integration->save();

        return $result;
    }

    /**
     * Set the order in which enabled drivers of one type are tried.
     *
     * @param  list<string>  $ids
     */
    public function reorder(string $type, array $ids, User $actor): void
    {
        $this->catalogue->type($type);
        $known = Integration::query()->where('type', $type)->pluck('id')->all();

        if (array_diff($ids, $known) !== [] || count(array_unique($ids)) !== count($ids)) {
            throw new SettingsError('The list must contain this type\'s integrations only, once each.', 'invalid_order');
        }

        DB::transaction(function () use ($ids, $actor): void {
            foreach ($ids as $position => $id) {
                Integration::query()->whereKey($id)->firstOrFail()
                    ->forceFill(['priority' => ($position + 1) * 10, 'updated_by' => $actor->id])->save();
            }
        });
    }

    public function remove(Integration $integration): void
    {
        $integration->delete();
    }

    /**
     * Public view of an integration: non-secret values in full, secrets as a hint.
     *
     * @return array<string, mixed>
     */
    public function present(Integration $integration): array
    {
        $values = [];
        $secrets = [];

        foreach ($this->catalogue->fields($integration->driver) as $name => $field) {
            if ($field['secret'] ?? false) {
                $value = $integration->credentials[$name] ?? null;
                $secrets[$name] = is_string($value) && $value !== '' ? '••••'.mb_substr($value, -4) : null;
            } else {
                $values[$name] = $integration->settings[$name] ?? $field['default'] ?? null;
            }
        }

        return [
            'id' => $integration->id,
            'type' => $integration->type,
            'driver' => $integration->driver,
            'mode' => $integration->mode,
            'enabled' => $integration->enabled,
            'priority' => $integration->priority,
            'values' => (object) $values,
            'secrets' => (object) $secrets,
            'complete' => $this->missing($integration) === [],
            'testable' => $this->testers->has($integration->driver),
            'last_test' => $integration->last_tested_at === null ? null : [
                'at' => $integration->last_tested_at->toIso8601String(),
                'ok' => $integration->last_test_ok,
                'message' => $integration->last_test_message,
            ],
            'updated_at' => $integration->updated_at->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function applyValues(Integration $integration, array $values): void
    {
        $credentials = $integration->credentials ?? [];
        $settings = $integration->settings ?? [];

        foreach ($this->catalogue->fields($integration->driver) as $name => $field) {
            if (! array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];

            if ($field['secret'] ?? false) {
                if (is_string($value) && trim($value) !== '') {
                    $credentials[$name] = trim($value);
                } else {
                    unset($credentials[$name]);
                }

                continue;
            }

            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                unset($settings[$name]);
            } else {
                $settings[$name] = match ($field['type'] ?? 'text') {
                    'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
                    'number' => (int) $value,
                    default => $value,
                };
            }
        }

        $integration->credentials = $credentials === [] ? null : $credentials;
        $integration->settings = $settings === [] ? null : $settings;
    }

    /** @return list<string> required fields with no value */
    private function missing(Integration $integration): array
    {
        return array_values(array_filter(
            $this->catalogue->requiredFields($integration->driver),
            function (string $name) use ($integration): bool {
                $value = $integration->value($name, $this->catalogue->fields($integration->driver)[$name]['default'] ?? null);

                return $value === null || $value === '';
            },
        ));
    }

    private function assertComplete(Integration $integration): void
    {
        $missing = $this->missing($integration);

        if ($missing !== []) {
            $fields = $this->catalogue->fields($integration->driver);

            throw new SettingsError(
                'Fill in the required fields before switching it on.',
                'incomplete',
                array_combine(
                    array_map(fn (string $n): string => "values.{$n}", $missing),
                    array_map(fn (string $n): array => [$fields[$n]['label'].' is required.'], $missing),
                ),
            );
        }
    }

    private function runTest(Integration $integration): TestResult
    {
        try {
            $result = $this->testers->for($integration->driver)?->test($integration)
                ?? TestResult::failed('No test available.');
        } catch (Throwable $e) {
            report($e);
            $result = TestResult::failed('Could not connect: '.mb_substr($e->getMessage(), 0, 300));
        }

        $integration->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $result->ok,
            'last_test_message' => mb_substr($result->message, 0, 500),
        ]);

        return $result;
    }

    private function nextPriority(string $type): int
    {
        return ((int) Integration::query()->where('type', $type)->max('priority')) + 10;
    }
}
