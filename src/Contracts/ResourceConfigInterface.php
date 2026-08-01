<?php

declare(strict_types=1);

namespace Jengo\Api\Contracts;

use Jengo\Api\Support\HookContext;

interface ResourceConfigInterface
{
    /**
     * Get the resource name (e.g. 'users').
     */
    public function name(): string;

    /**
     * Get the version prefix of the resource (e.g. 'v1' or ['v1', 'v2']), if any.
     *
     * @return string|array|null
     */
    public function version();

    /**
     * Get the authentication requirements for actions.
     * E.g. ['get' => true, 'post' => 'users.create']
     */
    public function requiredAuth(): array;

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

    /**
     * Get the fields that should be obfuscated/deobfuscated using Sqids.
     */
    public function obfuscatedFields(): array;

    /**
     * Hook run before a record is created or updated.
     */
    public function beforeSave(array $data, ?HookContext $context = null): array;

    /**
     * Hook run after a record is created or updated.
     */
    public function afterSave(array $record, ?HookContext $context = null): array;

    /**
     * Hook run before query execution (GET collection/single).
     * Allows dynamically modifying the Jengo Query Builder query.
     */
    public function beforeQuery($query, ?HookContext $context = null): void;

    /**
     * Hook run after query execution.
     * Allows transforming the queried records array.
     */
    public function afterQuery(array $data, ?HookContext $context = null): array;
}
