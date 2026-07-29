<?php

declare(strict_types=1);

namespace Jengo\Api\Services;

use Jengo\Api\Contracts\ResourceConfigInterface;
use Jengo\Schema\Reflection\SchemaReflector;

class SwaggerGenerator
{
    /**
     * Compile active API resource configurations and database schemas to OpenAPI 3.0.
     */
    public static function generate(): array
    {
        $config = config('JengoApi');
        $apiName = $config->apiName ?? 'Jengo Auto-Generated API';
        $apiBaseUrl = $config->apiBaseUrl ?? '';
        if ($apiBaseUrl === '') {
            helper('url');
            $apiBaseUrl = site_url();
        }

        $openapi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $apiName,
                'version' => '1.0.0',
                'description' => 'Dynamic REST API documentation generated automatically by Jengo.',
            ],
            'servers' => [
                [
                    'url' => $apiBaseUrl,
                ]
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
            ],
        ];

        foreach ($config->resources as $resClass) {
            if (!class_exists($resClass)) {
                continue;
            }

            $resObj = new $resClass();
            if (!$resObj instanceof ResourceConfigInterface) {
                continue;
            }

            $name = $resObj->name();
            $exposedMethods = array_map('strtolower', $resObj->exposedMethods());
            $capabilities = $resObj->capabilities();
            $allowedRelations = $resObj->allowedRelations();
            $maxLimit = $resObj->maxLimit();

            $properties = [];
            try {
                $metadata = SchemaReflector::reflect($name);
                foreach ($metadata->fields as $field) {
                    $type = 'string';
                    if (in_array('int', $field->type->types, true)) {
                        $type = 'integer';
                    } elseif (in_array('float', $field->type->types, true) || in_array('double', $field->type->types, true)) {
                        $type = 'number';
                    } elseif (in_array('bool', $field->type->types, true)) {
                        $type = 'boolean';
                    }
                    $properties[$field->name] = [
                        'type' => $type,
                    ];
                }
            } catch (\Throwable $e) {
                $properties = [
                    'id' => ['type' => 'integer'],
                ];
            }

            $schemaName = ucfirst($name);
            $openapi['components']['schemas'][$schemaName] = [
                'type' => 'object',
                'properties' => $properties,
            ];

            $listPath = "/{$name}";
            $openapi['paths'][$listPath] = [];

            if (in_array('get', $exposedMethods, true)) {
                $parameters = [];
                if (in_array('pagination', $capabilities, true)) {
                    $parameters[] = [
                        'name' => 'page',
                        'in' => 'query',
                        'description' => 'Page number for pagination',
                        'required' => false,
                        'schema' => ['type' => 'integer', 'default' => 1],
                    ];
                    $parameters[] = [
                        'name' => 'limit',
                        'in' => 'query',
                        'description' => "Maximum items to return (max: {$maxLimit})",
                        'required' => false,
                        'schema' => ['type' => 'integer', 'default' => 20],
                    ];
                }
                if (in_array('search', $capabilities, true)) {
                    $parameters[] = [
                        'name' => 'search',
                        'in' => 'query',
                        'description' => 'Search term to filter records',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                    ];
                }
                if (in_array('sort', $capabilities, true)) {
                    $parameters[] = [
                        'name' => 'sort',
                        'in' => 'query',
                        'description' => 'Sorting criteria (e.g., -id or name)',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                    ];
                }
                if (!empty($allowedRelations)) {
                    $parameters[] = [
                        'name' => 'derive',
                        'in' => 'query',
                        'description' => 'Comma-separated list of relationships to load: ' . implode(', ', $allowedRelations),
                        'required' => false,
                        'schema' => ['type' => 'string'],
                    ];
                }

                $openapi['paths'][$listPath]['get'] = [
                    'summary' => "Retrieve a paginated collection of {$name}",
                    'parameters' => $parameters,
                    'responses' => [
                        '200' => [
                            'description' => 'Success',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string', 'example' => 'success'],
                                            'data' => [
                                                'type' => 'array',
                                                'items' => ['$ref' => "#/components/schemas/{$schemaName}"],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            if (in_array('post', $exposedMethods, true)) {
                $openapi['paths'][$listPath]['post'] = [
                    'summary' => "Create a new {$name} record",
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}",
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Created successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string', 'example' => 'success'],
                                            'data' => ['$ref' => "#/components/schemas/{$schemaName}"],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            $itemPath = "/{$name}/{id}";
            $openapi['paths'][$itemPath] = [];

            if (in_array('get', $exposedMethods, true)) {
                $openapi['paths'][$itemPath]['get'] = [
                    'summary' => "Retrieve a single {$name} record by ID",
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Success',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string', 'example' => 'success'],
                                            'data' => ['$ref' => "#/components/schemas/{$schemaName}"],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            if (in_array('put', $exposedMethods, true) || in_array('patch', $exposedMethods, true)) {
                $methodKey = in_array('put', $exposedMethods, true) ? 'put' : 'patch';
                $openapi['paths'][$itemPath][$methodKey] = [
                    'summary' => "Update an existing {$name} record by ID",
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}",
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string', 'example' => 'success'],
                                            'data' => ['$ref' => "#/components/schemas/{$schemaName}"],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            if (in_array('delete', $exposedMethods, true)) {
                $openapi['paths'][$itemPath]['delete'] = [
                    'summary' => "Delete a {$name} record by ID",
                    'parameters' => [
                        [
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Deleted successfully',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string', 'example' => 'success'],
                                            'message' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        }

        return $openapi;
    }
}
