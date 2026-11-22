<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Identity\Models\User;
use Modules\Settings\Models\Integration;
use Modules\Settings\Services\IntegrationCatalogue;
use Modules\Settings\Services\IntegrationService;

/** Settings → Integrations (payments, SMS, WhatsApp, email, storage). Super Admin. */
final class IntegrationController
{
    /** The catalogue of drivers with their fields, and what is set up for each. */
    public function index(): JsonResponse
    {
        $catalogue = $this->catalogue();
        $saved = Integration::query()->get()->keyBy('driver');

        $types = [];
        foreach ($catalogue->types() as $type => $meta) {
            $drivers = [];
            foreach ($catalogue->drivers($type) as $key => $driver) {
                $integration = $saved->get($key);
                $drivers[] = [
                    'driver' => $key,
                    'label' => $driver['label'],
                    'description' => $driver['description'],
                    'capabilities' => $driver['capabilities'],
                    'note' => $driver['note'] ?? null,
                    'fields' => collect($driver['fields'])->map(fn (array $f, string $name): array => [
                        'name' => $name,
                        'label' => $f['label'],
                        'secret' => (bool) ($f['secret'] ?? false),
                        'required' => (bool) ($f['required'] ?? false),
                        'type' => $f['type'] ?? 'text',
                        'options' => $f['options'] ?? null,
                        'default' => $f['default'] ?? null,
                        'help' => $f['help'] ?? null,
                    ])->values(),
                    'integration' => $integration === null ? null : $this->service()->present($integration),
                ];
            }

            usort($drivers, fn (array $a, array $b): int => [
                $a['integration'] === null, $a['integration']['priority'] ?? 0,
            ] <=> [
                $b['integration'] === null, $b['integration']['priority'] ?? 0,
            ]);

            $types[] = ['type' => $type, ...$meta, 'drivers' => $drivers];
        }

        return response()->json(['data' => $types]);
    }

    public function save(Request $request, string $driver): JsonResponse
    {
        /** @var array{values?: array<string, mixed>, mode?: string, enabled?: bool} $input */
        $input = $request->only(['values', 'mode', 'enabled']);
        $integration = $this->service()->save($driver, $input, $this->actor($request));

        return response()->json(['data' => $this->service()->present($integration)]);
    }

    public function enable(Request $request, string $integration): JsonResponse
    {
        $model = $this->service()->enable(Integration::query()->findOrFail($integration), $this->actor($request));

        return response()->json(['data' => $this->service()->present($model)]);
    }

    public function disable(Request $request, string $integration): JsonResponse
    {
        $model = $this->service()->disable(Integration::query()->findOrFail($integration), $this->actor($request));

        return response()->json(['data' => $this->service()->present($model)]);
    }

    public function test(string $integration): JsonResponse
    {
        $model = Integration::query()->findOrFail($integration);
        $result = $this->service()->test($model);

        return response()->json(['ok' => $result->ok, 'message' => $result->message, 'data' => $this->service()->present($model)]);
    }

    public function reorder(Request $request): Response
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys($this->catalogue()->types()))],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string', 'ulid'],
        ]);
        /** @var list<string> $ids */
        $ids = array_values($data['ids']);
        $this->service()->reorder($data['type'], $ids, $this->actor($request));

        return response()->noContent();
    }

    public function destroy(string $integration): Response
    {
        $this->service()->remove(Integration::query()->findOrFail($integration));

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function service(): IntegrationService
    {
        return app(IntegrationService::class);
    }

    private function catalogue(): IntegrationCatalogue
    {
        return app(IntegrationCatalogue::class);
    }
}
