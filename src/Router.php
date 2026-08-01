<?php

declare(strict_types=1);

namespace Jengo\Api;

use CodeIgniter\Router\RouteCollection;
use Jengo\Api\Controllers\ApiController;
use Jengo\Api\Support\RouterOptions;

class Router
{
    protected RouteCollection $routes;
    protected RouterOptions $options;

    public function __construct(RouteCollection $routes, ?RouterOptions $options = null)
    {
        $this->routes = $routes;
        $this->options = $options ?? new RouterOptions();
    }

    /**
     * Automatically registers Jengo REST routes and returns a Router instance for chaining.
     */
    public static function publish(RouteCollection $routes, ?RouterOptions $options = null): self
    {
        $router = new self($routes, $options);
        $router->publishRoutes();
        return $router;
    }


    /**
     * Mutate the router settings, publish the new routes, and return the new Router instance.
     */
    public function mutate(RouterOptions $overrides): self
    {
        $mutatedOptions = $this->options->mutate($overrides);
        $mutatedRouter = new self($this->routes, $mutatedOptions);
        $mutatedRouter->publishRoutes();
        return $mutatedRouter;
    }

    /**
     * Internal method to publish the configured routes to the route collection.
     */
    private function publishRoutes(): void
    {
        $except = array_map('strtolower', $this->options->except);
        $only = array_map('strtolower', $this->options->only);
        $version = $this->options->version;
        $options = $this->options;

        $registerRoutes = static function ($routes) use ($except, $only, $options, $version) {
            // Register dynamic API documentation endpoint if not disabled
            $docsRoute = $options->docs->route;
            if ($docsRoute !== false) {
                $routeName = ($version ? $version . '-' : '') . 'api-docs';
                $routes->get($docsRoute, [ApiController::class, 'docs'], ['as' => $routeName]);
            }

            // Register dynamic API documentation UI endpoint if not disabled
            $docsUiRoute = $options->docs->uiRoute;
            if ($docsUiRoute !== false) {
                $routeNameUi = ($version ? $version . '-' : '') . 'api-docs-ui';
                $routes->get($docsUiRoute, [ApiController::class, 'docsUi'], ['as' => $routeNameUi]);
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
        };

        if ($version) {
            $this->routes->group($version, $registerRoutes);
        } else {
            $registerRoutes($this->routes);
        }
    }
}