<?php

declare(strict_types=1);

it('answers the liveness probe without touching dependencies', function (): void {
    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'service', 'version', 'cell', 'time']);
});

it('reports ready when the database and redis respond', function (): void {
    $this->getJson('/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true);
});

it('serves a json landing response on the root', function (): void {
    $this->getJson('/')->assertOk()->assertJsonPath('name', 'Skills Ecosystem API');
});
