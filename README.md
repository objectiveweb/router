# Objectiveweb URL Router [![CI](https://github.com/objectiveweb/router/actions/workflows/ci.yml/badge.svg)](https://github.com/objectiveweb/router/actions/workflows/ci.yml)

Lightweight PHP URL router with controller mapping and dependency injection.

## Requirements

- PHP 8.1+
- Composer

## Installation

```bash
composer require objectiveweb/router:^3.0
```

## Basic routing

```php
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
```

The verb helpers append request data after regex captures:

- `GET()` and `DELETE()` append `$_GET`.
- `POST()`, `PUT()`, and `PATCH()` append the Content-Type-aware decoded request body.
- `GET()` also matches `HEAD` requests. The callback runs with GET semantics, but Router suppresses the final response body.
- `route()` passes regex captures directly, plus any additional arguments supplied to `route()`.

Callbacks may also be written as `[Controller::class, 'method']`. Class-name callbacks are instantiated through Dice before invocation.

## Public API notes

Router v3 uses explicit PHP 8.1 types on its straightforward public APIs. Route callbacks intentionally remain `mixed`: callback validation happens inside the route Throwable boundary so invalid callbacks become controlled HTTP responses rather than uncaught argument `TypeError` failures.

`Router::isAjax(): bool` remains available as a convenience check for `X-Requested-With: XMLHttpRequest`.

## Routing model

Objectiveweb Router is an **immediate regex dispatcher**, not a complete route-table dispatcher. Calls to `route()`, the HTTP verb helpers, and `controller()` evaluate regular expressions against the current request and may execute immediately; Router does not first collect every application route into a route table.

This is intentional: regular-expression routing and capture groups are first-class behavior, including controller-path captures that can be forwarded to controller constructors.

Because Router does not own a complete route table, it does not synthesize route-table features such as named routes, reverse URL generation, route introspection, automatic generic `OPTIONS`, or global `405 Method Not Allowed` / `Allow` responses. Applications can register explicit regex routes or request middleware when those behaviors are needed.

The convenience `GET()` helper and controller dispatcher implement normal `HEAD` fallback to GET semantics. Raw `route()` remains exactly the regex supplied by the application; use a pattern such as `(?:GET|HEAD) /path` when the raw route should accept both methods.

For controllers, this is an intentional v3 behavior change: legacy v2 `head()` and `headFoo()` handlers no longer receive HEAD requests. Define the GET handler instead; Router executes it with HEAD response-body suppression.

## Controllers

Bind a URL pattern to a controller:

```php
$router->controller('/products', App\ProductsController::class);
```

Controller paths are regular expressions. Capture groups in the controller path are passed to the controller constructor after any explicit constructor arguments supplied to `controller()`:

```php
$router->controller(
    '/accounts/([0-9]+)/regions/([a-z]+)',
    App\AccountController::class,
    'explicit-argument'
);
```

For `GET /accounts/42/regions/us/products`, Router constructs the controller as if Dice had been called with:

```php
$router->create(App\AccountController::class, [
    'explicit-argument',
    '42',
    'us',
]);
```

The remaining `products` path is then used for controller method resolution. Use non-capturing groups such as `(?:...)` when a regex group should affect matching without becoming a constructor argument.

Controller resolution follows these rules:

| Request | Preferred controller method |
| --- | --- |
| `GET /products` | `index($_GET)` |
| `POST /products` | `post($body)` |
| `PUT /products` | `put($body)` |
| `PATCH /products` | `patch($body)` |
| `GET /products/42` | `get('42', $_GET)` |
| `POST /products/42` | `post('42', $body)` |
| `GET /products/sale` | `getSale($_GET)`, then `sale($_GET)`, then `get('sale', $_GET)` |
| `POST /products/sale` | `postSale($body)`, then `sale($body)`, then `post('sale', $body)` |

Hyphens in custom path method names are converted to underscores.

For POST, PUT, and PATCH controller methods, the request body is appended as the final argument. If that final parameter is a class and JMS Serializer is installed, JSON and `application/*+json` requests are deserialized into that class. Unsupported or missing media types return 415 for class-typed bodies; malformed JSON returns 400.

See [controller mapping](doc/controller.md) for the full behavior.

## Middleware

Request/response interception uses repeatable `#[Middleware]` attributes rather than controller `before()` hooks.

```php
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
```

See [middleware documentation](docs/middleware.md) for ordering and override rules.

### Global request middleware

Request middleware wraps the incoming request and is constructed through the same Dice container as controllers. Its `before()` hooks run once before route matching begins:

```php
$router = new Router(null, [
    'request.middlewares' => [
        RequestIdMiddleware::class => [],
    ],
]);

$router->addRequestMiddleware(TracingMiddleware::class, ['http']);
```

Request middleware `before(string $method, string $path)` hooks run in declaration order. When a route produces a response, `after(string $method, string $path, mixed $response)` hooks run in reverse order and may transform that response. Hooks are optional when the class does not implement `RequestMiddlewareInterface`.

