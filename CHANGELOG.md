# Changelog

All notable changes to Objectiveweb Router are documented in this file.

## [3.0.0] - Unreleased

### Breaking changes

- Require PHP 8.1 or newer.
- Router now composes Dice instead of extending it. Dependency injection remains available through `addRule()` and `create()`, but inherited Dice methods are no longer part of the Router API.
- Replace controller `before()` / `beforePost()` style hooks with attribute-based middleware.
- Middleware `before()` hooks must return the complete controller argument array.
- Method-level middleware replaces broader middleware of the same class; repeated middleware at the same scope is preserved and executed in declaration order.
- Responses are routed through `respond()`, including objects with registered serializers and exceptions.
- Controller object responses bypass template lookup unless they are arrays intended as template data.
- The default template directory is now resolved from the Composer application root as `<project>/templates`.
- The supported PHPUnit baseline is PHPUnit 10+.

### Added

- GitHub Actions CI for PHP 8.1 through 8.5.
- Dedicated release verification workflow for version tags.
- Repeatable middleware execution with reverse-order `after()` unwinding.
- Regression coverage for controller object responses, middleware ordering, template fallback, template root resolution, serializer response dispatch, and current controller routing behavior.
- MIT license.

### Changed

- Response negotiation now honors `Accept` media ranges, q-values, wildcards, and q=0 exclusions; HTML/JSON responses use explicit content types and unsupported requests receive 406.
- Require Objectiveweb Dice `^4.1.0`.
- Update JMS Serializer development compatibility to `^3.32`.
- Example JMS metadata now uses PHP attributes.
- Non-string response bodies are JSON-encoded before output.
- Route matching for the root controller now handles nested paths correctly.
- Composer metadata is validated with `composer validate --strict` in CI and release verification.

### Fixed

- Route execution now catches all PHP `Throwable` failures, including `TypeError`/`Error`, normalizes invalid exception codes to HTTP 500, and includes dependency-injection/callback resolution inside the HTTP error boundary.
- Request body parsing now follows `Content-Type`: JSON (including `+json` media types), URL-encoded forms, multipart forms, and raw/unknown bodies are handled explicitly.
- HTTP-method template fallback no longer uses an accidental variable-variable expression.
- Controller responses that are not arrays no longer reach the array-only template renderer.
- Overridden `respond()` methods work again through late static binding.
- Middleware without an `after()` method no longer crashes response processing.

## Migrating from 2.x

Version 3 is intentionally breaking and does not provide a compatibility layer for 2.x applications.

### PHP

Update the runtime to PHP 8.1 or newer:

```json
{
    "require": {
        "php": ">=8.1",
        "objectiveweb/router": "^3.0"
    }
}
```

### Dependency injection

Router no longer extends `Dice\Dice`.

Continue using the Router-level DI methods:

```php
$router->addRule(Service::class, [
    'shared' => true,
]);

$service = $router->create(Service::class);
```

Code that called other inherited Dice methods on the Router should create/configure dependencies through the supported Router API instead.

### Controller hooks and middleware

Controller methods such as `before()` and `beforePost()` are no longer invoked automatically. Move request/response interception to middleware attributes:

```php
use Objectiveweb\Router\Middleware;

#[Middleware(AuthenticationMiddleware::class)]
class Controller
{
    #[Middleware(AuditMiddleware::class)]
    public function index(array $query)
    {
        // ...
    }
}
```

A middleware `before()` method must return the complete controller argument array:

```php
public function before(string $method, string $fn, array $params): array
{
    return $params;
}
```

`after()` is optional. When present, it receives the controller response and may transform it. After hooks execute in reverse middleware order.

### Responses and serializers

Response representation is now negotiated from the request `Accept` header. Controller array results with a matching template can be rendered as `text/html` or returned as `application/json`; missing `Accept` behaves like `*/*` and prefers HTML when a template is available. Requests that reject all available representations receive HTTP 406.

All routed responses now enter the common `respond()` pipeline. Custom subclasses overriding `respond()` therefore see normal responses, registered-serializer responses, and exceptions.

A renderable object may return either a completed string body or a structured non-string value. Non-string values are JSON-encoded by the router.

### Templates

The default template directory is now:

```text
<composer project root>/templates
```

Passing an explicit Router root continues to override this default.

Controller templates receive array responses as their data context. Other response types bypass template lookup and continue through the response pipeline.

### Tests and development

The development test suite supports PHP 8.1-8.5 and PHPUnit 10-12. Run:

```bash
composer validate --strict
composer test
```
