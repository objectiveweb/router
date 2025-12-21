# Controller Mapping in Objectiveweb Router

The Objectiveweb Router framework uses a sophisticated controller mapping system that automatically routes HTTP requests to appropriate controller methods based on the URL structure and HTTP method.

## How Requests are Mapped to Controllers

### Controller Registration

Controllers are registered using the `controller()` method in the router. This method takes two parameters:
1. `$path` - The URL path prefix (e.g., `/vouchers`, `/venues`)
2. `$controller` - The controller class name or instance

Example from `public/index.php`:
```php
$app->controller('/auth', \Objectiveweb\Auth\Controller\OAuthController::class);
$app->controller('/admin/users', \Breakfastweekend\App\Controller\Admin\UsersController::class);
$app->controller('/vouchers', \Breakfastweekend\App\Controller\VouchersController::class);
$app->controller('/venues', \Breakfastweekend\App\Controller\VenuesController::class);
```

### Request Routing Logic

When a request is made to a registered controller path, the router follows these steps:

1. **HTTP Method Detection**: The router detects the HTTP method (GET, POST, PUT, DELETE, etc.)

2. **URL Parameter Parsing**: URL path parameters are extracted from the request path

3. **Method Resolution**: The router determines which controller method to call based on:
   - The HTTP method (GET, POST, PUT, DELETE)
   - URL parameters (if any)
   - The controller's available methods

### Method Resolution Rules

The router follows these rules to determine which method to call:

#### 1. Base Methods
- **GET /path/** → calls `index()` method
- **POST /path/** → calls `post()` method
- **PUT /path/** → calls `put()` method
- **DELETE /path/** → calls `delete()` method

#### 2. Parameter-Based Methods
When URL parameters are present, the router tries to match them to controller methods:

- **GET /path/123** → calls `get(123)` method
- **POST /path/123** → calls `post(123)` method
- **PUT /path/123** → calls `put(123)` method
- **DELETE /path/123** → calls `delete(123)` method

#### 3. Custom Method Resolution
If URL parameters match controller method names, the router will call that specific method:

- **GET /path/some-action** → calls `someAction()` method
- **POST /path/some-action** → calls `postSomeAction()` method

#### 4. Special Cases
- **GET /path/** with no parameters → calls `index()` method
- **GET /path/123** with no matching method → calls `get(123)` method

## Example Request Mappings

### Vouchers Controller Examples

Given the registration: `$app->controller('/vouchers', \Breakfastweekend\App\Controller\VouchersController::class);`

| Request | URL Path | Method Called |
|---------|----------|---------------|
| GET `/vouchers` | `/vouchers` | `index()` |
| GET `/vouchers/123` | `/vouchers/123` | `get(123)` |
| POST `/vouchers/123` | `/vouchers/123` | `post(123)` |
| POST `/vouchers` | `/vouchers` | `post()` |
| PUT `/vouchers/123` | `/vouchers/123` | `put(123)` |
| DELETE `/vouchers/123` | `/vouchers/123` | `delete(123)` |

### Custom Method Examples

Given a controller with methods like `activate()` and `postActivate()`:

| Request | URL Path | Method Called |
|---------|----------|---------------|
| GET `/vouchers/activate` | `/vouchers/activate` | `activate()` |
| POST `/vouchers/activate` | `/vouchers/activate` | `postActivate()` |

## HTTP Method Handling

The router automatically handles different HTTP methods and passes appropriate data:

### GET Requests
- Parameters from URL path are passed as arguments
- `$_GET` parameters are appended to method arguments

### POST/PUT/PATCH Requests
- Parameters from URL path are passed as arguments
- Request body is parsed and passed as the last argument
- For type-hinted methods, the body is automatically deserialized using JMS Serializer

### DELETE Requests
- Parameters from URL path are passed as arguments
- `$_GET` parameters are appended to method arguments

## Middleware Support

Controllers can define middleware using attributes:
- Class-level middleware applies to all methods
- Method-level middleware overrides class middleware

Middleware classes are instantiated using the dependency injection container (`$this->create`) instead of manual instantiation, ensuring that dependencies are properly injected into middleware classes, similar to how controllers are instantiated.

## Template Rendering

If a template exists for the method being called, the router will automatically render it:
- Templates are located in `templates/` directory
- The path structure follows the controller registration pattern

## Error Handling

If no matching method is found:
- Returns 404 error with "Route not found" message
- If CORS is enabled, OPTIONS requests are handled appropriately

## Authentication Integration

Controllers can use `#[RequireRole]` attributes to control access:
- Role-based access control is enforced before method execution
- Authentication is handled automatically through the framework

This controller mapping system provides a clean, predictable way to route requests to appropriate controller methods while maintaining flexibility for complex routing scenarios.
