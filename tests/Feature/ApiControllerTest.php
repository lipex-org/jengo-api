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

        public function testArrayFormClassHandling(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_array_form_table', true);

            $config = config('JengoApi');
            $config->resources = [
                TempArrayFormResource::class
            ];

            // 1. POST (using MockFormHandler) should succeed!
            $request = Services::request(null, false);
            $request->setBody(json_encode(['title' => 'Array Form POST']));
            $request->setHeader('Content-Type', 'application/json');

            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController($request, Services::response(), Services::logger());

            $response = $controller->create('temp_array_form_table');
            $body = json_decode($response->getBody(), true);
            $this->assertSame('success', $body['status']);

            // 2. PUT (using non-existent Form class) should return 404 Not Found!
            $updateRequest = Services::request(null, false);
            $updateRequest->setBody(json_encode(['title' => 'Array Form PUT']));
            $updateRequest->setHeader('Content-Type', 'application/json');

            $controller2 = new \Jengo\Api\Controllers\ApiController();
            $controller2->initController($updateRequest, Services::response(), Services::logger());

            $response2 = $controller2->update('temp_array_form_table', '1');
            $this->assertSame(404, $response2->getStatusCode());
            $forge->dropTable('temp_array_form_table', true);
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

        public function testSingleUpdateAndDeleteLifecycle(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->dropTable('temp_lifecycle_table', true);
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_lifecycle_table', true);

            $this->db->table('temp_lifecycle_table')->insert(['title' => 'Original Title']);
            $insertedId = (string) $this->db->insertID();

            $config = config('JengoApi');
            $config->resources = [
                TempLifecycleResource::class
            ];

            $controller = new \Jengo\Api\Controllers\ApiController();

            // 1. Single PUT update by ID in URL
            $putRequest = Services::request(null, false);
            $putRequest->setMethod('PUT');
            $putRequest->setBody(json_encode(['title' => 'Updated via PUT']));
            $putRequest->setHeader('Content-Type', 'application/json');

            $controller->initController($putRequest, Services::response(), Services::logger());
            $response = $controller->update('temp_lifecycle_table', $insertedId);
            $body = json_decode($response->getBody(), true);

            $this->assertSame('success', $body['status']);
            $this->assertSame('Updated via PUT', $body['data']['title']);

            // 2. Single PATCH update by ID in body
            $patchRequest = Services::request(null, false);
            $patchRequest->setMethod('PATCH');
            $patchRequest->setBody(json_encode(['id' => (int) $insertedId, 'title' => 'Patched via Payload']));
            $patchRequest->setHeader('Content-Type', 'application/json');

            $controller->initController($patchRequest, Services::response(), Services::logger());
            $patchResponse = $controller->update('temp_lifecycle_table');
            $patchBody = json_decode($patchResponse->getBody(), true);

            $this->assertSame('success', $patchBody['status']);
            $this->assertSame('Patched via Payload', $patchBody['data']['title']);

            // 3. Update without ID anywhere -> 400 Bad Request
            $noIdRequest = Services::request(null, false);
            $noIdRequest->setMethod('PUT');
            $noIdRequest->setBody(json_encode(['title' => 'No ID']));
            $noIdRequest->setHeader('Content-Type', 'application/json');

            $controller->initController($noIdRequest, Services::response(), Services::logger());
            $noIdResponse = $controller->update('temp_lifecycle_table');
            $this->assertSame(400, $noIdResponse->getStatusCode());

            // 4. Update non-existent record -> 404
            $notFoundPut = Services::request(null, false);
            $notFoundPut->setMethod('PUT');
            $notFoundPut->setBody(json_encode(['title' => 'Ghost']));
            $notFoundPut->setHeader('Content-Type', 'application/json');

            $controller->initController($notFoundPut, Services::response(), Services::logger());
            $notFoundPutResp = $controller->update('temp_lifecycle_table', '99999');
            $this->assertSame(404, $notFoundPutResp->getStatusCode());

            // 5. Delete record by ID
            $delRequest = Services::request(null, false);
            $delRequest->setMethod('DELETE');
            $controller->initController($delRequest, Services::response(), Services::logger());
            $delResponse = $controller->delete('temp_lifecycle_table', $insertedId);

            $this->assertSame(200, $delResponse->getStatusCode());
            $this->assertSame(0, $this->db->table('temp_lifecycle_table')->countAllResults());

            // 6. Delete already deleted / non-existent record -> 404
            $del404Response = $controller->delete('temp_lifecycle_table', $insertedId);
            $this->assertSame(404, $del404Response->getStatusCode());

            $forge->dropTable('temp_lifecycle_table', true);
        }

        public function testValidationFailureRfc7807Format(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_failing_val_table', true);

            $config = config('JengoApi');
            $config->resources = [
                TempFailingValResource::class
            ];

            $request = Services::request(null, false);
            $request->setBody(json_encode(['title' => '']));
            $request->setHeader('Content-Type', 'application/json');

            $controller = new \Jengo\Api\Controllers\ApiController();
            $controller->initController($request, Services::response(), Services::logger());

            $response = $controller->create('temp_failing_val_table');
            $body = json_decode($response->getBody(), true);

            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('Validation Failed', $body['title']);
            $this->assertSame('about:blank', $body['type']);
            $this->assertNotEmpty($body['invalid_params']);
            $this->assertSame('title', $body['invalid_params'][0]['name']);

            // Verify transaction was rolled back
            $this->assertSame(0, $this->db->table('temp_failing_val_table')->countAllResults());

            $forge->dropTable('temp_failing_val_table', true);
        }

        public function testSqidsObfuscationAcrossAllCrud(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->dropTable('temp_sqids_crud', true);
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_sqids_crud', true);

            $this->db->table('temp_sqids_crud')->insert(['title' => 'Sqids Initial']);
            $rowId = (int) $this->db->insertID();

            $config = config('JengoApi');
            $config->resources = [
                TempSqidsCrudResource::class
            ];

            helper('jengo');
            $hashedId = sqids_hash($rowId);

            $controller = new \Jengo\Api\Controllers\ApiController();

            // 1. Show by sqid hash
            $controller->initController(Services::request(), Services::response(), Services::logger());
            $showResp = $controller->show('temp_sqids_crud', $hashedId);
            $showBody = json_decode($showResp->getBody(), true);
            $this->assertSame('success', $showBody['status']);
            $this->assertSame('Sqids Initial', $showBody['data']['title']);

            // 2. Update by sqid hash in URL
            $putReq = Services::request(null, false);
            $putReq->setMethod('PUT');
            $putReq->setBody(json_encode(['title' => 'Sqids Updated']));
            $putReq->setHeader('Content-Type', 'application/json');

            $controller->initController($putReq, Services::response(), Services::logger());
            $putResp = $controller->update('temp_sqids_crud', $hashedId);
            $putBody = json_decode($putResp->getBody(), true);
            $this->assertSame('success', $putBody['status']);
            $this->assertSame('Sqids Updated', $putBody['data']['title']);

            // 3. Delete by sqid hash in URL
            $delReq = Services::request(null, false);
            $delReq->setMethod('DELETE');
            $controller->initController($delReq, Services::response(), Services::logger());
            $delResp = $controller->delete('temp_sqids_crud', $hashedId);
            $this->assertSame(200, $delResp->getStatusCode());
            $this->assertSame(0, $this->db->table('temp_sqids_crud')->countAllResults());

            // 4. Invalid hash query does not trigger 500
            $invalidShow = $controller->show('temp_sqids_crud', 'invalid-non-sqid');
            $this->assertSame(404, $invalidShow->getStatusCode());

            $forge->dropTable('temp_sqids_crud', true);
        }

        public function testBeforeAndAfterHooksExecution(): void
        {
            $forge = \Config\Database::forge('tests');
            $forge->dropTable('temp_hooks_table', true);
            $forge->addField([
                'id' => ['type' => 'INTEGER', 'auto_increment' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            ]);
            $forge->addPrimaryKey('id');
            $forge->createTable('temp_hooks_table', true);

            $config = config('JengoApi');
            $config->resources = [
                TempHooksResource::class
            ];

            $controller = new \Jengo\Api\Controllers\ApiController();

            // Create: beforeSave should uppercase title, afterSave should set after_save_flag
            $req = Services::request(null, false);
            $req->setBody(json_encode(['title' => 'lowercase title']));
            $req->setHeader('Content-Type', 'application/json');

            $controller->initController($req, Services::response(), Services::logger());
            $resp = $controller->create('temp_hooks_table');
            $body = json_decode($resp->getBody(), true);

            $this->assertSame('success', $body['status']);
            $this->assertSame('LOWERCASE TITLE', $body['data']['title']);
            $this->assertTrue($body['data']['after_save_flag']);

            $createdId = (string) ($body['data']['id'] ?? $this->db->insertID());

            // Show: afterQuery should set after_query_flag
            $showResp = $controller->show('temp_hooks_table', $createdId);
            $showBody = json_decode($showResp->getBody(), true);
            $this->assertSame('success', $showBody['status']);
            $this->assertTrue($showBody['data']['after_query_flag']);

            $forge->dropTable('temp_hooks_table', true);
        }

        public function testSetupCommandForceOption(): void
        {
            command('jengo:api setup');
            $publishedConfig = APPPATH . 'Config/JengoApi.php';
            $this->assertFileExists($publishedConfig);

            // Modify the file to see if --force overwrites it
            file_put_contents($publishedConfig, '<?php // Modified');
            command('jengo:api setup --force');

            $content = file_get_contents($publishedConfig);
            $this->assertStringContainsString('class JengoApi extends BaseJengoApi', $content);
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

    class MockFormHandler extends \Jengo\Base\Validation\FormHandler
    {
        protected array $rules = [];

        public function validate(?\CodeIgniter\HTTP\RequestInterface $request = null): bool
        {
            return true;
        }

        public function validated(): \Jengo\Base\Validation\ValidatedData
        {
            $raw = json_decode($this->request->getBody() ?: '{}', true) ?: $this->request->getPost();
            return new \Jengo\Base\Validation\ValidatedData([], [], $raw);
        }
    }

    class TempUsersResource extends \Jengo\Api\Support\ResourceConfig
    {
        protected $formClass = MockFormHandler::class;

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
        protected $formClass = MockFormHandler::class;

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
        protected $formClass = MockFormHandler::class;

        public function name(): string
        {
            return 'temp_bulk_table';
        }
    }

    class TempArrayFormResource extends \Jengo\Api\Support\ResourceConfig
    {
        public function __construct()
        {
            $this->formClass = [
                'post' => MockFormHandler::class,
                'put' => 'NonExistentClass'
            ];
        }

        public function name(): string
        {
            return 'temp_array_form_table';
        }
    }

    class TempLifecycleResource extends \Jengo\Api\Support\ResourceConfig
    {
        protected $formClass = MockFormHandler::class;

        public function name(): string
        {
            return 'temp_lifecycle_table';
        }
    }

    class FailingValidationFormHandler extends \Jengo\Base\Validation\FormHandler
    {
        public function validate(?\CodeIgniter\HTTP\RequestInterface $request = null): bool
        {
            $data = json_decode($this->request->getBody() ?: '{}', true);
            if (empty($data['title'])) {
                $this->errors = ['title' => 'The title field is required.'];
                return false;
            }
            return true;
        }

        public function validated(): \Jengo\Base\Validation\ValidatedData
        {
            $data = json_decode($this->request->getBody() ?: '{}', true);
            return new \Jengo\Base\Validation\ValidatedData([], [], $data);
        }
    }

    class TempFailingValResource extends \Jengo\Api\Support\ResourceConfig
    {
        protected $formClass = FailingValidationFormHandler::class;

        public function name(): string
        {
            return 'temp_failing_val_table';
        }
    }

    class TempSqidsCrudResource extends \Jengo\Api\Support\ResourceConfig
    {
        protected $formClass = MockFormHandler::class;
        protected array $obfuscatedFields = ['id'];

        public function name(): string
        {
            return 'temp_sqids_crud';
        }
    }

    class TempHooksResource extends \Jengo\Api\Support\ResourceConfig
    {
        protected $formClass = MockFormHandler::class;

        public function name(): string
        {
            return 'temp_hooks_table';
        }

        public function beforeSave(array $data, ?\Jengo\Api\Support\HookContext $context = null): array
        {
            if (isset($data['title'])) {
                $data['title'] = strtoupper($data['title']);
            }
            return $data;
        }

        public function afterSave(array $record, ?\Jengo\Api\Support\HookContext $context = null): array
        {
            $record['after_save_flag'] = true;
            return $record;
        }

        public function afterQuery(array $data, ?\Jengo\Api\Support\HookContext $context = null): array
        {
            foreach ($data as $row) {
                if (is_object($row)) {
                    $row->after_query_flag = true;
                }
            }
            return $data;
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
