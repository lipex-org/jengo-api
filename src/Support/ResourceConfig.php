<?php

declare(strict_types=1);

namespace Jengo\Api\Support;

use Jengo\Api\Contracts\ResourceConfigInterface;

abstract class ResourceConfig implements ResourceConfigInterface
{
    protected array $exposedMethods = ['get', 'post', 'put', 'patch', 'delete'];
    protected ?string $formClass = null;
    protected array $capabilities = ['pagination', 'search', 'sort'];
    protected array $allowedRelations = [];
    protected int $maxLimit = 100;

    abstract public function name(): string;

    public function exposedMethods(): array
    {
        return $this->exposedMethods;
    }

    public function formClass(): ?string
    {
        return $this->formClass;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function allowedRelations(): array
    {
        return $this->allowedRelations;
    }

    public function maxLimit(): int
    {
        return $this->maxLimit;
    }

    public function beforeSave(array $data): array
    {
        return $data;
    }

    public function afterSave(array $record): array
    {
        return $record;
    }

    public function beforeQuery($query): void
    {
        // No-op
    }

    public function afterQuery(array $data): array
    {
        return $data;
    }
}
