<?php

declare(strict_types=1);

namespace Jengo\Api\Support;

class DocsOptions
{
    /**
     * The route prefix for docs JSON. If false, docs endpoint is disabled.
     *
     * @var string|bool
     */
    public $route;

    /**
     * The route prefix for docs UI. If false, docs UI endpoint is disabled.
     *
     * @var string|bool
     */
    public $uiRoute;

    public function __construct(
        $route = 'docs',
        $uiRoute = 'docs/ui'
    ) {
        $this->route = $route;
        $this->uiRoute = $uiRoute;
    }
}
