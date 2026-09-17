<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use Jengo\Api\Exceptions\ApiException;
use Jengo\Api\Services\RequestProcessor;
use Jengo\Api\Support\ResourceConfig;
use Tests\TestCase;

final class RequestProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestProcessor::clearCache();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        RequestProcessor::clearCache();
        $_GET = [];
        parent::tearDown();
    }

    public function testExtractVersion(): void
    {
        $this->assertSame('v1', RequestProcessor::extractVersion('v1/users'));
        $this->assertSame('v1', RequestProcessor::extractVersion('/v1/users/123'));
        $this->assertSame('v2', RequestProcessor::extractVersion('api/V2/posts'));
        $this->assertSame('v10', RequestProcessor::extractVersion('/v10/comments'));
        $this->assertNull(RequestProcessor::extractVersion('users'));
        $this->assertNull(RequestProcessor::extractVersion('/api/users/profile'));
        $this->assertNull(RequestProcessor::extractVersion('venues/v/items'));
    }

    public function testMatchVersion(): void
    {
        // Unversioned resource matches anything
        $this->assertTrue(RequestProcessor::matchVersion(null, 'v1'));
        $this->assertTrue(RequestProcessor::matchVersion([], 'v1'));
        $this->assertTrue(RequestProcessor::matchVersion('', 'v1'));
        $this->assertTrue(RequestProcessor::matchVersion(null, null));

        // Version-restricted resource rejected when request is unversioned
        $this->assertFalse(RequestProcessor::matchVersion('v1', null));
        $this->assertFalse(RequestProcessor::matchVersion(['v1', 'v2'], null));

        // Exact match (case-insensitive)
        $this->assertTrue(RequestProcessor::matchVersion('v1', 'v1'));
        $this->assertTrue(RequestProcessor::matchVersion('V1', 'v1'));
        $this->assertTrue(RequestProcessor::matchVersion('v1', 'V1'));
        $this->assertFalse(RequestProcessor::matchVersion('v1', 'v2'));

        // Array match
        $this->assertTrue(RequestProcessor::matchVersion(['v1', 'v2'], 'v1'));
        $this->assertTrue(RequestProcessor::matchVersion(['v1', 'v2'], 'v2'));
        $this->assertFalse(RequestProcessor::matchVersion(['v1', 'v2'], 'v3'));
    }

    public function testProcessThrowsPageNotFoundForUnexposedResource(): void
    {
        $config = config('JengoApi');
        $config->resources = [];

        $request = Services::request();

        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage("Resource 'unknown' is not exposed.");

        RequestProcessor::process('unknown', 'get', $request);
    }

    public function testProcessThrowsApiExceptionForDisallowedMethod(): void
    {
        $config = config('JengoApi');
        $config->resources = [TestReadOnlyResource::class];

        $request = Services::request();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage("Method 'post' is not allowed for resource 'test_readonly'.");

        RequestProcessor::process('test_readonly', 'post', $request);
    }

    public function testProcessThrowsPageNotFoundWhenWriteMethodHasNoFormHandler(): void
    {
        $config = config('JengoApi');
        $config->resources = [TestNoFormResource::class];

        $request = Services::request();

        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage("Write method 'post' is not allowed for resource 'test_no_form' as no validation FormHandler class is configured.");

        RequestProcessor::process('test_no_form', 'post', $request);
    }

    public function testCapabilityFilteringRemovesDisallowedParams(): void
    {
        $config = config('JengoApi');
        $config->resources = [TestRestrictedCapsResource::class];

        $_GET['search'] = 'test query';
        $_GET['sort'] = 'name';
        $_GET['page'] = '2';
        $_GET['limit'] = '200';

        $request = Services::request();

        $processed = RequestProcessor::process('test_restricted_caps', 'get', $request);

        // 'search' and 'sort' are not in capabilities, so $_GET should have them removed
        $this->assertArrayNotHasKey('search', $_GET);
        $this->assertArrayNotHasKey('sort', $_GET);

        // 'pagination' IS allowed, but maxLimit is 25, so limit should be clamped
        $this->assertSame(25, $_GET['limit']);
        $this->assertSame('2', $_GET['page']);
    }

    public function testDeriveFilteringRemovesUnallowedRelations(): void
    {
        $config = config('JengoApi');
        $config->resources = [TestRelationsResource::class];

        $_GET['derive'] = 'comments, secret_logs, author.profile, hacked_rel';

        $request = Services::request();

        RequestProcessor::process('test_relations', 'get', $request);

        $this->assertArrayHasKey('derive', $_GET);
        $derivations = explode(',', (string) $_GET['derive']);
        $derivations = array_map('trim', $derivations);
        $this->assertContains('comments', $derivations);
        $this->assertContains('author.profile', $derivations);
        $this->assertNotContains('secret_logs', $derivations);
        $this->assertNotContains('hacked_rel', $derivations);
    }

    public function testDeobfuscatesQueryParameters(): void
    {
        helper('jengo');
        $config = config('JengoApi');
        $config->resources = [TestObfuscatedQueryResource::class];

        $hashedUserId = sqids_hash(42);
        $_GET['user_id'] = $hashedUserId;

        $request = Services::request();

        RequestProcessor::process('test_obfuscated_query', 'get', $request);

        $this->assertSame(42, $_GET['user_id']);
    }
}

class TestReadOnlyResource extends ResourceConfig
{
    protected array $exposedMethods = ['get'];

    public function name(): string
    {
        return 'test_readonly';
    }
}

class TestNoFormResource extends ResourceConfig
{
    protected array $exposedMethods = ['get', 'post'];
    protected $formClass = null;

    public function name(): string
    {
        return 'test_no_form';
    }
}

class TestRestrictedCapsResource extends ResourceConfig
{
    protected array $capabilities = ['pagination'];
    protected int $maxLimit = 25;

    public function name(): string
    {
        return 'test_restricted_caps';
    }
}

class TestRelationsResource extends ResourceConfig
{
    protected array $allowedRelations = ['comments', 'author'];

    public function name(): string
    {
        return 'test_relations';
    }
}

class TestObfuscatedQueryResource extends ResourceConfig
{
    protected array $obfuscatedFields = ['user_id'];

    public function name(): string
    {
        return 'test_obfuscated_query';
    }
}
