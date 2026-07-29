<?php

declare(strict_types=1);

namespace Jengo\Api\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use Jengo\Api\Contracts\ResourceConfigInterface;
use Jengo\Api\Exceptions\ApiException;
use Jengo\Api\Services\RequestProcessor;
use Jengo\Api\Services\SwaggerGenerator;
use Jengo\Schema\Query\Enums\QueryMode;
use Jengo\Schema\Reflection\SchemaReflector;
use function Jengo\Schema\query;

class ApiController extends Controller
{
    use ResponseTrait;
    protected $format = 'json';

    public function docs()
    {
        try {
            $spec = SwaggerGenerator::generate();
            return $this->respond($spec);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function docsUi()
    {
        helper('url');

        $jsonUrl = null;

        try {
            $jsonUrl = url_to('api-docs');
        } catch (\Throwable $e) {
            throw new PageNotFoundException();
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="Swagger UI for Jengo API" />
    <title>Jengo API Documentation</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = () => {
            window.ui = SwaggerUIBundle({
                url: '{$jsonUrl}',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                layout: "StandaloneLayout"
            });
        };
    </script>
</body>
</html>
HTML;

        return $this->response->setBody($html);
    }

    public function index(string $resource)
    {
        try {
            RequestProcessor::process($resource, 'get', $this->request);

            $query = query($resource)->mode(QueryMode::OPEN);

            $instance = self::getResourceInstance($resource);
            if ($instance) {
                $instance->beforeQuery($query);
            }

            $result = $query->get();

            $data = $result->data;
            if ($instance) {
                $data = $instance->afterQuery($data);
            }

            return $this->respond([
                'status' => 'success',
                'data' => $data,
                'pagination' => $result->pagination
            ]);
        } catch (ApiException $e) {
            return $this->respond([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function show(string $resource, string $id)
    {
        try {
            RequestProcessor::process($resource, 'get', $this->request);

            $instance = self::getResourceInstance($resource);
            if ($instance && in_array('id', $instance->obfuscatedFields(), true)) {
                helper('jengo');
                $id = (string) sqids_unhash($id);
            }

            $query = query($resource)->mode(QueryMode::OPEN);
            if ($instance) {
                $instance->beforeQuery($query);
            }

            $result = $query->find($id);

            if ($result === null) {
                return $this->failNotFound("Resource {$resource} with ID {$id} not found.");
            }

            if ($instance) {
                $result = $instance->afterQuery([$result])[0] ?? null;
            }

            return $this->respond([
                'status' => 'success',
                'data' => $result
            ]);
        } catch (ApiException $e) {
            return $this->respond([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function create(string $resource)
    {
        try {
            $resourceConfig = RequestProcessor::process($resource, 'post', $this->request);
            $formClass = $resourceConfig['form'] ?? null;

            if ($formClass && class_exists($formClass)) {
                $form = new $formClass($this->request);
                if (!$form->validate()) {
                    return $this->respond([
                        'status' => 'error',
                        'message' => 'The given data was invalid.',
                        'errors' => $form->getErrors()
                    ], 422);
                }
                $payload = $form->validated()->toArray();
            } else {
                $payload = $this->request->getJSON(true) ?? $this->request->getPost();
            }

            $instance = self::getResourceInstance($resource);
            if ($instance) {
                $payload = $instance->beforeSave($payload);
            }

            $metadata = SchemaReflector::reflect($resource);
            $modelClass = $metadata->modelClass;

            if (!$modelClass) {
                return $this->fail("No model class associated with schema {$resource} to perform insert.");
            }

            $model = new $modelClass();
            $id = $model->insert($payload);

            if ($id === false) {
                return $this->failValidationErrors($model->errors());
            }

            $record = query($resource)->find($id);
            if ($instance && $record) {
                $record = $instance->afterSave(is_object($record) ? (array) $record : $record);
            }

            return $this->respondCreated([
                'status' => 'success',
                'data' => $record
            ]);
        } catch (ApiException $e) {
            return $this->respond([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function update(string $resource, string $id)
    {
        try {
            $resourceConfig = RequestProcessor::process($resource, 'put', $this->request);
            $formClass = $resourceConfig['form'] ?? null;

            $instance = self::getResourceInstance($resource);
            if ($instance && in_array('id', $instance->obfuscatedFields(), true)) {
                helper('jengo');
                $id = (string) sqids_unhash($id);
            }

            if ($formClass && class_exists($formClass)) {
                $form = new $formClass($this->request);
                if (!$form->validate()) {
                    return $this->respond([
                        'status' => 'error',
                        'message' => 'The given data was invalid.',
                        'errors' => $form->getErrors()
                    ], 422);
                }
                $payload = $form->validated()->toArray();
            } else {
                $payload = $this->request->getJSON(true) ?? $this->request->getRawInput();
            }

            if ($instance) {
                $payload = $instance->beforeSave($payload);
            }

            $metadata = SchemaReflector::reflect($resource);
            $modelClass = $metadata->modelClass;

            if (!$modelClass) {
                return $this->fail("No model class associated with schema {$resource} to perform update.");
            }

            $model = new $modelClass();
            $success = $model->update($id, $payload);

            if ($success === false) {
                return $this->failValidationErrors($model->errors());
            }

            $record = query($resource)->find($id);
            if ($instance && $record) {
                $record = $instance->afterSave(is_object($record) ? (array) $record : $record);
            }

            return $this->respond([
                'status' => 'success',
                'data' => $record
            ]);
        } catch (ApiException $e) {
            return $this->respond([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete(string $resource, string $id)
    {
        try {
            RequestProcessor::process($resource, 'delete', $this->request);

            $instance = self::getResourceInstance($resource);
            if ($instance && in_array('id', $instance->obfuscatedFields(), true)) {
                helper('jengo');
                $id = (string) sqids_unhash($id);
            }

            $metadata = SchemaReflector::reflect($resource);
            $modelClass = $metadata->modelClass;

            if (!$modelClass) {
                return $this->fail("No model class associated with schema {$resource} to perform delete.");
            }

            $model = new $modelClass();
            $success = $model->delete($id);

            if ($success === false) {
                return $this->fail("Failed to delete record.");
            }

            return $this->respondDeleted([
                'status' => 'success',
                'message' => "Resource {$resource} with ID {$id} successfully deleted."
            ]);
        } catch (ApiException $e) {
            return $this->respond([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $e->getCode());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Resolve resource configuration instance by name.
     */
    private static function getResourceInstance(string $resource): ?ResourceConfigInterface
    {
        $config = config('JengoApi');
        foreach ($config->resources as $resClass) {
            if (class_exists($resClass)) {
                $resObj = new $resClass();
                if ($resObj instanceof ResourceConfigInterface && $resObj->name() === $resource) {
                    return $resObj;
                }
            }
        }
        return null;
    }
}
