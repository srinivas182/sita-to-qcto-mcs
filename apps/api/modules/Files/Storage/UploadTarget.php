<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

final readonly class UploadTarget
{
    /** @param  array<string, string>  $headers  headers the client must send */
    public function __construct(public string $method, public string $url, public array $headers = []) {}

    /** @return array{method: string, url: string, headers: object} */
    public function toArray(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'headers' => (object) $this->headers];
    }
}
