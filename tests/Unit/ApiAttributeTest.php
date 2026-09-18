<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\HTTP\Response;
use Config\Services;
use Jengo\Api\Attributes\API;
use Tests\TestCase;

final class ApiAttributeTest extends TestCase
{
    public function testBeforeSetsAcceptHeader(): void
    {
        $attribute = new API();
        $request = Services::request();

        $attribute->before($request);

        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testAfterLeavesRedirectResponsesUntouched(): void
    {
        $attribute = new API();
        $request = Services::request();
        $response = new Response(config('App'));
        $response->setStatusCode(302);
        $response->setHeader('Location', '/dashboard');

        $result = $attribute->after($request, $response);

        $this->assertSame($response, $result);
        $this->assertSame(302, $result->getStatusCode());
    }

    public function testAfterLeavesPreformattedPayloadUntouched(): void
    {
        $attribute = new API();
        $request = Services::request();
        $response = new Response(config('App'));
        $response->setStatusCode(200);
        $preformatted = json_encode([
            'status' => 'success',
            'data' => ['id' => 1, 'name' => 'Alice'],
        ]);
        $response->setBody($preformatted);

        $result = $attribute->after($request, $response);

        $this->assertSame($response, $result);
        $this->assertSame($preformatted, $result->getBody());
    }

    public function testAfterWrapsSuccessResponseInStandardEnvelope(): void
    {
        $attribute = new API();
        $request = Services::request();
        $response = new Response(config('App'));
        $response->setStatusCode(200);
        $response->setBody(json_encode(['item' => 'widget', 'price' => 19.99]));

        $result = $attribute->after($request, $response);

        $this->assertNotNull($result);
        $decoded = json_decode($result->getBody(), true);
        $this->assertSame('success', $decoded['status']);
        $this->assertSame('Request processed successfully', $decoded['message']);
        $this->assertSame(['item' => 'widget', 'price' => 19.99], $decoded['data']);
    }

    public function testAfterWrapsErrorResponseInStandardEnvelope(): void
    {
        $attribute = new API();
        $request = Services::request();
        $response = new Response(config('App'));
        $response->setStatusCode(422);
        $response->setBody(json_encode([
            'message' => 'Invalid email address',
            'field' => 'email',
        ]));

        $result = $attribute->after($request, $response);

        $this->assertNotNull($result);
        $decoded = json_decode($result->getBody(), true);
        $this->assertSame('error', $decoded['status']);
        $this->assertSame('Invalid email address', $decoded['message']);
        $this->assertArrayHasKey('errors', $decoded);
    }

    public function testAfterHandlesRawStringBody(): void
    {
        $attribute = new API();
        $request = Services::request();
        $response = new Response(config('App'));
        $response->setStatusCode(200);
        $response->setBody('Plain text payload');

        $result = $attribute->after($request, $response);

        $this->assertNotNull($result);
        $decoded = json_decode($result->getBody(), true);
        $this->assertSame('success', $decoded['status']);
        $this->assertSame('Plain text payload', $decoded['data']);
    }
}
