<?php

declare(strict_types=1);

namespace Jengo\Api\Installers;

use CodeIgniter\CLI\CLI;
use Jengo\Base\Installers\Contracts\AbstractInstaller;

class ApiInstaller extends AbstractInstaller
{
    public static function name(): string
    {
        return 'api';
    }

    public static function description(): string
    {
        return 'Setup the Jengo API configurations and routing';
    }

    public static function reasonForSkipping(): string
    {
        return 'Jengo API is already configured.';
    }

    public function shouldRun(): bool
    {
        $targetConfig = APPPATH . 'Config/JengoApi.php';
        $routesFile = APPPATH . 'Config/Routes.php';

        $hasConfig = file_exists($targetConfig);
        $hasRoute = file_exists($routesFile) && str_contains((string) file_get_contents($routesFile), 'Router::publish');

        return !$hasConfig || !$hasRoute;
    }

    public function install(): void
    {
        $this->addRun();

        CLI::write('Setting up Jengo API configurations and routing...', 'cyan');

        $force = CLI::getOption('force') !== null;
        $targetConfig = APPPATH . 'Config/JengoApi.php';
        $sourceConfig = dirname(__DIR__) . '/Config/JengoApi.php';

        if (file_exists($targetConfig) && !$force) {
            CLI::write("Configuration file already exists at [{$targetConfig}]. Use --force to overwrite.", 'yellow');
        } else {
            $content = file_get_contents($sourceConfig);
            if ($content !== false) {
                $content = str_replace('namespace Jengo\Api\Config;', "namespace Config;\n\nuse Jengo\Api\Config\JengoApi as BaseJengoApi;", $content);
                $content = str_replace('class JengoApi extends BaseConfig', 'class JengoApi extends BaseJengoApi', $content);
                $content = str_replace('use CodeIgniter\Config\BaseConfig;', '', $content);
                if (file_put_contents($targetConfig, $content) !== false) {
                    CLI::write("Published config file to [{$targetConfig}]", 'green');
                } else {
                    CLI::error("Failed to write config file to [{$targetConfig}].");
                }
            } else {
                CLI::error("Failed to read source config file from [{$sourceConfig}].");
            }
        }

        // Automatically append routes registration to app/Config/Routes.php
        $routesFile = APPPATH . 'Config/Routes.php';
        if (file_exists($routesFile)) {
            $routesContent = (string) file_get_contents($routesFile);
            if (!str_contains($routesContent, 'Router::publish')) {
                $routesContent .= "\n\n\$routes->group('api', static function (\\CodeIgniter\\Router\\RouteCollection \$routes) {\n    \\Jengo\\Api\\Router::publish(\$routes);\n});\n";
                if (file_put_contents($routesFile, $routesContent) !== false) {
                    CLI::write("Appended routes registration to [{$routesFile}]", 'green');
                } else {
                    CLI::error("Failed to append routes to [{$routesFile}].");
                }
            } else {
                CLI::write("Routes registration already present in [{$routesFile}]", 'yellow');
            }
        }

        CLI::newLine();
        CLI::write('Jengo API setup complete!', 'green');
    }
}
