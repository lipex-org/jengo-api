<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Api\Services\SwaggerGenerator;
use Jengo\Api\Support\ResourceConfig;
use Tests\TestCase;

final class SwaggerGeneratorTest extends TestCase
{
    public function testVersionFiltering(): void
    {
        $config = config('JengoApi');
        $config->resources = [
            V1OnlyResource::class,
            V2OnlyResource::class,
            MultiVersionResource::class,
        ];

        $v1Spec = SwaggerGenerator::generate('v1');
        $this->assertArrayHasKey('/v1/v1_resource', $v1Spec['paths']);
        $this->assertArrayHasKey('/v1/multi_resource', $v1Spec['paths']);
        $this->assertArrayNotHasKey('/v1/v2_resource', $v1Spec['paths']);

        $v2Spec = SwaggerGenerator::generate('v2');
        $this->assertArrayHasKey('/v2/v2_resource', $v2Spec['paths']);
        $this->assertArrayHasKey('/v2/multi_resource', $v2Spec['paths']);
        $this->assertArrayNotHasKey('/v2/v1_resource', $v2Spec['paths']);
    }

    public function testBothPutAndPatchAreExposed(): void
    {
        $config = config('JengoApi');
        $config->resources = [
            FullCrudMockResource::class
        ];

        $spec = SwaggerGenerator::generate();
        $this->assertArrayHasKey('/crud_mock/{id}', $spec['paths']);

        $itemPaths = $spec['paths']['/crud_mock/{id}'];
        $this->assertArrayHasKey('get', $itemPaths);
        $this->assertArrayHasKey('put', $itemPaths);
        $this->assertArrayHasKey('patch', $itemPaths);
        $this->assertArrayHasKey('delete', $itemPaths);
    }

    public function testTagsAreDeduplicated(): void
    {
        $config = config('JengoApi');
        $config->resources = [
            FullCrudMockResource::class,
        ];

        $spec = SwaggerGenerator::generate();
        $tags = $spec['tags'];

        $tagNames = array_column($tags, 'name');
        $this->assertSame(array_unique($tagNames), $tagNames);
    }

    public function testQueryParametersMatchCapabilitiesAndRelations(): void
    {
        $config = config('JengoApi');
        $config->resources = [
            CustomParamsResource::class
        ];

        $spec = SwaggerGenerator::generate();
        $getOp = $spec['paths']['/custom_params']['get'];
        $paramNames = array_column($getOp['parameters'], 'name');

        $this->assertContains('page', $paramNames);
        $this->assertContains('limit', $paramNames);
        $this->assertContains('search', $paramNames);
        $this->assertContains('sort', $paramNames);
        $this->assertContains('derive', $paramNames);
    }

    public function testFallbackBaseUrlWhenEmpty(): void
    {
        $config = config('JengoApi');
        $config->apiBaseUrl = '';
        $config->resources = [];

        $spec = SwaggerGenerator::generate();
        $this->assertNotEmpty($spec['servers'][0]['url']);
    }
}

class V1OnlyResource extends ResourceConfig
{
    protected $version = 'v1';

    public function name(): string
    {
        return 'v1_resource';
    }
}

class V2OnlyResource extends ResourceConfig
{
    protected $version = 'v2';

    public function name(): string
    {
        return 'v2_resource';
    }
}

class MultiVersionResource extends ResourceConfig
{
    protected $version = ['v1', 'v2'];

    public function name(): string
    {
        return 'multi_resource';
    }
}

class FullCrudMockForm extends \Jengo\Base\Validation\FormHandler
{
    protected array $rules = [];

    public function validate(?\CodeIgniter\HTTP\RequestInterface $request = null): bool
    {
        return true;
    }

    public function validated(): \Jengo\Base\Validation\ValidatedData
    {
        return new \Jengo\Base\Validation\ValidatedData([], [], []);
    }
}

class FullCrudMockResource extends ResourceConfig
{
    protected $formClass = FullCrudMockForm::class;
    protected array $exposedMethods = ['get', 'post', 'put', 'patch', 'delete'];

    public function name(): string
    {
        return 'crud_mock';
    }
}

class CustomParamsResource extends ResourceConfig
{
    protected array $capabilities = ['pagination', 'search', 'sort'];
    protected array $allowedRelations = ['items', 'tags'];

    public function name(): string
    {
        return 'custom_params';
    }
}
