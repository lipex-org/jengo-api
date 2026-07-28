<?php

declare(strict_types=1);

namespace Jengo\Api\DTO;

class Resource
{
    private string $name;
    private array $exposedMethods = ['get', 'post', 'put', 'patch', 'delete'];
    private ?string $formClass = null;
    private array $capabilities = ['pagination', 'search', 'sort'];
    private array $allowedRelations = [];
    private int $maxLimit = 100;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Static entrypoint to initiate a fluent resource configuration.
     */
    public static function name(string $name): self
    {
        return new self($name);
    }

    /**
     * Restrict routes to only these HTTP methods.
     */
    public function only(array $methods): self
    {
        $this->exposedMethods = array_map('strtolower', $methods);
        return $this;
    }

    /**
     * Restrict routes to all HTTP methods except these.
     */
    public function except(array $methods): self
    {
        $methods = array_map('strtolower', $methods);
        $this->exposedMethods = array_values(array_filter(
            ['get', 'post', 'put', 'patch', 'delete'],
            fn($m) => !in_array($m, $methods, true)
        ));
        return $this;
    }

    /**
     * Map a FormHandler class for request data validation.
     */
    public function form(string $formClass): self
    {
        $this->formClass = $formClass;
        return $this;
    }

    /**
     * Configure allowed Jengo query string capabilities.
     */
    public function capabilities(array $capabilities): self
    {
        $this->capabilities = $capabilities;
        return $this;
    }

    /**
     * Configure allowed relation derivations.
     */
    public function relations(array $relations): self
    {
        $this->allowedRelations = $relations;
        return $this;
    }

    /**
     * Set the maximum allowed limit for pagination.
     */
    public function maxLimit(int $limit): self
    {
        $this->maxLimit = $limit;
        return $this;
    }

    /**
     * Get the resource name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Export the fluent configuration as a standardized array.
     */
    public function toArray(): array
    {
        return [
            'exposed_methods' => $this->exposedMethods,
            'form' => $this->formClass,
            'capabilities' => $this->capabilities,
            'allowed_relations' => $this->allowedRelations,
            'max_limit' => $this->maxLimit,
        ];
    }
}
