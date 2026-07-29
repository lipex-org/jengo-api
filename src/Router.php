<?php

declare(strict_types=1);

namespace Jengo\Api;

use CodeIgniter\Router\RouteCollection;
use Jengo\Api\Controllers\ApiController;

class Router
{
    /**
     * Automatically registers Jengo REST routes for a schema resource.
     */
    public static function publish(RouteCollection $routes, array $options = []): void
    {
        $except = array_map('strtolower', $options['except'] ?? []);
        $only = array_map('strtolower', $options['only'] ?? []);

        // Register dynamic API documentation endpoint if not disabled
        $docsRoute = $options['docs'] ?? 'docs';
        if ($docsRoute !== false) {
            $routes->get($docsRoute, [ApiController::class, 'docs'], ['as' => 'api-docs']);
        }

        // Register dynamic API documentation UI endpoint if not disabled
        $docsUiRoute = $options['docs_ui'] ?? 'docs/ui';
        if ($docsUiRoute !== false) {
            $routes->get($docsUiRoute, [ApiController::class, 'docsUi'], ['as' => 'api-docs-ui']);
        }

        $routes->group('(:segment)', static function ($routes) use ($except, $only) {
            $register = static function (string $verb, string $route, $callback) use ($routes, $except, $only) {
                if (!empty($only) && !in_array($verb, $only, true)) {
                    return;
                }
                if (!empty($except) && in_array($verb, $except, true)) {
                    return;
                }
                $routes->{$verb}($route, $callback);
            };

            $register('get', '/', [ApiController::class, 'index']);
            $register('get', '(:segment)', [ApiController::class, 'show']);
            $register('post', '/', [ApiController::class, 'create']);
            $register('put', '(:segment)', [ApiController::class, 'update']);
            $register('patch', '(:segment)', [ApiController::class, 'update']);
            $register('delete', '(:segment)', [ApiController::class, 'delete']);
        });
    }
}