<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use InvalidArgumentException;

/**
 * Reads config/integrations.php: the types and drivers the Super Admin can set up,
 * the fields each driver needs and which of them are secret.
 *
 * @phpstan-type Field array{label: string, secret?: bool, required?: bool, type?: string, options?: list<string>, default?: mixed, help?: string}
 * @phpstan-type Driver array{label: string, description: string, capabilities: list<string>, note?: string, fields: array<string, Field>}
 */
final class IntegrationCatalogue
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config) {}

    /** @return array<string, array{label: string, single: bool, modes: bool}> */
    public function types(): array
    {
        /** @var array<string, array{label: string, single: bool, modes: bool}> */
        return $this->config['types'] ?? [];
    }

    /** @return array{label: string, single: bool, modes: bool} */
    public function type(string $type): array
    {
        return $this->types()[$type] ?? throw new InvalidArgumentException("Unknown integration type [{$type}].");
    }

    /** @return array<string, Driver> */
    public function drivers(?string $type = null): array
    {
        /** @var array<string, Driver> $drivers */
        $drivers = $this->config['drivers'] ?? [];

        return $type === null ? $drivers : array_filter($drivers, fn (string $key): bool => str_starts_with($key, $type.'.'), ARRAY_FILTER_USE_KEY);
    }

    public function has(string $driver): bool
    {
        return isset($this->drivers()[$driver]);
    }

    /** @return Driver */
    public function driver(string $driver): array
    {
        return $this->drivers()[$driver] ?? throw new InvalidArgumentException("Unknown integration driver [{$driver}].");
    }

    public function typeOf(string $driver): string
    {
        $this->driver($driver);

        return explode('.', $driver, 2)[0];
    }

    /** @return array<string, Field> */
    public function fields(string $driver): array
    {
        return $this->driver($driver)['fields'];
    }

    public function isSecret(string $driver, string $field): bool
    {
        return (bool) ($this->fields($driver)[$field]['secret'] ?? false);
    }

    /** @return list<string> */
    public function requiredFields(string $driver): array
    {
        return array_keys(array_filter($this->fields($driver), fn (array $f): bool => (bool) ($f['required'] ?? false)));
    }

    /**
     * Laravel validation rules for one driver's fields. Every field is optional here;
     * whether required fields are present is checked after merging with saved values.
     *
     * @return array<string, list<string>>
     */
    public function rules(string $driver): array
    {
        $rules = [];

        foreach ($this->fields($driver) as $name => $field) {
            $rules["values.{$name}"] = match ($field['type'] ?? 'text') {
                'bool' => ['nullable', 'boolean'],
                'number' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'url' => ['nullable', 'string', 'url:https,http', 'max:500'],
                'select' => ['nullable', 'string', 'in:'.implode(',', $field['options'] ?? [])],
                default => ['nullable', 'string', 'max:'.(($field['secret'] ?? false) ? 4000 : 500)],
            };
        }

        return $rules;
    }

    /** True when the driver offers every one of the capabilities. */
    public function supports(string $driver, string ...$capabilities): bool
    {
        return array_diff($capabilities, $this->driver($driver)['capabilities']) === [];
    }
}