The built-in CORS middleware can be enabled with the compatibility helper:

```php
$router->setCors('https://app.example');
```

For public wildcard CORS, `$router->setCors('*')` automatically disables credentials so Router never emits the invalid `Access-Control-Allow-Origin: *` + `Access-Control-Allow-Credentials: true` combination.

It can also be registered/configured directly:

```php
use Objectiveweb\Router\CorsMiddleware;

$router->addRequestMiddleware(CorsMiddleware::class, [
    'https://app.example',
]);
```

CORS preflight requests terminate before controller resolution.

Middleware may reject a request by throwing an HTTP exception, or terminate immediately with `Router::respond()`, `$router->redirect()`, or `exit()`. Hard termination skips the controller, remaining middleware, and all `after()` hooks.

## Dependency injection

Router composes Objectiveweb Dice and exposes `addRule()` and `create()` as its supported DI API.

```php
$router->addRule(PDO::class, [
    'shared' => true,
    'constructParams' => [
        'mysql:host=127.0.0.1;dbname=mydb',
        'username',
        'password',
    ],
]);

$pdo = $router->create(PDO::class);
```

Controllers and class callbacks are instantiated through the same container:

```php
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
```

## Trusted hosts, proxies, and URL generation

`$router->url()` and `$router->redirect()` are instance methods because absolute URL generation depends on Router configuration.

Direct `HTTP_HOST` values are ignored by default. Configure the hostnames that are allowed to affect absolute URL generation:

```php
$router = new Router(null, [
    'trusted.hosts' => [
        'example.com',
        'api.example.com',
    ],
]);
```

Trusted host entries are hostnames only; request ports are matched independently. Matching is case-insensitive, ignores a trailing DNS dot, and normalizes IP literals. Set `'trusted.hosts' => '*'` to accept any syntactically valid direct `HTTP_HOST`. When a direct host is not trusted, Router falls back to `SERVER_NAME` and `SERVER_PORT`.

Forwarded headers are also ignored by default. Configure the exact proxy addresses or CIDR ranges that are allowed to supply external request metadata:

```php
$router = new Router(null, [
    'trusted.hosts' => ['example.com'],
    'trusted.proxies' => [
        '127.0.0.1',
        '10.42.0.0/16',
        '2001:db8:42::/48',
    ],
]);
```

Only when `REMOTE_ADDR` matches one of these proxy entries can `X-Forwarded-Proto`, `X-Forwarded-Host`, and `X-Forwarded-Port` affect `url('self')` / `url()`. Forwarded host values are validated separately and do not need to appear in `trusted.hosts`.

Objectiveweb Router intentionally does not interpret comma-separated proxy chains. A trusted proxy is expected to remove client-supplied forwarding headers and write one authoritative value. Comma-separated or malformed forwarded values are ignored.

Private address ranges are not trusted automatically. Add only the actual proxy/network ranges controlled by the application infrastructure.

## Templates

Controller methods that return arrays may be rendered through PHP templates. The default template root is:

```text
<composer project root>/templates
```

For a controller bound to `/products`, Router looks for a method-specific template first and then an HTTP-method fallback. If both HTML and JSON representations are available, the request `Accept` header selects the representation.

Templates created by Router expose URL generation through the Template object itself:

```php
<a href="<?= $this->url('/products') ?>">Products</a>
```

This delegates to the owning Router, so template URL generation uses the same trusted-proxy policy without injecting a reserved `$router` or `$url` variable into template data.

You can configure the template root and layout when constructing the router:

```php
$router = new Router(__DIR__, [
    'template.root' => __DIR__ . '/templates',
    'template.layout' => 'main',
]);
```

## Error disclosure

Unhandled 5xx errors are redacted by default. Router logs the original Throwable, while clients receive only `Internal Server Error` in the negotiated HTML or JSON representation.

For local development, detailed 5xx error responses can be enabled explicitly:

```php
$router = new Router(null, [
    'debug' => true,
]);
```

Debug mode exposes the exception class and message and should not be enabled in production. 4xx exception details remain visible by default, and exceptions with explicitly registered serializers continue to use those serializers.

## Response negotiation

Router negotiates supported representations from `Accept`, including q-values, wildcards, and q=0 exclusions. `HEAD` uses the same representation selection as GET but never emits a body. Informational responses and statuses `204`, `205`, and `304` also never emit a body.

- Structured PHP values are JSON responses.
- Strings and renderable objects can provide HTML or JSON.
- Controller arrays with matching templates can provide HTML or JSON.
- Default Throwable responses can provide HTML or JSON while preserving their original 4xx/5xx status.
- If no available representation is acceptable, Router returns 406.

## Automatic controller routing

```php
$router->run('App');
```

A request such as `/products` maps to `App\ProductsController`. Root and unmatched controller names fall back to `App\HomeController`.

See `example/app-run.php` for a complete example.
