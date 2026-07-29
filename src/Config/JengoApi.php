<?php

declare(strict_types=1);

namespace Jengo\Api\Config;

use CodeIgniter\Config\BaseConfig;

class JengoApi extends BaseConfig
{
    /**
     * The name of the API, used in generated OpenAPI/Swagger documentation.
     */
    public string $apiName = 'Jengo Auto-Generated API';

    /**
     * The base URL of the API, used in generated OpenAPI/Swagger servers block.
     * If empty, Jengo will automatically use CodeIgniter's site_url().
     */
    public string $apiBaseUrl = '/api';

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
