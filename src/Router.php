<?php

namespace Objectiveweb;

use Objectiveweb\Router\CorsMiddleware;
use Objectiveweb\Router\Middleware;
use Objectiveweb\Router\Template;

class Router
{

    private static array $serializers = [];

    private \Dice\Dice $dice;
    private array $requestMiddlewares = [];
    private array $activeRequestMiddlewares = [];
    private bool $requestMiddlewaresStarted = false;
    private bool $requestMiddlewaresFinished = false;

    public function __construct(?string $_root = null, private array $config = [])
    {
        $this->dice = new \Dice\Dice();

        // By default, use the Composer root package (the application root).
        // Fall back to ../../../../ from vendor/objectiveweb/router/src.
        if (!$_root) {
            if (class_exists(\Composer\InstalledVersions::class)) {
                $rootPackage = \Composer\InstalledVersions::getRootPackage();
                $_root = $rootPackage['install_path'] ?? null;
            }

            $_root ??= dirname(dirname(dirname(dirname(__DIR__))));
        }

        $defaults = [
            'debug' => false,
            'request.middlewares' => [],
            'middlewares' => [],
            'trusted.proxies' => [],
            'trusted.hosts' => [],
            'template.root' => $_root . '/templates',
            'template.layout' => null,
        ];

        $this->config = array_merge($defaults, $config);

        if (!is_bool($this->config['debug'])) {
            throw new \InvalidArgumentException('debug must be a boolean');
        }

        if (!is_array($this->config['trusted.proxies'])) {
            throw new \InvalidArgumentException('trusted.proxies must be an array');
        }

        foreach ($this->config['trusted.proxies'] as $proxy) {
            if (!is_string($proxy) || !static::isValidProxyRange($proxy)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid trusted proxy address or CIDR: %s', is_scalar($proxy) ? (string) $proxy : get_debug_type($proxy))
                );
            }
        }

        if ($this->config['trusted.hosts'] !== '*' && !is_array($this->config['trusted.hosts'])) {
            throw new \InvalidArgumentException('trusted.hosts must be an array or "*"');
        }

