<?php

declare(strict_types=1);

namespace Jengo\Api\Services;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use Jengo\Api\Contracts\ResourceConfigInterface;
use Jengo\Api\Exceptions\ApiException;

class RequestProcessor
{
    private static array $resolvedConfigs = [];

    /**
     * Process and validate the incoming API request against the resource configuration.
     *
     * @throws ApiException
     * @throws PageNotFoundException
     */
    public static function process(string $resource, string $method, RequestInterface $request): array
    {
        $config = config('JengoApi');
        $resourceConfig = null;

        if (isset(self::$resolvedConfigs[$resource])) {
            $resourceConfig = self::$resolvedConfigs[$resource];
        } else {
            foreach ($config->resources as $resClass) {
                if (class_exists($resClass)) {
                    $resObj = new $resClass();
                    if ($resObj instanceof ResourceConfigInterface && $resObj->name() === $resource) {
                        $resourceConfig = [
                            'form' => $resObj->formClass(),
                            'exposed_methods' => $resObj->exposedMethods(),
                            'capabilities' => $resObj->capabilities(),
                            'allowed_relations' => $resObj->allowedRelations(),
                            'max_limit' => $resObj->maxLimit(),
                            'obfuscated_fields' => $resObj->obfuscatedFields(),
                        ];
                        self::$resolvedConfigs[$resource] = $resourceConfig;
                        break;
                    }
                }
            }
        }

        // 1. Check if resource is exposed
        if ($resourceConfig === null) {
            throw PageNotFoundException::forPageNotFound("Resource '{$resource}' is not exposed.");
        }

        // 2. Validate HTTP method
        $exposedMethods = $resourceConfig['exposed_methods'] ?? ['get', 'post', 'put', 'patch', 'delete'];
        $exposedMethods = array_map('strtolower', $exposedMethods);
        $method = strtolower($method);

        if (!in_array($method, $exposedMethods, true)) {
            throw new ApiException("Method '{$method}' is not allowed for resource '{$resource}'.", 405);
        }

        // 3. Capability security checks for GET requests
        if ($method === 'get') {
            $allowedCapabilities = $resourceConfig['capabilities'] ?? ['pagination', 'search', 'sort'];
            $allowedRelations = $resourceConfig['allowed_relations'] ?? [];
            $maxLimit = $resourceConfig['max_limit'] ?? 100;

            $getParams = $request->getGet();

            if (isset($getParams['search']) && !in_array('search', $allowedCapabilities, true)) {
                unset($_GET['search']);
            }
            if (isset($getParams['sort']) && !in_array('sort', $allowedCapabilities, true)) {
                unset($_GET['sort']);
            }
            if (isset($getParams['page']) && !in_array('pagination', $allowedCapabilities, true)) {
                unset($_GET['page']);
            }
            if (isset($getParams['limit'])) {
                if (!in_array('pagination', $allowedCapabilities, true)) {
                    unset($_GET['limit']);
                } else {
                    $limitVal = (int) $getParams['limit'];
                    if ($limitVal > $maxLimit) {
                        $_GET['limit'] = $maxLimit;
                    }
                }
            }

            if (isset($getParams['derive'])) {
                $derivations = array_filter(array_map('trim', explode(',', (string) $getParams['derive'])));
                $validDerivations = [];
                foreach ($derivations as $rel) {
                    $rootRel = explode('.', $rel)[0];
                    if (in_array($rootRel, $allowedRelations, true)) {
                        $validDerivations[] = $rel;
                    }
                }
                if (empty($validDerivations)) {
                    unset($_GET['derive']);
                } else {
                    $_GET['derive'] = implode(',', $validDerivations);
                }
            }
        }

        // 4. Deobfuscation of query string parameters
        $obfuscatedFields = $resourceConfig['obfuscated_fields'] ?? [];
        if (!empty($obfuscatedFields)) {
            helper('jengo');
            foreach ($obfuscatedFields as $field) {
                if (isset($_GET[$field]) && is_string($_GET[$field]) && $_GET[$field] !== '') {
                    $unhashed = sqids_unhash($_GET[$field]);
                    if ($unhashed !== null) {
                        $_GET[$field] = $unhashed;
                    }
                }
            }
        }

        return $resourceConfig;
    }
}
