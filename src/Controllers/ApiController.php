<?php

declare(strict_types=1);

namespace Jengo\Api\Controllers;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use Jengo\Api\Contracts\ResourceConfigInterface;
use Jengo\Api\Exceptions\ApiException;
use Jengo\Api\Services\RequestProcessor;
use Jengo\Api\Services\SwaggerGenerator;
use Jengo\Api\Support\HookContext;
use Jengo\Schema\Reflection\SchemaReflector;
use CodeIgniter\Exceptions\PageNotFoundException;
use Throwable;
use function Jengo\Schema\query;

class ApiController extends Controller
{
    use ResponseTrait;
    protected $format = 'json';

    public function index(string $resource)
    {
        try {
            $resourceConfig = RequestProcessor::process($resource, 'get', $this->request);

            $currentPath = trim($this->request->getPath(), '/');
            $version = RequestProcessor::extractVersion($currentPath);
            $context = new HookContext($version, $resource, 'get');

            $query = query($resource)->open($resourceConfig['capabilities'] ?? []);

            $instance = $this->getResourceInstance($resource);
            if ($instance) {
                $instance->beforeQuery($query, $context);
            }

            $result = $query->get();

            $data = $result->data;
            if ($instance) {
                $data = $instance->afterQuery($data, $context);
            }

            return $this->respond([
                'status' => 'success',
                'data' => $data,
                'pagination' => $result->pagination
            ]);
        } catch (ApiException $e) {
            $data = json_decode($e->getMessage(), true);
            if (is_array($data)) {
                return $this->respondProblem($data['title'] ?? 'API Error', $e->getCode(), $data['detail'] ?? '', $data['invalid_params'] ?? []);
            }
            return $this->respondProblem('API Error', $e->getCode(), $e->getMessage());
        } catch (PageNotFoundException $e) {
            return $this->respondProblem('Not Found', 404, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->respondProblem('Internal Server Error', 500, $e->getMessage());
        }
    }

    public function show(string $resource, string $id)
    {
        try {
            $resourceConfig = RequestProcessor::process($resource, 'get', $this->request);

            $currentPath = trim($this->request->getPath(), '/');
            $version = RequestProcessor::extractVersion($currentPath);
            $context = new HookContext($version, $resource, 'get');

            $instance = $this->getResourceInstance($resource);
            if ($instance && in_array('id', $instance->obfuscatedFields(), true)) {
                helper('jengo');
                $id = (string) sqids_unhash($id);
            }

            $query = query($resource)->open($resourceConfig['capabilities'] ?? []);
            if ($instance) {
                $instance->beforeQuery($query, $context);
            }

            $result = $query->find($id);

            if ($result === null) {
                return $this->respondProblem('Resource Not Found', 404, "Resource {$resource} with ID {$id} not found.");
            }

            if ($instance) {
                $result = $instance->afterQuery([$result], $context)[0] ?? null;
            }

            return $this->respond([
                'status' => 'success',
                'data' => $result
            ]);
        } catch (ApiException $e) {
            $data = json_decode($e->getMessage(), true);
            if (is_array($data)) {
                return $this->respondProblem($data['title'] ?? 'API Error', $e->getCode(), $data['detail'] ?? '', $data['invalid_params'] ?? []);
            }
            return $this->respondProblem('API Error', $e->getCode(), $e->getMessage());
        } catch (PageNotFoundException $e) {
            return $this->respondProblem('Not Found', 404, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->respondProblem('Internal Server Error', 500, $e->getMessage());
        }
    }

    public function create(string $resource)
    {
        try {
            $resourceConfig = RequestProcessor::process($resource, 'post', $this->request);
            $formClass = $resourceConfig['resolved_form'] ?? null;

            $currentPath = trim($this->request->getPath(), '/');
            $version = RequestProcessor::extractVersion($currentPath);
            $context = new HookContext($version, $resource, 'post');

            $rawPayload = $this->request->getJSON(true) ?? $this->request->getPost() ?? [];
            $isBulk = is_array($rawPayload) && isset($rawPayload[0]) && is_array($rawPayload[0]);

            $items = $isBulk ? $rawPayload : [$rawPayload];
            $processedItems = [];

            $db = db_connect();
            $db->transBegin();

            try {
                foreach ($items as $item) {
                    if ($formClass && class_exists($formClass)) {
                        $originalBody = $this->request->getBody();
                        $this->request->setBody(json_encode($item));

                        $form = new $formClass($this->request);
                        if (!$form->validate()) {
                            $invalidParams = [];
                            foreach ($form->getErrors() as $field => $error) {
                                $invalidParams[] = [
                                    'name' => $field,
                                    'reason' => $error
                                ];
                            }
                            throw new ApiException(json_encode([
                                'title' => 'Validation Failed',
                                'detail' => 'One or more items in the batch failed validation.',
                                'invalid_params' => $invalidParams
                            ]), 422);
                        }
                        $itemPayload = $form->validated()->toArray();
                        $this->request->setBody($originalBody);
                    } else {
                        $itemPayload = $item;
                    }

                    $instance = $this->getResourceInstance($resource);
                    if ($instance) {
                        $itemPayload = $instance->beforeSave($itemPayload, $context);
                    }

                    $id = $this->saveResource($resource, $itemPayload);

                    $record = query($resource)->find($id);
                    if ($instance && $record) {
                        $record = $instance->afterSave(is_object($record) ? (array) $record : $record, $context);
                    }
                    $processedItems[] = $record;
                }

                $db->transCommit();
            } catch (\Throwable $e) {
                $db->transRollback();
                throw $e;
            }

            return $this->respondCreated([
                'status' => 'success',
                'data' => $isBulk ? $processedItems : ($processedItems[0] ?? null)
            ]);
        } catch (ApiException $e) {
            $data = json_decode($e->getMessage(), true);
            if (is_array($data)) {
                return $this->respondProblem($data['title'] ?? 'API Error', $e->getCode(), $data['detail'] ?? '', $data['invalid_params'] ?? []);
            }
            return $this->respondProblem('API Error', $e->getCode(), $e->getMessage());
        } catch (PageNotFoundException $e) {
            return $this->respondProblem('Not Found', 404, $e->getMessage());
        } catch (\Throwable $e) {
            $errors = json_decode($e->getMessage(), true);
            if (is_array($errors)) {
                $invalidParams = [];
                foreach ($errors as $field => $error) {
                    $invalidParams[] = [
                        'name' => $field,
                        'reason' => $error
                    ];
                }
                return $this->respondProblem('Validation Failed', 422, 'Model transaction failed validation checks.', $invalidParams);
            }
            return $this->respondProblem('Internal Server Error', 500, $e->getMessage());
        }
    }

    public function update(string $resource, ?string $id = null)
    {
        try {
            $resourceConfig = RequestProcessor::process($resource, 'put', $this->request);
            $formClass = $resourceConfig['resolved_form'] ?? null;

            $currentPath = trim($this->request->getPath(), '/');
            $version = RequestProcessor::extractVersion($currentPath);
            $method = $this->request->getMethod(true);
            $context = new HookContext($version, $resource, $method);

            $rawPayload = $this->request->getJSON(true) ?? $this->request->getRawInput() ?? [];
            $isBulk = is_array($rawPayload) && isset($rawPayload[0]) && is_array($rawPayload[0]);

            $items = $isBulk ? $rawPayload : [$rawPayload];
            $processedItems = [];

            $db = db_connect();
            $db->transBegin();

            try {
                foreach ($items as $item) {
                    $itemId = $id;
                    if ($isBulk) {
                        $itemId = $item['id'] ?? null;
                        if ($itemId === null) {
                            throw new ApiException(json_encode([
                                'title' => 'Missing ID',
                                'detail' => 'Each item in a bulk update batch must contain an ID.'
                            ]), 400);
                        }
                    }

                    $instance = $this->getResourceInstance($resource);
                    if ($instance && in_array('id', $instance->obfuscatedFields(), true)) {
                        helper('jengo');
                        $itemId = (string) sqids_unhash((string) $itemId);
                    }

                    if ($formClass && class_exists($formClass)) {
                        $originalBody = $this->request->getBody();
                        $this->request->setBody(json_encode($item));

                        $form = new $formClass($this->request);
                        if (!$form->validate()) {
                            $invalidParams = [];
                            foreach ($form->getErrors() as $field => $error) {
                                $invalidParams[] = [
                                    'name' => $field,
                                    'reason' => $error
                                ];
                            }
                            throw new ApiException(json_encode([
                                'title' => 'Validation Failed',
                                'detail' => 'One or more items in the batch failed validation.',
                                'invalid_params' => $invalidParams
                            ]), 422);
                        }
                        $itemPayload = $form->validated()->toArray();
                        $this->request->setBody($originalBody);
                    } else {
                        $itemPayload = $item;
                    }

                    if ($instance) {
                        $itemPayload = $instance->beforeSave($itemPayload, $context);
                    }

                    unset($itemPayload['id']);

                    $this->saveResource($resource, $itemPayload, $itemId);

                    $record = query($resource)->find($itemId);
                    if ($instance && $record) {
                        $record = $instance->afterSave(is_object($record) ? (array) $record : $record, $context);
                    }
                    $processedItems[] = $record;
                }

                $db->transCommit();
            } catch (\Throwable $e) {
                $db->transRollback();
                throw $e;
            }

            return $this->respond([
                'status' => 'success',
                'data' => $isBulk ? $processedItems : ($processedItems[0] ?? null)
            ]);
        } catch (ApiException $e) {
            $data = json_decode($e->getMessage(), true);
            if (is_array($data)) {
                return $this->respondProblem($data['title'] ?? 'API Error', $e->getCode(), $data['detail'] ?? '', $data['invalid_params'] ?? []);
            }
            return $this->respondProblem('API Error', $e->getCode(), $e->getMessage());
        } catch (PageNotFoundException $e) {
            return $this->respondProblem('Not Found', 404, $e->getMessage());
        } catch (\Throwable $e) {
            $errors = json_decode($e->getMessage(), true);
            if (is_array($errors)) {
                $invalidParams = [];
                foreach ($errors as $field => $error) {
                    $invalidParams[] = [
                        'name' => $field,
                        'reason' => $error
                    ];
                }
                return $this->respondProblem('Validation Failed', 422, 'Model transaction failed validation checks.', $invalidParams);
            }
            return $this->respondProblem('Internal Server Error', 500, $e->getMessage());
        }
    }

    public function delete(string $resource, string $id)
    {
        try {
            RequestProcessor::process($resource, 'delete', $this->request);

            $instance = $this->getResourceInstance($resource);
            if ($instance && in_array('id', $instance->obfuscatedFields(), true)) {
                helper('jengo');
                $id = (string) sqids_unhash($id);
            }

            $metadata = SchemaReflector::reflect($resource);
            $modelClass = $metadata->modelClass;

            if (!$modelClass) {
                return $this->respondProblem('Internal Server Error', 500, "No model class associated with schema {$resource} to perform delete.");
            }

            $model = new $modelClass();
            $success = $model->delete($id);

            if ($success === false) {
                return $this->respondProblem('Internal Server Error', 500, "Failed to delete record.");
            }

            return $this->respondDeleted([
                'status' => 'success',
                'message' => "Resource {$resource} with ID {$id} successfully deleted."
            ]);
        } catch (ApiException $e) {
            $data = json_decode($e->getMessage(), true);
            if (is_array($data)) {
                return $this->respondProblem($data['title'] ?? 'API Error', $e->getCode(), $data['detail'] ?? '', $data['invalid_params'] ?? []);
            }
            return $this->respondProblem('API Error', $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $this->respondProblem('Internal Server Error', 500, $e->getMessage());
        }
    }


    public function docs()
    {
        try {
            $currentPath = trim($this->request->getPath(), '/');
            $version = RequestProcessor::extractVersion($currentPath);
            $spec = SwaggerGenerator::generate($version);
            return $this->respond($spec);
        } catch (PageNotFoundException $e) {
            return $this->respondProblem('Not Found', 404, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->respondProblem('Internal Server Error', 500, $e->getMessage());
        }
    }

    public function docsUi()
    {
        helper('url');

        $currentPath = trim($this->request->getPath(), '/');
        $version = RequestProcessor::extractVersion($currentPath);
        $routeName = ($version ? $version . '-' : '') . 'api-docs';

        try {
            $jsonUrl = url_to($routeName);
        } catch (Throwable $e) {
            $jsonUrl = site_url($version ? $version . '/docs' : 'docs');
        }

        $config = config('JengoApi');
        $apiName = $config->apiName ?? 'Jengo Auto-Generated API';
        $viewName = $config->swaggerView ?? 'Jengo\Api\Views\swagger_ui';

        return $this->response->setBody(view($viewName, [
            'jsonUrl' => $jsonUrl,
            'apiName' => $apiName
        ]));
    }

    /**
     * Resolve resource configuration instance by name and request version.
     */
    private function getResourceInstance(string $resource): ?ResourceConfigInterface
    {
        $version = RequestProcessor::extractVersion($this->request->getPath());
        $config = config('JengoApi');
        foreach ($config->resources as $resClass) {
            if (class_exists($resClass)) {
                $resObj = new $resClass();
                if ($resObj instanceof ResourceConfigInterface && $resObj->name() === $resource) {
                    if (RequestProcessor::matchVersion($resObj->version(), $version)) {
                        return $resObj;
                    }
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

        $db = db_connect();
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

    /**
     * Respond with an RFC 7807 compliant Problem Details JSON payload.
     */
    private function respondProblem(string $title, int $status, string $detail, array $invalidParams = [])
    {
        $response = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => '/' . ltrim($this->request->getPath(), '/'),
        ];

        if (!empty($invalidParams)) {
            $response['invalid_params'] = $invalidParams;
        }

        return $this->respond($response, $status, 'application/problem+json');
    }
}
