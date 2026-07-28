<?php

declare(strict_types=1);

namespace Jengo\Api\Contracts;

interface ResourceConfigInterface
{
    /**
     * Get the resource name (e.g. 'users').
     */
    public function name(): string;

    /**
     * Get the allowed HTTP methods (e.g. ['get', 'post']).
     */
    public function exposedMethods(): array;

    /**
     * Get the FormHandler class name for validation, if any.
     */
    public function formClass(): ?string;

    /**
     * Get the allowed query capabilities.
     */
    public function capabilities(): array;

    /**
     * Get the allowed relation derivations.
     */
    public function allowedRelations(): array;

    /**
     * Get the maximum allowed pagination limit.
     */
    public function maxLimit(): int;
}
