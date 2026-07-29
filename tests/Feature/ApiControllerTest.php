<?php

declare(strict_types=1);

namespace Tests\Feature;

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

        // Dynamically register the resource configuration class for testing
        $config = config('JengoApi');
        $config->resources = [
            TempApiTableResource::class
        ];

        $routes = Services::routes();
        \Jengo\Api\Router::publish($routes, ['only' => ['get', 'post']]);

        $this->db->table('temp_api_table')->insert(['title' => 'Initial Title']);

        command('jengo:schema generate --table temp_api_table');
        $this->assertFileExists(APPPATH . 'Schemas/TempApiTableSchema.php');

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

        $forge->dropTable('temp_api_table', true);
        $schemaFile = APPPATH . 'Schemas/TempApiTableSchema.php';
        if (file_exists($schemaFile)) {
            unlink($schemaFile);
        }
    }

    public function testMakeApiResourceCommand(): void
    {
        command('jengo:make api_resource UserConfigResource');

        $expectedFile = APPPATH . 'Api/UserConfigResource.php';
        $this->assertFileExists($expectedFile);

        $content = file_get_contents($expectedFile);
        $this->assertStringContainsString('class UserConfigResource extends ResourceConfig', $content);
        $this->assertStringContainsString("return 'userconfig';", $content);

        // Cleanup
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

        \Jengo\Api\Router::publish($routes, [
            'docs' => 'my-swagger-custom',
            'docs_ui' => 'my-swagger-ui'
        ]);
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

        // Check foreign key reference
        $posts = $db->table('temp_posts')->get()->getResultArray();
        $this->assertEquals(1, $posts[0]['temp_user_id']);
        $this->assertEquals(1, $posts[1]['temp_user_id']);

        // Clean up
        $forge->dropTable('temp_users', true);
        $forge->dropTable('temp_posts', true);
    }

    private function cleanFileSystem(): void
    {
        $publishedConfig = APPPATH . 'Config/JengoApi.php';
        if (file_exists($publishedConfig)) {
            unlink($publishedConfig);
        }
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

    public function afterQuery(array $data): array
    {
        foreach ($data as $row) {
            if (is_object($row)) {
                $row->hook_executed = true;
            }
        }
        return $data;
    }
}
