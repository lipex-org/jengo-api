<?php

declare(strict_types=1);

namespace Jengo\Api\Support;

class RouterOptions
{
    public array $except;
    public array $only;
    public ?string $version;
    public DocsOptions $docs;

    public function __construct(
        array $except = [],
        array $only = [],
        ?string $version = null,
        ?DocsOptions $docs = null
    ) {
        $this->except = $except;
        $this->only = $only;
        $this->version = $version;
        $this->docs = $docs ?? new DocsOptions();
    }

    /**
     * Return a new RouterOptions instance with values copied and overridden.
     */
    public function mutate(RouterOptions $overrides): self
    {
        $except = !empty($overrides->except) ? $overrides->except : $this->except;
        $only = !empty($overrides->only) ? $overrides->only : $this->only;
        $version = $overrides->version !== null ? $overrides->version : $this->version;

        $docs = $this->docs;
        if ($overrides->docs !== null) {
            $docs = new DocsOptions(
                $overrides->docs->route !== 'docs' ? $overrides->docs->route : $this->docs->route,
                $overrides->docs->uiRoute !== 'docs/ui' ? $overrides->docs->uiRoute : $this->docs->uiRoute
            );
        }

        return new self($except, $only, $version, $docs);
    }
}
