<?php

declare(strict_types=1);

namespace Jengo\Api\Commands;

use Jengo\Base\Commands\Core\AbstractMasterCommand;

class ApiCommand extends AbstractMasterCommand
{
    protected $group = 'Jengo';
    protected $name = 'jengo:api';
    protected $description = 'Consolidated API management tools.';
    protected $usage = 'jengo:api <variant> [arguments] [options]';

    protected string $variantPath = 'Commands/Variants/Api';
}
