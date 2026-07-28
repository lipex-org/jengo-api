<?php

declare(strict_types=1);

namespace Jengo\Api\Commands\Variants\Make;

use Jengo\Base\Commands\Core\AbstractGeneratorVariant;

class ApiResourceVariant extends AbstractGeneratorVariant
{
    protected $component = 'Api';
    protected $directory = 'Api';

    public static function name(): string
    {
        return 'api_resource';
    }

    public static function description(): string
    {
        return 'Generates a new Jengo API Resource Configuration class.';
    }

    public function arguments(): array
    {
        return [
            'class_name' => 'Name of the class to create',
        ];
    }

    public function options(): array
    {
        return [
            '--namespace' => 'Namespace to create the class in',
            '--module'    => 'Module to create the class in',
            '--force'     => 'Overwrite existing file',
        ];
    }

    /**
     * Parse the template file to replace placeholders.
     */
    protected function parseTemplate(string $class, array $search = [], array $replace = [], array $data = []): string
    {
        $templatePath = dirname(dirname(dirname(__DIR__))) . '/Commands/Generators/Views/api_resource.tpl.php';
        $content = file_get_contents($templatePath);


        $parts = explode('\\', $class);
        $className = end($parts);
        array_pop($parts);
        $namespace = implode('\\', $parts);

        // Generate dynamic default resource name
        // E.g. UsersResource -> users
        $resourceName = strtolower(str_replace('Resource', '', $className));

        $content = str_replace('{namespace}', $namespace, $content);
        $content = str_replace('{class}', $className, $content);
        $content = str_replace('{name}', $resourceName, $content);

        return $content;
    }

    /**
     * Prepare options and do modifications.
     */
    protected function prepare(string $class): string
    {
        return $this->parseTemplate($class);
    }
}
