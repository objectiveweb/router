# Middleware System in Objectiveweb Router

The Objectiveweb Router framework provides a flexible middleware system that allows you to intercept and modify HTTP requests and responses before and after controller method execution.

## Overview

Middleware is implemented as PHP classes that can be applied to controllers or specific controller methods using attributes. The middleware system provides hooks for pre-processing requests (`before` method) and post-processing responses (`after` method).

## Middleware Class Structure

All middleware classes must implement the `Objectiveweb\Router\MiddlewareInterface`:

```php
<?php

namespace Objectiveweb\Router;

interface MiddlewareInterface
{
    /**
     * Runs before controller execution.
     * Must return updated params or throw.
     */
    public function before(
        string $method,
        string $fn,
        array  $params
    ): mixed;

    public function after(
        string $method,
        string $fn,
        array  $params,
        array|null  $response): mixed;
}
```

## Applying Middleware

Middleware can be applied at two levels:

### Class-Level Middleware

Applied to all methods in a controller:

```php
<?php

use Objectiveweb\Router\Middleware;

#[Middleware(MyMiddleware::class, 'arg1', 'arg2')]
class MyController 
{
    // This middleware will be applied to all methods in this controller
}
```

### Method-Level Middleware

Applied to specific methods only:

```php
<?php

use Objectiveweb\Router\Middleware;

class MyController 
{
    #[Middleware(MyMiddleware::class, 'arg1', 'arg2')]
    public function index() 
    {
        // This middleware will only be applied to the index method
    }
}
```

## Middleware Execution Flow

When a request is processed:

1. **Class-level middleware** is executed first (in the order they are defined)
2. **Method-level middleware** is executed after class middleware (if present)
3. **For each Middleware: Before hooks** are called with the HTTP method, function name, and parameters
4. **Controller method** is executed
5. **For each Middleware: After hooks** are called with the HTTP method, function name, parameters, and response

## Method Signatures

### `before($method, $fn, $params): mixed`

- `$method`: HTTP method (GET, POST, PUT, DELETE, etc.)
- `$fn`: Controller method name being called
- `$params`: Array of parameters passed to the controller method
- Returns: Modified parameters array

### `after($method, $fn, $params, $response): mixed`

- `$method`: HTTP method (GET, POST, PUT, DELETE, etc.)
- `$fn`: Controller method name being called
- `$params`: Array of parameters passed to the controller method
- `$response`: The response returned by the controller method
- Returns: Modified response

## Example Implementation

Here's a complete example of a middleware implementation:

```php
<?php

namespace Breakfastweekend\App\Middleware;

use Objectiveweb\Router\MiddlewareInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    public function before($method, $fn, $params): mixed
    {
        // Log request details
        error_log("Request: $method $fn with params: " . json_encode($params));
        
        // Return modified parameters if needed
        return $params;
    }

    public function after($method, $fn, $params, $response): mixed
    {
        // Log response details
        error_log("Response: $method $fn with response: " . json_encode($response));
        
        // Return modified response if needed
        return $response;
    }
}
```

## Usage in Controllers

```php
<?php

use Breakfastweekend\App\Middleware\LoggingMiddleware;
use Objectiveweb\Router\Middleware;

class MyController 
{
    #[Middleware(LoggingMiddleware::class)]
    public function index()
    {
        return ['message' => 'Hello World'];
    }
    
    #[Middleware(LoggingMiddleware::class)]
    public function get($id)
    {
        return ['id' => $id, 'data' => 'Some data'];
    }
}
```

## Best Practices

1. **Middleware Order**: Class-level middleware executes before method-level middleware
2. **Parameter Modification**: Use `before()` to modify request parameters before controller execution
3. **Response Modification**: Use `after()` to modify response data after controller execution
4. **Error Handling**: Middleware can throw exceptions that will be handled by the router
5. **Dependency Injection**: Middleware can receive dependencies through constructor injection
