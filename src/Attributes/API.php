<?php

declare(strict_types=1);

namespace Jengo\Api\Attributes;

use Attribute;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\Attributes\RouteAttributeInterface;

/**
 * Jengo API Attribute
 * 
 * Apply this to a controller class or method to ensure a consistent JSON 
 * response structure across the entire application.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class API implements RouteAttributeInterface
{
    /**
     * Called before the controller method executes.
     */
    public function before(RequestInterface $request): RequestInterface|ResponseInterface|null
    {
        // Force the request to be handled as JSON
        $request->setHeader('Accept', 'application/json');
        
        return null;
    }

    /**
     * Called after the controller method executes.
     * Intercepts the response and wraps it in the standard Jengo JSON structure.
     */
    public function after(RequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        // Don't process redirects
        $code = $response->getStatusCode();
        if ($code >= 300 && $code < 400) {
            return $response;
        }

        $body = $response->getBody();
        $data = json_decode((string) $body, true);

        // Fallback for non-json bodies (or raw strings)
        if ($data === null && !empty($body)) {
            $data = $body;
        }

        // If it's already a valid Jengo structure, leave it
        if (is_array($data) && isset($data['status']) && (isset($data['data']) || isset($data['errors']))) {
            return $response;
        }

        $isSuccess = $code >= 200 && $code < 300;

        $payload = [
            'status'  => $isSuccess ? 'success' : 'error',
            'message' => $isSuccess ? 'Request processed successfully' : 'An error occurred',
        ];

        if ($isSuccess) {
            $payload['data'] = $data;
        } else {
            $payload['errors'] = $data;
            // Try to find a specific message in the data (common for exceptions/validation)
            if (is_array($data) && isset($data['message'])) {
                $payload['message'] = $data['message'];
            }
        }

        return $response->setJSON($payload);
    }
}
