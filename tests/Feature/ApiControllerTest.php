<?php

declare(strict_types=1);

namespace Tests\Feature {

    use Config\Services;
    use Jengo\Api\Router;
    use Tests\TestCase;

    final class ApiControllerTest extends TestCase
    {
        public function setUp(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->dropTable('temp_api_table', true);

            parent::setUp();
            $this->cleanFileSystem();
        }

        public function tearDown(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->dropTable('temp_api_table', true);

            parent::tearDown();
            $this->cleanFileSystem();
        }

        public function testSetupCommandVariant(): void
        {
            command('jengo:api setup');

            $publishedConfig = APPPATH . 'Config/JengoApi.php';
            $this->assertFileExists($publishedConfig);

            $content = file_get_contents($publishedConfig);
            $this->assertStringContainsString('class JengoApi extends BaseJengoApi', $content);
            $this->assertStringContainsString('use Jengo\Api\Config\JengoApi as BaseJengoApi;', $content);
        }

        public function testDynamicApiRoutesAndValidation(): void
        {
            command('jengo:api setup');
            $this->assertFileExists(APPPATH . 'Config/JengoApi.php');

            $forge = \Config\Database::forge('tests');
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_api_table', true);

            $config = config('JengoApi');
            $config->resources = [
                TempApiTableResource::class
            ];

            $routes = Services::routes();
            \Jengo\Api\Router::publish($routes, new \Jengo\Api\Support\RouterOptions(only: ['get', 'post']));

            $this->db->table('temp_api_table')->insert(['title' => 'Initial Title']);

            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController(Services::request(), Services::response(), Services::logger());

            $response = $controller->index('temp_api_table');
            $body = json_decode($response->getBody(), true);

            $this->assertSame('success', $body['status']);
            $this->assertCount(1, $body['data']);
            $this->assertSame('Initial Title', $body['data'][0]['title']);
            $this->assertTrue($body['data'][0]['hook_executed']);

            // Test Sqids obfuscation / deobfuscation
            helper('jengo');
            $obfuscatedId = sqids_hash(1);
            $this->assertNotEmpty($obfuscatedId);

            $showResponse = $controller->show('temp_api_table', $obfuscatedId);
            $showBody = json_decode($showResponse->getBody(), true);

            $this->assertSame('success', $showBody['status']);
            $this->assertSame('Initial Title', $showBody['data']['title']);

            // Assert RFC 7807 problem response on 404
            $notFoundResponse = $controller->show('temp_api_table', '999');
            $notFoundBody = json_decode($notFoundResponse->getBody(), true);
            $this->assertSame('Resource Not Found', $notFoundBody['title']);
            $this->assertSame(404, $notFoundBody['status']);
            $this->assertStringContainsString('not found', $notFoundBody['detail']);
            $this->assertSame('about:blank', $notFoundBody['type']);

            $forge->dropTable('temp_api_table', true);
        }

