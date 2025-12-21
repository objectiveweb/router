# Middleware System in Objectiveweb Router

The Objectiveweb Router framework provides a flexible middleware system that allows you to intercept and modify HTTP requests and responses before and after controller method execution.

## Overview

Middleware is implemented as PHP classes that can be applied to controllers or specific controller methods using attributes. The middleware system provides hooks for pre-processing requests (`before` method) and post-processing responses (`after` method).

## Middleware Class Structure

All middleware classes must extend the base `Objectiveweb\Router\Middleware` class:

```php
<?php

namespace Objectiveweb\Router;

class Middleware 
{
    public function before($method, $fn, $params): mixed {
        return $params;
    }

    public function after($method, $fn, $params, $response): mixed {
        return $response;
    }
}
```

## Applying Middleware

Middleware can be applied at two levels:

### Class-Level Middleware

Applied to all methods in a controller:

```php
<?php

use Objectiveweb\Router\Middleware;

#[Middleware]
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
    #[Middleware]
    public function index() 
    {
        // This middleware will only be applied to the index method
    }
}
```

## Middleware Instantiation

Middleware classes are now instantiated using the dependency injection container (`$this->create`) instead of manual instantiation. This ensures that dependencies are properly injected into middleware classes, similar to how controllers are instantiated.

In the controller method, middleware instantiation happens like this:

```php
// With the middlewares list, let's instantiate and execute each
foreach ($middlewares as $mw_class => $mw_args) {
    // instantiate middleware
    $mw = $this->create($mw_class, $mw_args, [ get_class($controller).$mw_class ]);

    $middlewares[$mw_class] = $mw;

    // execute before() middleware functions
    if (method_exists($mw, 'before')) {
        $params = call_user_func([$mw, 'before'], $method, $fn, $params);
    }
}
```

This approach ensures that middleware classes can receive dependencies through constructor injection, just like controllers do.

## Middleware Execution Flow

When a request is processed:

1. **Class-level middleware** is executed first (in the order they are defined)
2. **Method-level middleware** is executed after class middleware (if present)
3. **Before hooks** are called with the HTTP method, function name, and parameters
4. **Controller method** is executed
5. **After hooks** are called with the HTTP method, function name, parameters, and response

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

use Objectiveweb\Router\Middleware;

class LoggingMiddleware extends Middleware
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
    #[LoggingMiddleware]
    public function index()
    {
        return ['message' => 'Hello World'];
    }
    
    #[LoggingMiddleware]
    public function get($id)
    {
        return ['id' => $id, 'data' => 'Some data'];
    }
}
```

## Authentication Middleware Example

The framework includes a built-in authentication middleware example:

```php
<?php

namespace Objectiveweb\Auth\Attributes;

use Objectiveweb\Auth\AuthException;
use Objectiveweb\Router\Middleware;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequireRole extends Middleware
{
    public \Objectiveweb\Auth $auth;

    private $role;

    public function __construct(string|array $role)
    {
        $this->role = is_array($role) ? $role : [$role];
    }

    public function after($method, $fn, $params, $response): mixed
    {
        if ($this->auth->check() && is_array($response) && !isset($response['_user'])) {
            $response['_user'] = $this->auth->user();
        }

        return $response;
    }

    public function before($method, $fn, $params): mixed
    {
        if ($this->auth->check()) {
            $scopes = \Objectiveweb\Auth::AUTHENTICATED;

            $this->user = $this->auth->user();

            if (is_array($this->user['scopes'])) {
                $scopes = array_merge($scopes, $this->user['scopes']);
            }
        } else {
            $scopes = \Objectiveweb\Auth::ANONYMOUS;
        }

        if (count(array_intersect($this->role, $scopes)) == 0) {
            throw new AuthException("Forbidden", $scopes[0] == 'anon' ? 401 : 403);
        }

        return $params;
    }
}
```

## Best Practices

1. **Middleware Order**: Class-level middleware executes before method-level middleware
2. **Parameter Modification**: Use `before()` to modify request parameters before controller execution
3. **Response Modification**: Use `after()` to modify response data after controller execution
4. **Error Handling**: Middleware can throw exceptions that will be handled by the router
5. **Dependency Injection**: Middleware can receive dependencies through constructor injection

## Advanced Usage

Middleware can also receive dependencies from the controller:

```php
<?php

class MyMiddleware extends Middleware
{
    public $auth; // Will be injected from controller
    
    public function before($method, $fn, $params): mixed
    {
        // Access injected dependencies
        if ($this->auth->check()) {
            // Do something with auth
        }
        
        return $params;
    }
}
