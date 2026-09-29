# Controller mapping

\`Router::controller($path, $controller, ...$constructorArgs)\` binds a URL prefix to a controller instance or class name.

\`\`\`php
$router->controller('/products', App\ProductsController::class);
\`\`\`

When a class name is supplied, Router constructs it through Dice. Additional arguments passed to \`controller()\` are available during controller construction.

## Method resolution

For a controller bound to \`/products\`, Router resolves requests as follows.

### Base path

| Request | Method |
| --- | --- |
| \`GET /products\` | \`index()\` |
| \`POST /products\` | \`post()\` |
| \`PUT /products\` | \`put()\` |
| \`PATCH /products\` | \`patch()\` |
| \`DELETE /products\` | \`delete()\` |

### Path parameters

If the first path segment does not identify a custom controller method, it remains an argument to the HTTP-method handler:

| Request | Method |
| --- | --- |
| \`GET /products/42\` | \`get('42', ...)\` |
| \`POST /products/42\` | \`post('42', ...)\` |
| \`PUT /products/42\` | \`put('42', ...)\` |
| \`DELETE /products/42\` | \`delete('42', ...)\` |

Additional path segments are passed in order.

### Custom methods

For a non-empty first segment, Router checks:

1. HTTP-method-prefixed method: \`getSale()\`, \`postSale()\`, etc.
2. Unprefixed method: \`sale()\`.
3. The normal HTTP-method handler, keeping the segment as an argument.

For example:

| Request | Resolution order |
| --- | --- |
| \`GET /products/sale\` | \`getSale()\` → \`sale()\` → \`get('sale', ...)\` |
| \`POST /products/sale\` | \`postSale()\` → \`sale()\` → \`post('sale', ...)\` |

When a custom method is selected, the segment naming that method is removed from the argument list. Hyphens in custom path method names are converted to underscores.

## Request arguments

After URL parameters are resolved, Router appends request data.

### GET, DELETE, HEAD, OPTIONS, and other non-body methods

\`$_GET\` is appended as the final controller argument.

\`\`\`php
public function get(string $sku, array $query): Product
{
    // ...
}
\`\`\`

### POST, PUT, and PATCH

The request body is appended as the final argument.

An array-typed final parameter uses Router's Content-Type-aware body parser:

\`\`\`php
public function put(string $sku, array $body): Product
{
    // ...
}
\`\`\`

If the final parameter is a class and JMS Serializer is installed, Router automatically deserializes JSON into that class:

\`\`\`php
public function post(Product $product): Product
{
    // ...
}
\`\`\`

Class-typed automatic deserialization accepts \`application/json\` and \`application/*+json\`. Unsupported or missing media types return 415, and malformed JSON returns 400.

A controller may implement \`_deserialize(string $body)\` for non-class, non-array body handling.

## Middleware

Controller interception uses \`#[Objectiveweb\Router\Middleware]\` attributes. Legacy controller methods such as \`before()\` and \`beforePost()\` are not invoked in v3.

See [middleware documentation](../docs/middleware.md).

## Templates

If a controller returns an array, Router looks for a PHP template using the controller path and selected method.

The default template root is:

\`\`\`text
<composer project root>/templates
\`\`\`

Router tries the selected controller method template first and the HTTP-method template second. If no template exists, the array continues as a JSON-capable response.

When both a template and JSON representation are available, \`Accept\` negotiation chooses between \`text/html\` and \`application/json\`.

## Errors

If no controller method matches, Router raises a 404 response. Exceptions and PHP Errors raised while resolving or executing controllers and middleware are handled by the route Throwable boundary.
