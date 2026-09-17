<?php

declare(strict_types=1);

namespace Tests\Unit;

use Jengo\Api\Support\DocsOptions;
use Jengo\Api\Support\RouterOptions;
use Tests\TestCase;

final class RouterOptionsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $options = new RouterOptions();

        $this->assertSame([], $options->except);
        $this->assertSame([], $options->only);
        $this->assertNull($options->version);
        $this->assertSame('docs', $options->docs->route);
        $this->assertSame('docs/ui', $options->docs->uiRoute);
    }

    public function testCustomValues(): void
    {
        $docs = new DocsOptions('swagger.json', 'swagger-ui');
        $options = new RouterOptions(
            except: ['delete'],
            only: ['get', 'post'],
            version: 'v1',
            docs: $docs
        );

        $this->assertSame(['delete'], $options->except);
        $this->assertSame(['get', 'post'], $options->only);
        $this->assertSame('v1', $options->version);
        $this->assertSame('swagger.json', $options->docs->route);
        $this->assertSame('swagger-ui', $options->docs->uiRoute);
    }

    public function testDocsCanBeDisabled(): void
    {
        $docs = new DocsOptions(false, false);
        $options = new RouterOptions(docs: $docs);

        $this->assertFalse($options->docs->route);
        $this->assertFalse($options->docs->uiRoute);
    }

    public function testMutateOverridesSpecifiedProperties(): void
    {
        $originalDocs = new DocsOptions('api-docs', 'api-docs-ui');
        $original = new RouterOptions(
            except: ['delete'],
            only: ['get', 'post'],
            version: 'v1',
            docs: $originalDocs
        );

        // Mutate only version
        $mutatedVersion = $original->mutate(new RouterOptions(version: 'v2'));
        $this->assertSame('v2', $mutatedVersion->version);
        $this->assertSame(['delete'], $mutatedVersion->except);
        $this->assertSame(['get', 'post'], $mutatedVersion->only);
        $this->assertSame('api-docs', $mutatedVersion->docs->route);
        $this->assertSame('api-docs-ui', $mutatedVersion->docs->uiRoute);

        // Mutate only only/except
        $mutatedActions = $original->mutate(new RouterOptions(only: ['get']));
        $this->assertSame(['get'], $mutatedActions->only);
        $this->assertSame(['delete'], $mutatedActions->except);
        $this->assertSame('v1', $mutatedActions->version);
    }
}
