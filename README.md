# Jengo API

An automated, configuration-driven REST API and interactive OpenAPI/Swagger documentation engine for CodeIgniter 4 and the Jengo Framework.

Documentation: https://lipex-org.github.io/jengophp.com/packages/api

## Installation

```bash
composer require jengo/api
php spark jengo:api setup
```

## Quick Start

```php
// app/Config/Routes.php
use Jengo\Api\Router;
use Jengo\Api\Support\RouterOptions;
use Jengo\Api\Support\DocsOptions;

Router::publish($routes, new RouterOptions(
    version: 'v1',
    docs: new DocsOptions(route: 'docs', uiRoute: 'docs/ui')
));
```

## Documentation

For full guides on ResourceConfig, atomic bulk writes, relational derivations, key obfuscation, and Swagger generation, visit https://lipex-org.github.io/jengophp.com/packages/api.

## License

Released under the MIT License.
