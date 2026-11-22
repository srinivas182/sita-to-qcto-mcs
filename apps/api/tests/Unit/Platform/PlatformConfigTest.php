<?php

declare(strict_types=1);

it('defines a default cell and base domain for the platform', function (): void {
    $config = require __DIR__.'/../../../config/platform.php';

    expect($config)->toHaveKeys(['cell', 'base_domain'])
        ->and($config['cell'])->toBeString()->not->toBeEmpty();
});