        if (is_array($this->config['trusted.hosts'])) {
            foreach ($this->config['trusted.hosts'] as $host) {
                if (
                    !is_string($host)
                    || !static::isValidHostHeader($host)
                    || static::splitHostAndPort($host)[1] !== null
                ) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Invalid trusted host: %s',
                            is_scalar($host) ? (string) $host : get_debug_type($host)
                        )
                    );
                }
            }
        }

        foreach ($this->config['request.middlewares'] as $class => $args) {
            if (is_int($class)) {
                if (!is_string($args)) {
                    throw new \InvalidArgumentException(
                        'List-style request middleware definitions must be class names'
                    );
                }

                $this->addRequestMiddleware($args);
                continue;
            }

            $this->addRequestMiddleware($class, $args);
        }
    }

    public function setCors(string $origin): void
    {
        $this->requestMiddlewares = array_values(array_filter(
            $this->requestMiddlewares,
            static fn (array $definition): bool => $definition['class'] !== CorsMiddleware::class
        ));

        $this->addRequestMiddleware(CorsMiddleware::class, [$origin]);
    }

    public function addRequestMiddleware(string $class, array $args = []): void
    {
        $this->requestMiddlewares[] = [
            'class' => $class,
            'args' => $args,
        ];
    }

    private function startRequestMiddlewares(string $method, string $path): void
    {
        if ($this->requestMiddlewaresStarted) {
            return;
        }

        $this->requestMiddlewaresStarted = true;

        foreach ($this->requestMiddlewares as $definition) {
            $middleware = $this->create(
                $definition['class'],
                $definition['args']
            );

            if (method_exists($middleware, 'before')) {
                $middleware->before($method, $path);
            }

            $this->activeRequestMiddlewares[] = $middleware;
        }
    }

    private function finishRequestMiddlewares(
        string $method,
        string $path,
        mixed $response
    ): mixed {
        if ($this->requestMiddlewaresFinished) {
            return $response;
        }

        foreach (array_reverse($this->activeRequestMiddlewares) as $middleware) {
            if (method_exists($middleware, 'after')) {
                $response = $middleware->after($method, $path, $response);
            }
        }

        $this->requestMiddlewaresFinished = true;

        return $response;
    }

    public static function addSerializer(string $type, callable $callback): void
    {
        self::$serializers[$type] = $callback;
    }

    public static function hasSerializer(string $type): bool
    {
        return !empty(self::$serializers[$type]);
    }

    public function addRule(string $name, array $rule): void
    {
        $this->dice = $this->dice->addRule($name, $rule);
    }

    public function create(string $name, array $args = []): object
    {
        return $this->dice->create($name, $args);
    }

    /**
     * Return a new Template() object based on default root and optional layout
     *
     * @param $names
     * @param array|null $_data
     * @param string|null $layout
     * @return Template|null
     * @throws \Exception
     */
    public function template(string|array $names, ?array $_data = null, ?string $_layout = null): ?Template
    {
        $_root = $this->config["template.root"];
        $_layout = $_layout ?? $this->config["template.layout"];
        $_data = $_data ?? [];

        if (is_array($names)) {
            foreach ($names as $name) {
                if (is_readable($_root . DIRECTORY_SEPARATOR . $name . '.php')) {
                    return new Template($_root, $name, $_data, $_layout, $this);
                }
            }
        } else {
            if (is_readable($_root . DIRECTORY_SEPARATOR . $names . '.php')) {
                return new Template($_root, $names, $_data, $_layout, $this);
            }
        }

        return null;
    }

    /**
     * Route a particular request to a callback
     *
     *
     * @param $request - HTTP Request Method + Request-URI Regex e.g. "GET /something/([0-9]+)/?"
     * @param $callback - A valid callback. Regex capture groups are passed as arguments to this function, using
     *   array('Namespace\ClassNameAsString', 'method') triggers the dependency injector to instantiate the given class
     * If the callback returns a value, Router sends it through the response pipeline.
     * @throws \Exception
     */
    public function route(
        string $request,
        mixed $callback,
        mixed ...$args
    ): void {
        $this->dispatchRoute(
            $request,
            $callback,
            $args
        );
    }

    /**
     * Match and execute a route through the common callback/error boundary.
     *
     * $argumentFactory is used by HTTP verb helpers so request-derived
     * arguments such as query parameters and decoded bodies are created only
     * after the route matches and inside the Throwable boundary.
     */
    private function dispatchRoute(
        string $request,
        $callback,
        array $extraArgs = [],
        ?callable $argumentFactory = null
    ): void {
        // support PATH_INFO when using mod_rewrite
        if (empty($_SERVER['REDIRECT_URL'])) {
            $_SERVER['REDIRECT_URL'] = preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']);
        }

        $p = sprintf('/%s(\/%s)?(.*)/',
            str_replace('/', '\/', dirname($_SERVER['SCRIPT_NAME'])),
            str_replace('.', '\.', basename($_SERVER['SCRIPT_NAME']))
        );

        if (empty($_SERVER['PATH_INFO']) && preg_match($p, $_SERVER['REDIRECT_URL'], $m)) {
            $_SERVER['PATH_INFO'] = (empty($m[2]) || $m[2][0] != '/') ? '/' . $m[2] : $m[2];
        }

        $method = (string) $_SERVER['REQUEST_METHOD'];
        $path = (string) $_SERVER['PATH_INFO'];

        // Route execution is the HTTP error boundary. Request middleware,
        // route matching, callback resolution, dependency injection, request
        // argument preparation, invocation and response preparation can all
        // raise PHP Errors or Exceptions.
        try {
            // Request middleware is global to the incoming request. before()
            // runs once, before route matching begins.
            $this->startRequestMiddlewares($method, $path);

            if (!preg_match(
                sprintf("/^%s$/", str_replace('/', '\/', $request)),
                "$method $path",
                $params
            )) {
                return;
            }

            array_shift($params);

            // A [ClassName::class, 'method'] callback is resolved through Dice.
            if (is_array($callback) && is_string($callback[0])) {
                $callback[0] = $this->create($callback[0], $params);
            }

            if (!is_callable($callback)) {
                $callbackType = is_string($callback) ? $callback : get_debug_type($callback);
                throw new \RuntimeException(
                    sprintf(_('%s: Invalid callback'), $callbackType),
                    500
                );
            }

            if ($argumentFactory !== null) {
                $resolvedArgs = $argumentFactory();
                if (!is_array($resolvedArgs)) {
                    throw new \UnexpectedValueException(
                        'Route argument factory must return an array',
                        500
                    );
                }

                $extraArgs = array_merge($extraArgs, $resolvedArgs);
            }

            $params = array_merge($params, $extraArgs);

            $response = call_user_func_array($callback, $params);

            if ($response !== NULL) {
                $response = $this->finishRequestMiddlewares($method, $path, $response);
                static::respond($response, 200, $this->config['debug']);
            }
        } catch (\Throwable $ex) {
            $status = (int) $ex->getCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            if ($status >= 500) {
                error_log(get_class($ex) . ' ' . $ex->getMessage() . " @ " . $ex->getTraceAsString());
            }

            static::respond($ex, $status, $this->config['debug']);
        }
    }

    /**
     * Binds a controller to HTTP-method or custom action methods.
     * @param $path String path prefix (/path)
     * @param $controller mixed class name or class
     * @param ... mixed passed to controller instantiation
     * @throws \Exception
     */
    public function controller(
        string $path,
        object|string $controller,
        mixed ...$args
    ): void {
        $re = $path === '/'
            ? '([A-Z]+) /(.*)'
            : sprintf("([A-Z]+) %s(?:$|/)(.*)", rtrim($path, '/'));
        $this->route($re, function ($method, $params) use ($re, $path, $controller, $args) {
            if (func_num_args() > 2) {
                $callback_args = func_get_args();
                array_splice($callback_args, 0, 1);

                $params = array_pop($callback_args);
                $args = [...$args, ...$callback_args];
            }

            if (is_string($controller)) {
                $controller = $this->create($controller, $args);
            }

            $requestMethod = strtolower($method);
            $method = $requestMethod === 'head' ? 'get' : $requestMethod;

            // HEAD uses GET controller resolution while middleware still sees
            // the actual request method.

            // url parameters
            $params = explode("/", $params);

            // A GET $path/1/2/3/4 request will be parsed into
            // $method = GET
            // $params = [ 1, 2, 3, 4 ]

            // function that will be called (initially the http request method)
            $fn = $method;
            // check if there's a specific method to handle this request
            if (!empty($params[0])) {

                // Try to execute controller.[post|get|put|delete]Name()
                if (is_callable(array($controller, $_fn = str_replace('-', '_', $method . ucfirst($params[0]))))) {
                    array_shift($params);
                    $fn = $_fn;

                } // Try to execute controller.name()
                elseif (is_callable(array($controller, $_fn = str_replace('-', '_', $params[0])))) {
                    array_shift($params);
                    $fn = $_fn;
                }
                // Otherwise the params should not be shifted (i.e. GET /2)
            } else {
                // no url parameters, remove the first item (it is empty)
                array_shift($params);

                // If we're GETting /, handle it using the index() method
                $fn = ($method == 'get' ? 'index' : $method);
            }

            if (!is_callable(array($controller, $fn))) {
                throw new \Exception(sprintf(_("%s\\%s: Route not found"), get_class($controller), $fn), 404);
            }

            $refClass = new \ReflectionClass($controller);
            $refMethod = new \ReflectionMethod($controller, $fn);

            switch ($method) {
                // append the decoded body to the argument list for (post|put|patch).* methods
                case "post":
                case "put":
                case "patch":
                    $rparams = $refMethod->getParameters();
                    $fn_param = array_pop($rparams);
                    $fnType = $fn_param?->getType();
                    $fnClass = $fnType instanceof \ReflectionNamedType && !$fnType->isBuiltin()
                        ? $fnType->getName()
                        : null;
                    $fnIsArray = $fnType instanceof \ReflectionNamedType
                        && $fnType->isBuiltin()
                        && $fnType->getName() === 'array';

                    // Auto-deserialize class-typed bodies only when the request
                    // explicitly declares a JSON media type.
                    if ($fnClass && class_exists('\JMS\Serializer\SerializerBuilder')) {
                        $contentType = static::requestContentType();
                        if (!static::isJsonContentType($contentType)) {
                            throw new \RuntimeException(
                                sprintf(
                                    'Unsupported Content-Type "%s"; expected application/json',
                                    $contentType ?: '(missing)'
                                ),
                                415
                            );
                        }

                        $body = Router::parse_post_body(false);
                        static::decodeJsonBody($body);

                        $serializer = \JMS\Serializer\SerializerBuilder::create()->build();
                        $params[] = $serializer->deserialize($body, $fnClass, 'json');
                    } // hinting as array allows overriding _deserialize
                    elseif ($fnIsArray) {
                        $params[] = Router::parse_post_body();
                    } // use _deserialize as the default parser for non-type-hinted methods
                    elseif (is_callable(array($controller, '_deserialize'))) {
                        $params[] = $controller->_deserialize(Router::parse_post_body(false));
                    } // use default body parser
                    else {
                        $params[] = Router::parse_post_body();
                    }

                    break;
                default:
                    $params[] = $_GET;
                    break;
            }

            // Build middleware definitions while preserving repeated attributes.
            // A narrower scope replaces broader middleware of the same class:
            // defaults < class attributes < method attributes.
            $middlewareDefinitions = [];
            foreach ($this->config['middlewares'] as $mwClass => $mwArgs) {
                $middlewareDefinitions[] = [
                    'class' => $mwClass,
                    'args' => $mwArgs,
                ];
            }

            $classAttributes = $refClass->getAttributes(Middleware::class, \ReflectionAttribute::IS_INSTANCEOF);
            $classMiddlewareClasses = [];
            foreach ($classAttributes as $attr) {
                /** @var Middleware $definition */
                $definition = $attr->newInstance();
                $classMiddlewareClasses[$definition->getClass()] = true;
            }

            if ($classMiddlewareClasses) {
                $middlewareDefinitions = array_values(array_filter(
                    $middlewareDefinitions,
                    static fn (array $definition): bool => !isset($classMiddlewareClasses[$definition['class']])
                ));
            }

            foreach ($classAttributes as $attr) {
                $definition = $attr->newInstance();
                $middlewareDefinitions[] = [
                    'class' => $definition->getClass(),
                    'args' => $definition->getArgs(),
                ];
            }

            $methodAttributes = $refMethod->getAttributes(Middleware::class, \ReflectionAttribute::IS_INSTANCEOF);
            $methodMiddlewareClasses = [];
            foreach ($methodAttributes as $attr) {
                /** @var Middleware $definition */
                $definition = $attr->newInstance();
                $methodMiddlewareClasses[$definition->getClass()] = true;
            }

            if ($methodMiddlewareClasses) {
                $middlewareDefinitions = array_values(array_filter(
                    $middlewareDefinitions,
                    static fn (array $definition): bool => !isset($methodMiddlewareClasses[$definition['class']])
                ));
            }

            foreach ($methodAttributes as $attr) {
                $definition = $attr->newInstance();
                $middlewareDefinitions[] = [
                    'class' => $definition->getClass(),
                    'args' => $definition->getArgs(),
                ];
            }

            // Instantiate and execute before() hooks in declaration order.
            $middlewares = [];
            foreach ($middlewareDefinitions as $definition) {
                $mw = $this->create(
                    $definition['class'],
                    $definition['args']
                );

                if (method_exists($mw, 'before')) {
                    $updatedParams = call_user_func([$mw, 'before'], $requestMethod, $fn, $params);
                    if (!is_array($updatedParams)) {
                        throw new \UnexpectedValueException(sprintf(
                            '%s::before() must return an array',
                            get_class($mw)
                        ), 500);
                    }

                    $params = $updatedParams;
                }

                $middlewares[] = $mw;
            }

            // we're testing this here in case Middlewares end the request prematurely
            // (needed for OPTIONS in CORS)
            if (!is_callable([$controller, $fn])) {
                throw new \Exception(sprintf(_("%s\\%s: Route not found"), get_class($controller), $fn), 404);
            }

            $response = call_user_func_array([$controller, $fn], $params);

            // Execute implemented after() hooks in reverse order.
            foreach (array_reverse($middlewares) as $mw) {
                if (method_exists($mw, 'after')) {
                    $response = call_user_func([$mw, 'after'], $requestMethod, $fn, $params, $response);
                }
            }

            // Templates receive arrays as their data context. Other response types
            // are already complete response values and should be handled by respond().
            if (!is_array($response)) {
                return $response;
            }

            // Check if there's a template available for this method
            $_SCRIPT_DIR = dirname($_SERVER['SCRIPT_NAME']);
            $_SCRIPT_NAME = basename($_SERVER['SCRIPT_NAME'], '.php');

            $template_path = sprintf("%s%s%s",
                $_SCRIPT_DIR == '/' ? '' : $_SCRIPT_DIR,
                $_SCRIPT_NAME == 'index' ? '' : '/' . $_SCRIPT_NAME,
                $path != '/' ? $path . '/' : $path
            );

            $templates = array_unique(["$template_path$fn", "$template_path$method"]);
            $template = $this->template($templates, $response);

            if (!$template) {
                return $response;
            }

            // A controller array with a matching template has both HTML and
            // JSON representations. Missing Accept behaves like */* and HTML
            // wins ties for browser/controller routes.
            header('Vary: Accept', false);

            return static::negotiateContentType(
                ['text/html', 'application/json'],
                $_SERVER['HTTP_ACCEPT'] ?? null
            ) === 'text/html'
                ? $template
                : $response;
        });
    }

    /**
     * Matches a DELETE request,
     * Callback is called with regex matches + $_GET arguments
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., $_GET)
     * @throws \Exception
     */
    public function DELETE(string $path, mixed $callback): void
    {
        $this->dispatchRoute(
            "DELETE $path",
            $callback,
            argumentFactory: static fn (): array => [$_GET]
        );
    }

    /**
     * Matches a GET request,
     * Callback is called with regex matches + $_GET arguments
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., $_GET)
     * @throws \Exception
     */
    public function GET(string $path, mixed $callback): void
    {
        $this->dispatchRoute(
            "(?:GET|HEAD) $path",
            $callback,
            argumentFactory: static fn (): array => [$_GET]
        );
    }

    /**
     * Matches a POST request,
     * Callback is called with regex matches + decoded post body
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., <$post_body>)
     * @throws \Exception
     */
    public function POST(string $path, mixed $callback): void
    {
        $this->dispatchRoute(
            "POST $path",
            $callback,
            argumentFactory: static fn (): array => [Router::parse_post_body()]
        );
    }

    /**
     * Matches a PUT request,
     * Callback is called with regex matches + decoded post body
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., <$post_body>)
     * @throws \Exception
     */
    public function PUT(string $path, mixed $callback): void
    {
        $this->dispatchRoute(
            "PUT $path",
            $callback,
            argumentFactory: static fn (): array => [Router::parse_post_body()]
        );
    }

    /**
     * Matches a PATCH request,
     * Callback is called with regex matches + decoded request body
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., <$request_body>)
     * @throws \Exception
     */
    public function PATCH(string $path, mixed $callback): void
    {
        $this->dispatchRoute(
            "PATCH $path",
            $callback,
            argumentFactory: static fn (): array => [Router::parse_post_body()]
        );
    }

    /**
     * Bootstraps an endpoint based on $namespace
     */
    public function run(string $namespace): void
    {

        $router = $this;

        $this->route("([A-Z]+) /(.*)", function ($method, $path) use ($router, $namespace) {

            if (!empty($path)) {
                $path = explode("/", $path);
                $class = "$namespace\\" . ucfirst($path[0]) . "Controller";

                if (class_exists($class)) {
                    $router->controller("/{$path[0]}", $class);
                }
            }

            $router->controller("/", "$namespace\\HomeController");
        });
    }

    /**
     * Construct a URL for the current request or for a path relative to the
     * current script.
     *
     * NULL, an empty string, and "self" return the absolute current URL,
     * including the request scheme, host, non-default port, and script URL.
     * Other values are appended to the current script directory. When both
     * SCRIPT_URL and PATH_INFO are available, PATH_INFO is removed from
     * SCRIPT_URL so generated paths remain anchored to the front controller.
     */
    public function url(?string $str = null): string
    {
        if ($str === 'self' || $str === null || $str === '') {
            $trustedProxy = $this->isTrustedProxy($_SERVER['REMOTE_ADDR'] ?? '');

            $protocol = $this->requestProtocol();
            $hostHeader = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $port = isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : null;

            $directHost = $_SERVER['HTTP_HOST'] ?? null;
            if (is_string($directHost) && $this->isTrustedHost($directHost)) {
                $hostHeader = $directHost;
            }

            $forwardedProto = null;
            $forwardedHost = null;
            $forwardedPort = null;

            if ($trustedProxy) {
                $candidateProto = static::forwardedHeader('HTTP_X_FORWARDED_PROTO');
                if (
                    $candidateProto !== null
                    && in_array(strtolower($candidateProto), ['http', 'https'], true)
                ) {
                    $forwardedProto = strtolower($candidateProto);
                    $protocol = $forwardedProto;
                }

                $candidateHost = static::forwardedHeader('HTTP_X_FORWARDED_HOST');
                if ($candidateHost !== null && static::isValidHostHeader($candidateHost)) {
                    $forwardedHost = $candidateHost;
                    $hostHeader = $forwardedHost;
                }

                $candidatePort = static::forwardedHeader('HTTP_X_FORWARDED_PORT');
                if ($candidatePort !== null && ctype_digit($candidatePort)) {
                    $candidatePortNumber = (int) $candidatePort;
                    if ($candidatePortNumber >= 1 && $candidatePortNumber <= 65535) {
                        $forwardedPort = $candidatePortNumber;
                    }
                }
            }

            [$host, $hostPort] = static::splitHostAndPort($hostHeader);

            if ($forwardedPort !== null) {
                $port = $forwardedPort;
            } elseif ($forwardedHost !== null && $hostPort !== null) {
                $port = $hostPort;
            } elseif ($forwardedProto !== null) {
                // A proxy that supplies the external scheme but no explicit
                // external port is assumed to use that scheme's default port.
                $port = $protocol === 'https' ? 443 : 80;
            } elseif ($hostPort !== null) {
                $port = $hostPort;
            }

            $url = $protocol . '://' . $host;

            if (
                $port !== null
                && !(($protocol === 'http' && $port === 80) || ($protocol === 'https' && $port === 443))
            ) {
                $url .= ':' . $port;
            }

            $url .= !empty($_SERVER['SCRIPT_URL'])
                ? $_SERVER['SCRIPT_URL']
                : ($_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '/');

            return $url;
        }

        if (!empty($_SERVER['PATH_INFO'])) {
            if (!empty($_SERVER['SCRIPT_URL'])) {
                $path = substr($_SERVER['SCRIPT_URL'], 0, -1 * strlen($_SERVER['PATH_INFO']));
            } else {
                $path = dirname($_SERVER['SCRIPT_NAME']);
            }
        } else {
            $path = dirname($_SERVER['SCRIPT_NAME']);
        }

        return ($path === '/' ? '' : $path) . ($str[0] === '/' ? $str : '/' . $str);
    }

    private function isTrustedHost(string $hostHeader): bool
    {
        if (!static::isValidHostHeader($hostHeader)) {
            return false;
        }

        if ($this->config['trusted.hosts'] === '*') {
            return true;
        }

        [$host] = static::splitHostAndPort($hostHeader);
        $host = static::normalizeHostForComparison($host);

        foreach ($this->config['trusted.hosts'] as $trustedHost) {
            [$candidate] = static::splitHostAndPort($trustedHost);

            if ($host === static::normalizeHostForComparison($candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHostForComparison(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $packed = @inet_pton($host);
        if ($packed !== false) {
            return bin2hex($packed);
        }

        return strtolower(rtrim($host, '.'));
    }

    private function requestProtocol(): string
    {
        return isset($_SERVER['HTTPS'])
            && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1' || $_SERVER['HTTPS'] === 1)
            ? 'https'
            : 'http';
    }

    private function isTrustedProxy(string $remoteAddress): bool
    {
        if ($remoteAddress === '') {
            return false;
        }

        foreach ($this->config['trusted.proxies'] as $trustedProxy) {
            if (static::addressMatchesRange($remoteAddress, $trustedProxy)) {
                return true;
            }
        }

        return false;
    }

    private static function isValidProxyRange(string $range): bool
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $packed = @inet_pton($network);
        if ($packed === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        if ($prefix === '' || !ctype_digit($prefix)) {
            return false;
        }

        $bits = strlen($packed) * 8;

        return (int) $prefix >= 0 && (int) $prefix <= $bits;
    }

    private static function addressMatchesRange(string $address, string $range): bool
    {
        $packedAddress = @inet_pton($address);
        if ($packedAddress === false) {
            return false;
        }

        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $packedNetwork = @inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
            return false;
        }

        if ($prefix === null) {
            return $packedAddress === $packedNetwork;
        }

        $prefixBits = (int) $prefix;
        $wholeBytes = intdiv($prefixBits, 8);
        $remainingBits = $prefixBits % 8;

        if (
            $wholeBytes > 0
            && substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)
        ) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($packedAddress[$wholeBytes]) & $mask)
            === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    private static function forwardedHeader(string $name): ?string
    {
        if (!isset($_SERVER[$name])) {
            return null;
        }

        $value = trim((string) $_SERVER[$name]);

        // Objectiveweb Router intentionally does not interpret proxy chains.
        // The trusted proxy must replace forwarded headers with one authoritative value.
        if (
            $value === ''
            || str_contains($value, ',')
            || preg_match('/[\r\n]/', $value)
        ) {
            return null;
        }

        return $value;
    }

    private static function isValidHostHeader(string $host): bool
    {
        if (
            $host === ''
            || str_contains($host, ',')
            || preg_match('/[\s\x00-\x1f\x7f\/\\@?#]/', $host)
        ) {
            return false;
        }

        [$hostname] = static::splitHostAndPort($host);

        if (str_starts_with($hostname, '[') && str_ends_with($hostname, ']')) {
            return @inet_pton(substr($hostname, 1, -1)) !== false;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $hostname) === 1;
    }

    /**
     * @return array{0:string,1:?int}
     */
    private static function splitHostAndPort(string $hostHeader): array
    {
        $hostHeader = trim($hostHeader);

        // Bracketed IPv6 literal, optionally followed by a port.
        if (str_starts_with($hostHeader, '[')) {
            if (preg_match('/^(\[[0-9a-fA-F:.]+\])(?::([0-9]+))?$/', $hostHeader, $matches)) {
                return [
                    $matches[1],
                    isset($matches[2])
                        && (int) $matches[2] >= 1
                        && (int) $matches[2] <= 65535
                            ? (int) $matches[2]
                            : null,
                ];
            }

            return [$hostHeader, null];
        }

        if (substr_count($hostHeader, ':') === 1) {
            [$host, $port] = explode(':', $hostHeader, 2);
            if ($port !== '' && ctype_digit($port)) {
                $portNumber = (int) $port;
                if ($portNumber >= 1 && $portNumber <= 65535) {
                    return [$host, $portNumber];
                }
            }
        }

        return [$hostHeader, null];
    }

    private static function requestContentType(): string
    {
        return strtolower(trim(explode(
            ';',
            $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''
        )[0]));
    }

    private static function isJsonContentType(string $contentType): bool
    {
        return $contentType === 'application/json'
            || str_ends_with($contentType, '+json');
    }

    private static function decodeJsonBody(string $body, bool $asArray = true): mixed
    {
        try {
            return json_decode($body, $asArray, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $ex) {
            throw new \RuntimeException('Invalid JSON request body', 400, $ex);
        }
    }

    public static function parse_post_body(bool $decoded = true, bool $as_array = true): mixed
    {
        $contentType = static::requestContentType();

        // $_POST is normally an array populated by PHP for form requests.
        // Keeping string support is useful for tests and callers that inject a raw body.
        $postBody = is_string($_POST)
            ? $_POST
            : file_get_contents('php://input');

        if (!$decoded) {
            return $postBody;
        }

        if (static::isJsonContentType($contentType)) {
            return static::decodeJsonBody($postBody, $as_array);
        }

        if ($contentType === 'application/x-www-form-urlencoded') {
            if (is_array($_POST) && !empty($_POST)) {
                return $_POST;
            }

            parse_str($postBody, $data);

            return $data;
        }

        if ($contentType === 'multipart/form-data') {
            return is_array($_POST) ? $_POST : [];
        }

        // When Content-Type is missing or unsupported, do not guess from the
        // body contents. Return PHP-parsed form data when available, otherwise
        // preserve the raw body.
        if (is_array($_POST) && !empty($_POST)) {
            return $_POST;
        }

        return $postBody;
    }

    /**
     * Emits a `Location` header pointing to $to
     * @param $to string The URL to redirect to
     * @param int $code 3xx redirect code, from the HTTP spec, 301 is the default
     *                  300 Multiple Choices
     *                  301 Moved Permanently - This and all future requests should be directed to the given URI.
     *                  302 Found - Previously "Moved temporarily" - has been superseded by 303 and 307
     *                  303 See Other - The response to the request can be found under another URI using the GET method.
     *                  304 Not Modified - Indicates that the resource has not been modified since the version specified by the request headers
     *                  305 Use Proxy - The requested resource is available only through a proxy, the address for which is provided in the response.
     *                  306 Switch Proxy - No longer used. Originally meant "Subsequent requests should use the specified proxy."
     *                  307 Temporary Redirect - In this case, the request should be repeated with another URI; however, future requests should still use the original URI.
     *                  308 Permanent Redirect - The request and all future requests should be repeated using another URI.
     */
    public function redirect(string $to, int $code = 301): never
    {
        header("HTTP/1.1 $code");

        if (preg_match('#^https?://#', $to)) {
            header("Location: $to");
        } else {
            header('Location: ' . $this->url($to));
        }

        exit();
    }

    /**
     * Choose the best representation from the server-supported content types.
     *
     * Missing Accept behaves like the wildcard media range. More specific ranges override
     * wildcards, including q=0 exclusions. Ties are resolved by the order of
     * $available so callers can express a server preference.
     */
    public static function negotiateContentType(array $available, ?string $accept = null): ?string
    {
        $accept = trim((string) $accept);
        if ($accept === '') {
            $accept = '*/*';
        }

        $ranges = [];
        foreach (explode(',', $accept) as $entry) {
            $parts = array_map('trim', explode(';', $entry));
            $mediaRange = strtolower((string) array_shift($parts));

            if (!str_contains($mediaRange, '/')) {
                continue;
            }

            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\\s*=\\s*([0-9.]+)$/i', $parameter, $match)) {
                    $quality = max(0.0, min(1.0, (float) $match[1]));
                }
            }

            [$type, $subtype] = explode('/', $mediaRange, 2);
            $ranges[] = [
                'type' => $type,
                'subtype' => $subtype,
                'q' => $quality,
            ];
        }

        $selected = null;
        $selectedQuality = -1.0;

        foreach ($available as $contentType) {
            [$type, $subtype] = explode('/', strtolower($contentType), 2);

            $bestSpecificity = -1;
            $quality = 0.0;

            foreach ($ranges as $range) {
                if ($range['type'] !== '*' && $range['type'] !== $type) {
                    continue;
                }

                if ($range['subtype'] !== '*' && $range['subtype'] !== $subtype) {
                    continue;
                }

                $specificity = $range['type'] === '*'
                    ? 0
                    : ($range['subtype'] === '*' ? 1 : 2);

                if ($specificity > $bestSpecificity) {
                    $bestSpecificity = $specificity;
                    $quality = $range['q'];
                } elseif ($specificity === $bestSpecificity) {
                    $quality = max($quality, $range['q']);
                }
            }

            if ($bestSpecificity >= 0 && $quality > 0 && $quality > $selectedQuality) {
                $selected = $contentType;
                $selectedQuality = $quality;
            }
        }

        return $selected;
    }

    /**
     * Prepare a response body without emitting headers or terminating execution.
     *
     * @return array{body:string, content_type:?string, vary_accept:bool}
     */
    protected static function prepareResponse(
        mixed $content,
        ?string $accept = null,
        ?int $status = null,
        bool $debug = false
    ): array
    {
        if ($content instanceof \Throwable && !self::hasSerializer(get_class($content))) {
            $contentType = static::negotiateContentType(
                ['text/html', 'application/json'],
                $accept
            );

            if ($contentType === null) {
                return [
                    'body' => '',
                    'content_type' => null,
                    'vary_accept' => true,
                ];
            }

            $redact = !$debug && $status !== null && $status >= 500;

            if ($contentType === 'text/html') {
                if ($redact) {
                    return [
                        'body' => '<!doctype html><html><body><h1>Internal Server Error</h1></body></html>',
                        'content_type' => 'text/html',
                        'vary_accept' => true,
                    ];
                }

                $exception = htmlspecialchars(
                    get_class($content),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
                $message = htmlspecialchars(
                    $content->getMessage(),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );

                return [
                    'body' => sprintf(
                        '<!doctype html><html><body><h1>%s</h1><p>%s</p></body></html>',
                        $exception,
                        $message
                    ),
                    'content_type' => 'text/html',
                    'vary_accept' => true,
                ];
            }

            if ($redact) {
                return [
                    'body' => static::serializeJson([
                        'message' => 'Internal Server Error',
                    ]),
                    'content_type' => 'application/json',
                    'vary_accept' => true,
                ];
            }

            return [
                'body' => static::serializeJson([
                    'exception' => get_class($content),
                    'message' => $content->getMessage(),
                ]),
                'content_type' => 'application/json',
                'vary_accept' => true,
            ];
        }

        $obj = null;
        if (is_array($content) && !empty($content[0]) && is_object($content[0])) {
            $obj = $content[0];
        } elseif (is_object($content)) {
            $obj = $content;
        }

        $isTemplate = $content instanceof Template;
        $isRenderable = is_object($content) && is_callable([$content, 'render']);

        if ($isTemplate) {
            $available = ['text/html'];
        } elseif (is_string($content) || $isRenderable) {
            $available = ['text/html', 'application/json'];
        } else {
            $available = ['application/json'];
        }

        $contentType = static::negotiateContentType($available, $accept);
        $varyAccept = count($available) > 1;

        if ($contentType === null) {
            return [
                'body' => '',
                'content_type' => null,
                'vary_accept' => $varyAccept,
            ];
        }

        if ($contentType === 'text/html') {
            $body = $isRenderable ? call_user_func([$content, 'render']) : $content;

            // A render() method may return structured data. That result is JSON,
            // not HTML, and must itself be acceptable to the client.
            if (!is_string($body)) {
                if (static::negotiateContentType(['application/json'], $accept) === null) {
                    return [
                        'body' => '',
                        'content_type' => null,
                        'vary_accept' => true,
                    ];
                }

                return [
                    'body' => static::serializeJson($body),
                    'content_type' => 'application/json',
                    'vary_accept' => true,
                ];
            }

            return [
                'body' => $body,
                'content_type' => 'text/html',
                'vary_accept' => $varyAccept,
            ];
        }

        return [
            'body' => static::serializeJson($content, $obj),
            'content_type' => 'application/json',
            'vary_accept' => $varyAccept,
        ];
    }

    private static function serializeJson(mixed $content, ?object $obj = null): string
    {
        if ($obj && self::hasSerializer(get_class($obj))) {
            $content = self::$serializers[get_class($obj)]($content);

            return is_string($content)
                ? $content
                : json_encode($content, JSON_THROW_ON_ERROR);
        }

        if ($obj && class_exists('\\JMS\\Serializer\\SerializerBuilder')) {
            $serializer = \JMS\Serializer\SerializerBuilder::create()->build();

            return $serializer->serialize(
                $content,
                'json',
                \JMS\Serializer\SerializationContext::create()->enableMaxDepthChecks()
            );
        }

        return json_encode($content, JSON_THROW_ON_ERROR);
    }

    /**
     * Prepare the final HTTP response plan, including the status selected after
     * representation negotiation.
     *
     * @return array{status:int, body:string, content_type:?string, vary_accept:bool}
     */
    protected static function prepareHttpResponse(
        mixed $content,
        int $code = 200,
        ?string $accept = null,
        ?string $requestMethod = null,
        bool $debug = false
    ): array {
        // Informational responses, 204, 205 and 304 never carry a message body
        // and do not require representation negotiation.
        if (
            ($code >= 100 && $code < 200)
            || $code === 204
            || $code === 205
            || $code === 304
        ) {
            return [
                'status' => $code,
                'body' => '',
                'content_type' => null,
                'vary_accept' => false,
            ];
        }

        $response = static::prepareResponse($content, $accept, $code, $debug);
        $status = $response['content_type'] === null ? 406 : $code;

        // HEAD selects the same representation as GET but never emits its body.
        if (strcasecmp((string) $requestMethod, 'HEAD') === 0) {
            $response['body'] = '';
        }

        return [
            'status' => $status,
            ...$response,
        ];
    }

    public static function respond(
        mixed $content,
        int $code = 200,
        bool $debug = false
    ) {
        $response = static::prepareHttpResponse(
            $content,
            $code,
            $_SERVER['HTTP_ACCEPT'] ?? null,
            $_SERVER['REQUEST_METHOD'] ?? null,
            $debug
        );

        if ($response['vary_accept']) {
            header('Vary: Accept', false);
        }

        header("HTTP/1.1 {$response['status']}");

        if ($response['content_type'] === null) {
            exit('');
        }

        header('Content-Type: ' . $response['content_type'] . '; charset=utf-8');

        exit($response['body']);
    }

    public static function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