        public function testMakeApiResourceCommand(): void
        {
            command('jengo:make api_resource UserConfigResource');

            $expectedFile = APPPATH . 'Api/UserConfigResource.php';
            $this->assertFileExists($expectedFile);

            $content = file_get_contents($expectedFile);
            $this->assertStringContainsString('class UserConfigResource extends ResourceConfig', $content);
            $this->assertStringContainsString("return 'userconfig';", $content);

            if (file_exists($expectedFile)) {
                unlink($expectedFile);
            }
            $dir = APPPATH . 'Api';
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        public function testSwaggerDocsGeneration(): void
        {
            $config = config('JengoApi');
            $config->apiName = 'My Custom API Title';
            $config->apiBaseUrl = 'https://myapi.com/v1';
            $config->resources = [
                TempApiTableResource::class
            ];

            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController(Services::request(), Services::response(), Services::logger());

            $response = $controller->docs();
            $body = json_decode($response->getBody(), true);

            $this->assertSame('3.0.0', $body['openapi']);
            $this->assertSame('My Custom API Title', $body['info']['title']);
            $this->assertSame('https://myapi.com/v1', $body['servers'][0]['url']);
            $this->assertArrayHasKey('/temp_api_table', $body['paths']);
            $this->assertArrayHasKey('/temp_api_table/{id}', $body['paths']);
        }

        public function testSwaggerUiGeneration(): void
        {
            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController(Services::request(), Services::response(), Services::logger());

            $response = $controller->docsUi();
            $body = $response->getBody();

            $this->assertStringContainsString('Swagger UI for Jengo API', $body);
            $this->assertStringContainsString('swagger-ui-bundle.js', $body);
        }

        public function testConfigurableDocsRouteOption(): void
        {
            $routes = Services::routes(false);
            Services::injectMock('routes', $routes);

            \Jengo\Api\Router::publish($routes, new \Jengo\Api\Support\RouterOptions(
                docs: new \Jengo\Api\Support\DocsOptions('my-swagger-custom', 'my-swagger-ui')
            ));
            $routesList = $routes->getRoutes('GET');
            $this->assertArrayHasKey('my-swagger-custom', $routesList);
            $this->assertArrayHasKey('my-swagger-ui', $routesList);
        }

        public function testNestedRelationshipMutations(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'name' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_users', true);

            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
                'temp_user_id' => ['type' => 'INTEGER', 'null' => true],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_posts', true);

            $config = config('JengoApi');
            $config->resources = [
                TempUsersResource::class,
                TempPostsResource::class
            ];

            $request = Services::request(null, false);
            $request->setBody(json_encode([
                'name' => 'Alice',
                'temp_posts' => [
                    ['title' => 'Alice Post 1'],
                    ['title' => 'Alice Post 2']
                ]
            ]));
            $request->setHeader('Content-Type', 'application/json');

            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController($request, Services::response(), Services::logger());

            $response = $controller->create('temp_users');
            $body = json_decode($response->getBody(), true);

            $this->assertSame('success', $body['status']);
            $db = \Config\Database::connect('tests');
            $this->assertSame(1, $db->table('temp_users')->countAllResults());
            $this->assertSame(2, $db->table('temp_posts')->countAllResults());

            $posts = $db->table('temp_posts')->get()->getResultArray();
            $this->assertEquals(1, $posts[0]['temp_user_id']);
            $this->assertEquals(1, $posts[1]['temp_user_id']);

            $forge->dropTable('temp_users', true);
            $forge->dropTable('temp_posts', true);
        }

        public function testVersionedApiRoutes(): void
        {
            $routes = Services::routes(false);
            Services::injectMock('routes', $routes);

            \Jengo\Api\Router::publish($routes, new \Jengo\Api\Support\RouterOptions(
                version: 'v1'
            ));

            $routesList = $routes->getRoutes('GET');
            $this->assertArrayHasKey('v1/docs', $routesList);
            $this->assertArrayHasKey('v1/docs/ui', $routesList);
            $this->assertArrayHasKey('v1/([^/]+)', $routesList);
        }

        public function testRouterOptionsDtoUsage(): void
        {
            $routes = Services::routes(false);
            Services::injectMock('routes', $routes);

            $options = new \Jengo\Api\Support\RouterOptions(
                version: 'v2',
                docs: new \Jengo\Api\Support\DocsOptions('my-v2-swagger', 'my-v2-swagger-ui')
            );

            \Jengo\Api\Router::publish($routes, $options);

            $routesList = $routes->getRoutes('GET');
            $this->assertArrayHasKey('v2/my-v2-swagger', $routesList);
            $this->assertArrayHasKey('v2/my-v2-swagger-ui', $routesList);
        }

        public function testRouterOptionsChainedMutation(): void
        {
            $routes = Services::routes(false);
            Services::injectMock('routes', $routes);

            $v1Options = new \Jengo\Api\Support\RouterOptions(
                version: 'v1',
                docs: new \Jengo\Api\Support\DocsOptions(route: 'docs.json', uiRoute: 'docs')
            );

            \Jengo\Api\Router::publish($routes, $v1Options)->mutate(
                new \Jengo\Api\Support\RouterOptions(version: 'v2')
            );

            $routesList = $routes->getRoutes('GET');
            $this->assertArrayHasKey('v1/docs.json', $routesList);
            $this->assertArrayHasKey('v1/docs', $routesList);
            $this->assertArrayHasKey('v2/docs.json', $routesList);
            $this->assertArrayHasKey('v2/docs', $routesList);
        }

        public function testResourceConfigArrayVersion(): void
        {
            $versionArray = ['v1', 'v2'];
            $this->assertTrue(\Jengo\Api\Services\RequestProcessor::matchVersion($versionArray, 'v1'));
            $this->assertTrue(\Jengo\Api\Services\RequestProcessor::matchVersion($versionArray, 'v2'));
            $this->assertFalse(\Jengo\Api\Services\RequestProcessor::matchVersion($versionArray, 'v3'));
            $this->assertFalse(\Jengo\Api\Services\RequestProcessor::matchVersion($versionArray, null));
            $this->assertTrue(\Jengo\Api\Services\RequestProcessor::matchVersion(null, 'v1'));
        }

        public function testBulkWritesAndMutations(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_bulk_table', true);

            $config = config('JengoApi');
            $config->resources = [
                TempBulkResource::class
            ];

            // 1. Bulk Create
            $request = Services::request(null, false);
            $request->setBody(json_encode([
                ['title' => 'Bulk Item 1'],
                ['title' => 'Bulk Item 2']
            ]));
            $request->setHeader('Content-Type', 'application/json');

            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController($request, Services::response(), Services::logger());

            $response = $controller->create('temp_bulk_table');
            $body = json_decode($response->getBody(), true);

            $this->assertSame('success', $body['status']);
            $this->assertCount(2, $body['data']);
            $this->assertSame('Bulk Item 1', $body['data'][0]['title']);
            $this->assertSame('Bulk Item 2', $body['data'][1]['title']);

            // 2. Bulk Update
            $id1 = $body['data'][0]['id'];
            $id2 = $body['data'][1]['id'];

            $updateRequest = Services::request(null, false);
            $updateRequest->setBody(json_encode([
                ['id' => $id1, 'title' => 'Updated Bulk 1'],
                ['id' => $id2, 'title' => 'Updated Bulk 2']
            ]));
            $updateRequest->setHeader('Content-Type', 'application/json');

            $controller2 = new \Jengo\Api\Controllers\ApiController();
            $controller2->initController($updateRequest, Services::response(), Services::logger());

            $response2 = $controller2->update('temp_bulk_table');
            $body2 = json_decode($response2->getBody(), true);

            $this->assertSame('success', $body2['status']);
            $this->assertCount(2, $body2['data']);
            $this->assertSame('Updated Bulk 1', $body2['data'][0]['title']);
            $this->assertSame('Updated Bulk 2', $body2['data'][1]['title']);

            $forge->dropTable('temp_bulk_table', true);
        }

        public function testShieldAuthIntegration(): void
        {
            MockShieldAuth::getInstance()->user = null;
            \Jengo\Api\Services\RequestProcessor::clearCache();

            $forge = \Config\Database::forge('tests');
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_auth_table', true);

            $config = config('JengoApi');
            $config->resources = [
                TempAuthResource::class
            ];

            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController(Services::request(), Services::response(), Services::logger());

            $response = $controller->index('temp_auth_table');
            $body = json_decode($response->getBody(), true);

            $this->assertSame(401, $body['status'] ?? $body['error']);
            $this->assertSame('Authentication required to access this resource.', $body['detail']);

            $user = new MockUser();
            $user->permissions = [];
            MockShieldAuth::getInstance()->user = $user;
            \Jengo\Api\Services\RequestProcessor::clearCache();

            $response = $controller->index('temp_auth_table');
            $body = json_decode($response->getBody(), true);

            $this->assertSame(403, $body['status'] ?? $body['error']);
            $this->assertSame("Insufficient permissions. Required permission: 'temp.read'.", $body['detail']);

            $user->permissions = ['temp.read'];
            \Jengo\Api\Services\RequestProcessor::clearCache();

            $response = $controller->index('temp_auth_table');
            $body = json_decode($response->getBody(), true);

            $this->assertSame('success', $body['status']);

            $forge->dropTable('temp_auth_table', true);
        }

        private function cleanFileSystem(): void
        {
            $publishedConfig = APPPATH . 'Config/JengoApi.php';
            if (file_exists($publishedConfig)) {
                unlink($publishedConfig);
            }
        }
    }

