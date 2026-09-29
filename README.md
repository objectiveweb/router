# Objectiveweb URL Router [![CI](https://github.com/objectiveweb/router/actions/workflows/ci.yml/badge.svg)](https://github.com/objectiveweb/router/actions/workflows/ci.yml)

Lightweight PHP URL router with controller mapping and dependency injection.

## Requirements

- PHP 8.1+
- Composer

## Installation

\`\`\`bash
composer require objectiveweb/router:^3.0
\`\`\`

## Basic routing

\`\`\`php
<?php

require __DIR__ . '/vendor/autoload.php';

use Objectiveweb\Router;

$router = new Router();

$router->GET('/?', function () {
    return 'Hello index';
});

$router->GET('/([a-z]+)/?', function (string $key, array $query) {
    if ($key === 'data') {
        return [1, 2, 3];
    }

    throw new RuntimeException("Unknown key: $key", 404);
});

$router->POST('/echo', function (array $body) {
    return $body;
});

$router->route('([A-Z]+) /(.*)', function (string $method, string $path) {
    return "Request matched $method /$path";
});
\`\`\`

The verb helpers append request data after regex captures:

- \`GET()\` and \`DELETE()\` append \`$_GET\`.
- \`POST()\` and \`PUT()\` append the Content-Type-aware decoded request body.
- \`route()\` passes regex captures directly, plus any additional arguments supplied to \`route()\`.

Callbacks may also be written as \`[Controller::class, 'method']\`. Class-name callbacks are instantiated through Dice before invocation.

## Controllers

Bind a URL pattern to a controller:

\`\`\`php
$router->controller('/products', App\ProductsController::class);
\`\`\`

Controller paths are regular expressions. Capture groups in the controller path are passed to the controller constructor after any explicit constructor arguments supplied to \`controller()\`:

\`\`\`php
$router->controller(
    '/accounts/([0-9]+)/regions/([a-z]+)',
    App\AccountController::class,
    'explicit-argument'
);
\`\`\`

For \`GET /accounts/42/regions/us/products\`, Router constructs the controller as if Dice had been called with:

\`\`\`php
$router->create(App\AccountController::class, [
    'explicit-argument',
    '42',
    'us',
]);
\`\`\`

The remaining \`products\` path is then used for controller method resolution. Use non-capturing groups such as \`(?:...)\` when a regex group should affect matching without becoming a constructor argument.

Controller resolution follows these rules:

| Request | Preferred controller method |
| --- | --- |
| \`GET /products\` | \`index($_GET)\` |
| \`POST /products\` | \`post($body)\` |
| \`PUT /products\` | \`put($body)\` |
| \`PATCH /products\` | \`patch($body)\` |
| \`GET /products/42\` | \`get('42', $_GET)\` |
| \`POST /products/42\` | \`post('42', $body)\` |
| \`GET /products/sale\` | \`getSale($_GET)\`, then \`sale($_GET)\`, then \`get('sale', $_GET)\` |
| \`POST /products/sale\` | \`postSale($body)\`, then \`sale($body)\`, then \`post('sale', $body)\` |

Hyphens in custom path method names are converted to underscores.

For POST, PUT, and PATCH controller methods, the request body is appended as the final argument. If that final parameter is a class and JMS Serializer is installed, JSON and \`application/*+json\` requests are deserialized into that class. Unsupported or missing media types return 415 for class-typed bodies; malformed JSON returns 400.

See [controller mapping](doc/controller.md) for the full behavior.

## Middleware

Request/response interception uses repeatable \`#[Middleware]\` attributes rather than controller \`before()\` hooks.

\`\`\`php
use Objectiveweb\Router\Middleware;
use Objectiveweb\Router\MiddlewareInterface;

class AuthenticationMiddleware implements MiddlewareInterface
{
    public function before(string $method, string $fn, array $params): array
    {
        // Validate or modify controller arguments.
        return $params;
    }

    public function after(
        string $method,
        string $fn,
        array $params,
        mixed $response
    ): mixed {
        return $response;
    }
}

#[Middleware(AuthenticationMiddleware::class)]
class ProductsController
{
    public function index(array $query): array
    {
        return [];
    }
}
\`\`\`

See [middleware documentation](docs/middleware.md) for ordering and override rules.

## Dependency injection

Router composes Objectiveweb Dice and exposes \`addRule()\` and \`create()\` as its supported DI API.

\`\`\`php
$router->addRule(PDO::class, [
    'shared' => true,
    'constructParams' => [
        'mysql:host=127.0.0.1;dbname=mydb',
        'username',
        'password',
    ],
]);

$pdo = $router->create(PDO::class);
\`\`\`

Controllers and class callbacks are instantiated through the same container:

\`\`\`php
class ProductsController
{
    public function __construct(private ProductsRepository $products)
    {
    }

    public function index(array $query): array
    {
        return $this->products->index();
    }
}

$router->controller('/products', ProductsController::class);
\`\`\`

## Templates

Controller methods that return arrays may be rendered through PHP templates. The default template root is:

\`\`\`text
<composer project root>/templates
\`\`\`

For a controller bound to \`/products\`, Router looks for a method-specific template first and then an HTTP-method fallback. If both HTML and JSON representations are available, the request \`Accept\` header selects the representation.

You can configure the template root and layout when constructing the router:

\`\`\`php
$router = new Router(__DIR__, [
    'template.root' => __DIR__ . '/templates',
    'template.layout' => 'main',
]);
\`\`\`

## Response negotiation

Router negotiates supported representations from \`Accept\`, including q-values, wildcards, and q=0 exclusions.

- Structured PHP values are JSON responses.
- Strings and renderable objects can provide HTML or JSON.
- Controller arrays with matching templates can provide HTML or JSON.
- Default Throwable responses can provide HTML or JSON while preserving their original 4xx/5xx status.
- If no available representation is acceptable, Router returns 406.

## Automatic controller routing

\`\`\`php
$router->run('App');
\`\`\`

A request such as \`/products\` maps to \`App\ProductsController\`. Root and unmatched controller names fall back to \`App\HomeController\`.

See \`example/app-run.php\` for a complete example.
