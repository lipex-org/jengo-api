# Jengo API

An automated, configuration-driven REST API engine for the Jengo framework inside CodeIgniter 4 applications. It generates dynamic endpoints, executes schema-driven queries, handles bulk transactional writes, secures actions via validation handlers, and automatically publishes interactive OpenAPI/Swagger documentation.

---

## Features

- **Automated REST Resource Routing**: Expose full CRUD endpoints automatically with clean HTTP verb mapping.
- **Interactive OpenAPI/Swagger Generation**: Auto-compiles active endpoints, parameter filters, and database schemas into collapsible documentation sections.
- **DTO-Driven Stateful Mutations**: Chain versioned routing dynamically (e.g., publishing version namespaces alongside distinct options).
- **Atomic Bulk-Write Transactions**: Batch payload creations and updates processed sequentially inside nested database transactions.
- **Granular Validation Mapping**: Enforce input validations using verb-mapped or global FormHandlers.
- **Relational Tree Derivations**: Query and resolve nested relationship trees on the fly using `?derive=relation_name`.
- **Database ID Obfuscation**: Native integration with Sqids to hash and decode auto-increment primary/foreign keys out of the box.

---

## Installation

Add the package to your Composer dependencies:

```bash
composer require jengo/api
```

---

## Getting Started

### 1. Define Your Resource Config
Create a configuration file extending `Jengo\Api\Support\ResourceConfig` to define policies, validation rules, and allowed relations for your resource:

```php
namespace App\Api;

use Jengo\Api\Support\ResourceConfig;
use App\Forms\CreateUserForm;
use App\Forms\UpdateUserForm;

class UserResourceConfig extends ResourceConfig
{
    // Define the matching version constraints
    protected $version = 'v1';

    // Allow relationship derivations
    protected array $allowedRelations = ['posts'];

    // Obfuscate primary/foreign keys in outward-facing payloads
    protected array $obfuscatedFields = ['id'];

    // Bind HTTP verbs to specific validation FormHandlers
    protected $formClass = [
        'post' => CreateUserForm::class,
        'put'  => UpdateUserForm::class,
    ];

    public function name(): string
    {
        return 'users';
    }
}
```

> [!IMPORTANT]
> To use database key obfuscation, your database Entity class must extend `Jengo\Base\Entities\BaseEntity` and declare the targeted fields in its `$obfuscatedFields` property.

---

### 2. Register Resource in Jengo API
Register your resource configurations inside the Jengo configuration file `Config/JengoApi.php`:

```php
namespace Config;

use Jengo\Api\Config\JengoApi as BaseJengoApi;
use App\Api\UserResourceConfig;
use App\Api\PostResourceConfig;

class JengoApi extends BaseJengoApi
{
    public string $apiName = 'My Jengo Application API';
    public string $apiBaseUrl = '/api';

    public array $resources = [
        UserResourceConfig::class,
        PostResourceConfig::class,
    ];
}
```

---

### 3. Publish Versioned Routes
Publish your API endpoints and documentation routes inside `Config/Routes.php`:

```php
use Jengo\Api\Router;
use Jengo\Api\Support\RouterOptions;
use Jengo\Api\Support\DocsOptions;

// Publish version 1 routes with documentation JSON & interactive Swagger UI
Router::publish($routes, new RouterOptions(
    version: 'v1',
    docs: new DocsOptions(
        route: 'docs',       // JSON spec at /api/v1/docs
        uiRoute: 'docs/ui'   // Swagger UI at /api/v1/docs/ui
    )
));
```

You can also chain mutations dynamically to handle multiple API versions concurrently:

```php
Router::publish($routes, new RouterOptions(version: 'v1'))
      ->mutate(new RouterOptions(version: 'v2'));
```

---

## Query Capabilities

Once active, resources support powerful URL query parameters out of the box:

- **Pagination**: `/api/v1/users?page=2&limit=15`
- **Sorting**: `/api/v1/users?sort=-created_at` (prefix `-` for descending order)
- **Searching**: `/api/v1/users?search=Alice`
- **Derivations**: `/api/v1/users?derive=posts` (resolves relationships automatically)

---

## Relational Mutations & Bulk Writes

Payload writes support sequential batch arrays and transactional mutations:

### Create Multiple Records (Bulk POST)
```json
POST /api/v1/users
[
  { "name": "John Doe", "email": "john@example.com" },
  { "name": "Jane Doe", "email": "jane@example.com" }
]
```

### Create Nested Relationships (POST)
```json
POST /api/v1/users
{
  "name": "Alice Smith",
  "email": "alice@example.com",
  "posts": [
    { "title": "My First Post", "body": "Post body content..." },
    { "title": "Another Post", "body": "More post content..." }
  ]
}
```

All batch and nested actions are processed atomically inside database transaction savepoints, rolling back completely if any single row or hook validation fails.
