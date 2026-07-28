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

            $query = query($resource)->mode(QueryMode::OPEN);
            
            $instance = self::getResourceInstance($resource);
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

            $instance = self::getResourceInstance($resource);
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
