<?php

declare(strict_types=1);

namespace Jengo\Api\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use Jengo\Api\Contracts\ResourceConfigInterface;
use Jengo\Api\Exceptions\ApiException;
use Jengo\Api\Services\RequestProcessor;
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
            $spec = \Jengo\Api\Services\SwaggerGenerator::generate();
            return $this->respond($spec);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function docsUi()
    {
        helper('url');
        
        $jsonUrl = site_url(str_replace('docs/ui', 'docs', $this->request->getPath()));

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

            $id = $this->saveResource($resource, $payload);

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
            $errors = json_decode($e->getMessage(), true);
            if (is_array($errors)) {
                return $this->failValidationErrors($errors);
            }
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

            $this->saveResource($resource, $payload, $id);

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
            $errors = json_decode($e->getMessage(), true);
            if (is_array($errors)) {
                return $this->failValidationErrors($errors);
            }
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

    /**
     * Save resource and mutate relations recursively.
     */
    private function saveResource(string $resource, array $payload, $id = null)
    {
        $metadata = SchemaReflector::reflect($resource);
        $modelClass = $metadata->modelClass;

        if (!$modelClass) {
            throw new \RuntimeException("No model class associated with schema {$resource}");
        }

        $model = new $modelClass();
        $relations = $metadata->relations;

        // Separate relation payloads from main attributes
        $relationPayloads = [];
        foreach ($relations as $relation) {
            if (array_key_exists($relation->name, $payload)) {
                $relationPayloads[$relation->name] = $payload[$relation->name];
                unset($payload[$relation->name]);
            }
        }

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            // 1. Process belongsTo relations FIRST
            foreach ($relations as $relation) {
                if ($relation->type === \Jengo\Schema\Metadata\RelationMetadata::BELONGS_TO && isset($relationPayloads[$relation->name])) {
                    $childPayload = $relationPayloads[$relation->name];
                    if (is_array($childPayload)) {
                        $childId = $childPayload['id'] ?? null;
                        $childResource = $relation->name;
                        
                        $childId = $this->saveResource($childResource, $childPayload, $childId);
                        $payload[$relation->fromField] = $childId;
                    }
                }
            }

            // 2. Save the parent record
            if ($id !== null) {
                $success = $model->update($id, $payload);
                if ($success === false) {
                    throw new \RuntimeException(json_encode($model->errors()));
                }
            } else {
                $id = $model->insert($payload);
                if ($id === false) {
                    throw new \RuntimeException(json_encode($model->errors()));
                }
            }

            // 3. Process hasMany relations AFTER parent is saved
            foreach ($relations as $relation) {
                if ($relation->type === \Jengo\Schema\Metadata\RelationMetadata::HAS_MANY && isset($relationPayloads[$relation->name])) {
                    $childPayloads = $relationPayloads[$relation->name];
                    $childResource = $relation->name;

                    if (is_array($childPayloads)) {
                        $items = isset($childPayloads[0]) && is_array($childPayloads[0]) ? $childPayloads : [$childPayloads];
                        foreach ($items as $item) {
                            $item[$relation->toField] = $id;
                            $childId = $item['id'] ?? null;
                            $this->saveResource($childResource, $item, $childId);
                        }
                    }
                }
            }

            $db->transCommit();
            return $id;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }
}
