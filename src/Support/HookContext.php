<?php

declare(strict_types=1);

namespace Jengo\Api\Support;

class HookContext
{
    public ?string $version;
    public string $resource;
    public string $method;
    public array $metadata;

    public function __construct(
        ?string $version = null,
        string $resource = '',
        string $method = '',
        array $metadata = []
    ) {
        $this->version = $version;
        $this->resource = $resource;
        $this->method = $method;
        $this->metadata = $metadata;
    }
}
