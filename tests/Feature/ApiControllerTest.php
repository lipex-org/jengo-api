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
        $this->assertStringContainsString('class JengoApi extends BaseConfig', $content);
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

        $forge->dropTable('temp_api_table', true);
        $schemaFile = APPPATH . 'Schemas/TempApiTableSchema.php';
        if (file_exists($schemaFile)) {
            unlink($schemaFile);
        }
    }

    public function testResourceDtoFluentApi(): void
    {
        $resource = \Jengo\Api\DTO\Resource::name('users')
            ->only(['get', 'post'])
            ->form('App\Validation\UserForm')
            ->capabilities(['pagination'])
            ->relations(['files'])
            ->maxLimit(50);

        $this->assertSame('users', $resource->getName());

        $config = $resource->toArray();
        $this->assertSame(['get', 'post'], $config['exposed_methods']);
        $this->assertSame('App\Validation\UserForm', $config['form']);
        $this->assertSame(['pagination'], $config['capabilities']);
        $this->assertSame(['files'], $config['allowed_relations']);
        $this->assertSame(50, $config['max_limit']);
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

    private function cleanFileSystem(): void
    {
        $publishedConfig = APPPATH . 'Config/JengoApi.php';
        if (file_exists($publishedConfig)) {
            unlink($publishedConfig);
        }
    }
}

class TempApiTableResource extends \Jengo\Api\Support\ResourceConfig
{
    public function name(): string
    {
        return 'temp_api_table';
    }
}
