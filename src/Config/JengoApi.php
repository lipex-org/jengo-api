<?php

declare(strict_types=1);

namespace Jengo\Api\Config;

use CodeIgniter\Config\BaseConfig;

class JengoApi extends BaseConfig
{
    /**
     * Route resources mapping configuration.
     * Maps resource endpoint names to their Jengo API policy rules.
     *
     * Example:
     * public array $resources = [
     *     \App\Api\UsersResource::class,
     * ];
     */
    public array $resources = [];
}
