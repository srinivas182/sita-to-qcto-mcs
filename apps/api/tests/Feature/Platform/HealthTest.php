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

it('grants no cross-origin access', function (): void {
    makeProvider('tym');

    $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])
        ->options('https://tym.skills.test/v1/auth/login')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->withHeaders(['Origin' => 'https://evil.example'])
        ->getJson('https://tym.skills.test/v1/branding')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
