<p align="center">
  <a href="https://lipex-org.github.io/jengophp.com/">
    <img src="https://raw.githubusercontent.com/lipex-org/docs/main/public/logo-full.png" width="220" alt="Jengo Logo">
  </a>
</p>

<h1 align="center">Jengo API</h1>

<p align="center">
  <strong>Automated configuration-driven REST API engine with live interactive Swagger/OpenAPI documentation and atomic bulk transactions for CodeIgniter 4 and Jengo.</strong>
</p>

<p align="center">
  <a href="https://lipex-org.github.io/jengophp.com/packages/api"><strong>Documentation</strong></a> •
  <a href="https://github.com/lipex-org/api/blob/main/LICENSE"><strong>License</strong></a> •
  <a href="https://github.com/lipex-org/api/issues"><strong>Issues</strong></a>
</p>

---

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
