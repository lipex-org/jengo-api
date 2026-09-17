<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Config\Services;
use Jengo\Api\Router;
use Jengo\Api\Support\DocsOptions;
use Jengo\Api\Support\RouterOptions;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    public function testPublishWithOnlyOption(): void
    {
        $routes = Services::routes(false);
        Services::injectMock('routes', $routes);

        Router::publish($routes, new RouterOptions(only: ['get']));

        $getRoutes = $routes->getRoutes('GET');
        $postRoutes = $routes->getRoutes('POST');
        $deleteRoutes = $routes->getRoutes('DELETE');

        $this->assertNotEmpty($getRoutes);
        $this->assertEmpty($postRoutes);
        $this->assertEmpty($deleteRoutes);
    }

    public function testPublishWithExceptOption(): void
    {
        $routes = Services::routes(false);
        Services::injectMock('routes', $routes);

        Router::publish($routes, new RouterOptions(except: ['delete', 'put', 'patch']));

        $getRoutes = $routes->getRoutes('GET');
        $postRoutes = $routes->getRoutes('POST');
        $deleteRoutes = $routes->getRoutes('DELETE');
        $putRoutes = $routes->getRoutes('PUT');

        $this->assertNotEmpty($getRoutes);
        $this->assertNotEmpty($postRoutes);
        $this->assertEmpty($deleteRoutes);
        $this->assertEmpty($putRoutes);
    }

    public function testPublishWithDisabledDocs(): void
    {
        $routes = Services::routes(false);
        Services::injectMock('routes', $routes);

        Router::publish($routes, new RouterOptions(
            docs: new DocsOptions(route: false, uiRoute: false)
        ));

        $getRoutes = $routes->getRoutes('GET');

        $this->assertArrayNotHasKey('docs', $getRoutes);
        $this->assertArrayNotHasKey('docs/ui', $getRoutes);
    }

    public function testPublishWithCustomDocsRoutes(): void
    {
        $routes = Services::routes(false);
        Services::injectMock('routes', $routes);

        Router::publish($routes, new RouterOptions(
            version: 'v3',
            docs: new DocsOptions(route: 'openapi.json', uiRoute: 'explorer')
        ));

        $getRoutes = $routes->getRoutes('GET');

        $this->assertArrayHasKey('v3/openapi.json', $getRoutes);
        $this->assertArrayHasKey('v3/explorer', $getRoutes);
    }
}
