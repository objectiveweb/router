# Middleware

Objectiveweb Router uses middleware attributes to intercept controller execution.

Middleware may implement \`Objectiveweb\Router\MiddlewareInterface\` when it provides both hooks. The router invokes a hook only when that method exists, so a middleware class may also implement only \`before()\` or only \`after()\`.

## Interface

\`\`\`php
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
\`\`\`

\`before()\` must return the complete argument list that will be passed to the controller. Returning anything other than an array is a 500 error.

\`after()\` receives the controller response and may return a transformed response.

## Applying middleware

Class-level middleware applies to every routed method:

\`\`\`php
use Objectiveweb\Router\Middleware;

#[Middleware(AuthenticationMiddleware::class)]
class ProductsController
{
    // ...
}
\`\`\`

Method-level middleware applies only to one controller method:

\`\`\`php
use Objectiveweb\Router\Middleware;

class ProductsController
{
    #[Middleware(AuditMiddleware::class, 'products.read')]
    public function index(array $query): array
    {
        return [];
    }
}
\`\`\`

Arguments after the middleware class name are passed to the middleware constructor through Dice.

## Execution order

Middleware definitions are combined in this precedence order:

1. Router defaults configured through \`middlewares\`.
2. Controller class attributes.
3. Controller method attributes.

A narrower scope replaces broader middleware of the same class. Repeated middleware using the same class at the winning scope is preserved in declaration order.

For the final middleware list:

1. \`before()\` hooks run in declaration order.
2. The controller method runs.
3. \`after()\` hooks run in reverse order.

This gives normal middleware unwinding around the controller response.

## Example

\`\`\`php
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
\`\`\`

In this example, the method-level \`LoggingMiddleware\` replaces the class-level instance because both use the same middleware class.

## Default middleware

Middleware can also be configured when the router is created:

\`\`\`php
$router = new \Objectiveweb\Router(null, [
    'middlewares' => [
        AuthenticationMiddleware::class => [],
        LoggingMiddleware::class => ['http'],
    ],
]);
\`\`\`

Class- or method-level attributes for the same middleware class replace that default definition.

## Errors and dependency injection

Middleware is constructed through the same Dice container used for controllers, so constructor dependencies can be injected normally.

Exceptions and PHP Errors raised by middleware remain inside the route Throwable boundary and are converted to controlled HTTP responses.