    class MockUser
    {
        public array $permissions = [];

        public function hasPermission(string $permission): bool
        {
            return in_array($permission, $this->permissions, true);
        }
    }

    class MockShieldAuth
    {
        private static ?MockShieldAuth $instance = null;
        public ?MockUser $user = null;

        public static function getInstance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function user(): ?MockUser
        {
            return $this->user;
        }
    }

    class TempAuthResource extends \Jengo\Api\Support\ResourceConfig
    {
        protected array $requiredAuth = [
            'get' => 'temp.read'
        ];

        public function name(): string
        {
            return 'temp_auth_table';
        }
    }

    class TempUsersResource extends \Jengo\Api\Support\ResourceConfig
    {
        public function name(): string
        {
            return 'temp_users';
        }

        public function allowedRelations(): array
        {
            return ['temp_posts'];
        }
    }

    class TempPostsResource extends \Jengo\Api\Support\ResourceConfig
    {
        public function name(): string
        {
            return 'temp_posts';
        }
    }

    class TempApiTableResource extends \Jengo\Api\Support\ResourceConfig
    {
        protected array $obfuscatedFields = ['id'];

        public function name(): string
        {
            return 'temp_api_table';
        }

        public function afterQuery(array $data, ?\Jengo\Api\Support\HookContext $context = null): array
        {
            foreach ($data as $row) {
                if (is_object($row)) {
                    $row->hook_executed = true;
                }
            }
            return $data;
        }
    }

    class TempBulkResource extends \Jengo\Api\Support\ResourceConfig
    {
        public function name(): string
        {
            return 'temp_bulk_table';
        }
    }
}

namespace {
    if (!function_exists('auth')) {
        function auth() {
            return \Tests\Feature\MockShieldAuth::getInstance();
        }
    }
}
