# Middleware

Objectiveweb Router uses middleware attributes to intercept controller execution.

Middleware may implement `Objectiveweb\Router\MiddlewareInterface` when it provides both hooks. The router invokes a hook only when that method exists, so a middleware class may also implement only `before()` or only `after()`.

## Interface

```php
<?php

namespace Objectiveweb\Router;

interface MiddlewareInterface
{
    public function before(
        string $method,
        string $fn,
        array $params
    ): array;

    public function after(
        string $method,
        string $fn,
        array $params,
        mixed $response
    ): mixed;
}
```

`before()` must return the complete argument list that will be passed to the controller. Returning anything other than an array is a 500 error.

`after()` receives the controller response and may return a transformed response.

## Applying middleware

Class-level middleware applies to every routed method:

```php
use Objectiveweb\Router\Middleware;

#[Middleware(AuthenticationMiddleware::class)]
class ProductsController
{
    // ...
}
```

Method-level middleware applies only to one controller method:

```php
use Objectiveweb\Router\Middleware;

class ProductsController
{
    #[Middleware(AuditMiddleware::class, 'products.read')]
    public function index(array $query): array
    {
        return [];
    }
}
```

Arguments after the middleware class name are passed to the middleware constructor through Dice.

## Global request middleware

Request middleware is application-wide middleware around a matched route. It runs before callback or controller resolution, so it is appropriate for concerns that are not tied to a controller method, such as CORS, tracing, request IDs, and global response decoration.

Request middleware is constructed through the Router's Dice container.

```php
use Objectiveweb\Router\RequestMiddlewareInterface;

class TracingMiddleware implements RequestMiddlewareInterface
{
    public function before(string $method, string $path): void
    {
        // Start request-level work.
    }

    public function after(
        string $method,
        string $path,
        mixed $response
    ): mixed {
        // Finish request-level work or transform the response.
        return $response;
    }
}
```

Register request middleware through constructor configuration:

```php
$router = new \Objectiveweb\Router(null, [
    'request.middlewares' => [
        TracingMiddleware::class => ['http'],
    ],
]);
```

or append definitions explicitly:

```php
$router->addRequestMiddleware(TracingMiddleware::class, ['http']);
```

Constructor arguments are passed to Dice through `Router::create()`, so normal DI rules and shared dependencies continue to work.

For a matched controller request, execution order is:

1. Request middleware `before()` hooks in declaration order.
2. Controller middleware `before()` hooks in declaration order.
3. Controller method.
4. Controller middleware `after()` hooks in reverse order.
5. Request middleware `after()` hooks in reverse order.
6. `Router::respond()`.

If the callback/controller throws, request middleware `after()` hooks are not run; the Throwable is handled by Router's normal error boundary. Hard termination through `respond()`, `redirect()`, or `exit()` also skips remaining hooks.

A class may provide only `before()` or only `after()`; Router checks for each hook before invoking it. Implement `RequestMiddlewareInterface` when both hooks are provided.

### CORS

`Objectiveweb\Router\CorsMiddleware` is a built-in request middleware. The existing convenience API now registers this middleware rather than using controller-specific CORS logic:

```php
$router->setCors('https://app.example');
```

Equivalent explicit registration:

```php
$router->addRequestMiddleware(
    \Objectiveweb\Router\CorsMiddleware::class,
    ['https://app.example']
);
```

CORS headers are emitted before controller resolution, so they also apply to controller errors. An OPTIONS request carrying both `Origin` and `Access-Control-Request-Method` is treated as a CORS preflight, receives the configured CORS headers, and terminates with HTTP 204 before controller resolution.

The CORS middleware constructor also accepts optional credentials, allowed methods, allowed request headers, and exposed response headers:

```php
$router->addRequestMiddleware(
    \Objectiveweb\Router\CorsMiddleware::class,
    [
        'https://app.example',
        true,
        ['GET', 'POST', 'OPTIONS'],
        ['Authorization', 'Content-Type'],
        ['content-range'],
    ]
);
```

## Execution order

Middleware definitions are combined in this precedence order:

1. Router defaults configured through `middlewares`.
2. Controller class attributes.
3. Controller method attributes.

A narrower scope replaces broader middleware of the same class. Repeated middleware using the same class at the winning scope is preserved in declaration order.

For the final middleware list:

1. `before()` hooks run in declaration order.
2. The controller method runs.
3. `after()` hooks run in reverse order.

This gives normal middleware unwinding around the controller response.

## Terminating a request

Middleware has three supported control-flow patterns:

1. **Continue normally.** Return the complete controller argument array from `before()`.
2. **Reject the request.** Throw an exception with an HTTP status code. The exception remains inside Router's Throwable boundary and is converted to the negotiated error response.
3. **Terminate immediately.** Call a terminating response helper such as `Router::respond()` or `Router::redirect()`, or call `exit()` directly.

Example authorization guard:

```php
public function before(string $method, string $fn, array $params): array
{
    if (!$this->auth->check()) {
        throw new AuthException('Authentication required', 401);
    }

    return $params;
}
```

Use exceptions for request failures such as authentication, authorization, validation, rate limiting, and other error conditions. This keeps the failure inside the Router response pipeline, including status handling and content negotiation.

Example hard termination:

```php
public function before(string $method, string $fn, array $params): array
{
    if ($this->shouldRedirect()) {
        \Objectiveweb\Router::redirect('/login', 302);
    }

    return $params;
}
```

A hard termination ends request processing immediately. The controller is not called, remaining middleware does not run, and `after()` hooks are not executed, including hooks from middleware whose `before()` already ran.

This is appropriate for truly terminal flows such as redirects or a CORS preflight response. If cleanup, logging, or response transformation must happen after the decision, use normal Router control flow instead of `exit()`.

`before()` does not return a response value. Its return value is always the controller argument array. Successful early responses that need normal middleware unwinding are not modeled by the v3 middleware contract.

## Example

```php
<?php

use Objectiveweb\Router\Middleware;
use Objectiveweb\Router\MiddlewareInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private string $channel = 'http')
    {
    }

    public function before(
        string $method,
        string $fn,
        array $params
    ): array {
        error_log("[$this->channel] $method $fn");

        return $params;
    }

    public function after(
        string $method,
        string $fn,
        array $params,
        mixed $response
    ): mixed {
        error_log("[$this->channel] completed $method $fn");

        return $response;
    }
}

#[Middleware(LoggingMiddleware::class, 'products')]
class ProductsController
{
    #[Middleware(LoggingMiddleware::class, 'products.index')]
    public function index(array $query): array
    {
        return ['ok' => true];
    }
}
```

In this example, the method-level `LoggingMiddleware` replaces the class-level instance because both use the same middleware class.

## Default middleware

Middleware can also be configured when the router is created:

```php
$router = new \Objectiveweb\Router(null, [
    'middlewares' => [
        AuthenticationMiddleware::class => [],
        LoggingMiddleware::class => ['http'],
    ],
]);
```

Class- or method-level attributes for the same middleware class replace that default definition.

## Errors and dependency injection

Middleware is constructed through the same Dice container used for controllers, so constructor dependencies can be injected normally.

Exceptions and PHP Errors raised by middleware remain inside the route Throwable boundary and are converted to controlled HTTP responses.
