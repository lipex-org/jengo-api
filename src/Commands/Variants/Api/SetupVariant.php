<?php

declare(strict_types=1);

namespace Jengo\Api\Commands\Variants\Api;

use CodeIgniter\CLI\CLI;
use Jengo\Base\Commands\Contracts\CommandVariantInterface;

class SetupVariant implements CommandVariantInterface
{
    public static function name(): string
    {
        return 'setup';
    }

    public static function description(): string
    {
        return 'Setup the Jengo API configurations and routing for the application.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function options(): array
    {
        return [
            '--force' => 'Force overwrite of existing configuration files',
        ];
    }

    public function run(array $params): void
    {
        CLI::write('Setting up Jengo API package...', 'cyan');

        $force = CLI::getOption('force') !== null;

        $targetConfig = APPPATH . 'Config/JengoApi.php';
        // Locate source config file relative to this folder
        $sourceConfig = dirname(dirname(dirname(__DIR__))) . '/Config/JengoApi.php';

        if (file_exists($targetConfig) && !$force) {
            CLI::write("Configuration file already exists at [{$targetConfig}]. Use --force to overwrite.", 'yellow');
        } else {
            $content = file_get_contents($sourceConfig);
            if ($content !== false) {
                $content = str_replace('namespace Jengo\Api\Config;', 'namespace Config;', $content);
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
            $routesContent = file_get_contents($routesFile);
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
        CLI::write('Next Steps:', 'cyan');
        CLI::write('1. Open app/Config/JengoApi.php and configure your REST resources.');
        CLI::write('2. Verify the API routing group registered inside app/Config/Routes.php.');
        CLI::newLine();
        CLI::write('Jengo API setup complete!', 'green');
    }
}
