<?php

namespace Objectiveweb;

use JMS\Serializer\SerializationContext;
use Objectiveweb\Router\Middleware;
use Objectiveweb\Router\Template;

class Router
{

    private static $serializers = [];

    private $cors = null;
    private \Dice\Dice $dice;

    function __construct(?string $_root = null, private array $config = [])
    {
        $this->dice = new \Dice\Dice();

        // By default, set root to the project root (../../.. from vendor/ow/router)
        if (!$_root) {
            $_root = dirname(dirname(dirname(__DIR__)));
        }

        $defaults = [
            'middlewares' => [],
            'template.root' => $_root . '/templates',
            'template.layout' => null,
        ];

        $this->config = array_merge($defaults, $config);
    }

    function setCors($cors)
    {
        $this->cors = $cors;
    }

    static function addSerializer($type, $callback)
    {
        self::$serializers[$type] = $callback;
    }

    static function hasSerializer($type)
    {
        return !empty(self::$serializers[$type]);
    }

    public function addRule($name, array $rule): void
    {
        $this->dice = $this->dice->addRule($name, $rule);
    }

    public function create(string $name, array $args = [], array $share = [])
    {
        return $this->dice->create($name, $args, $share);
    }

    /**
     * Return a new Template() object based on default root and optional layout
     *
     * @param $names
     * @param array $_data
     * @param string|null $layout
     * @return Template|null
     * @throws \Exception
     */
    public function template($names, array $_data, string|null $_layout = null): Template|null
    {
        $_root = $this->config["template.root"];
        $_layout = $_layout ?? $this->config["template.layout"];

        if (is_array($names)) {
            foreach ($names as $name) {
                if (is_readable($_root . DIRECTORY_SEPARATOR . $name . '.php')) {
                    return new Template($_root, $name, $_data, $_layout);
                }
            }
        } else {
            if (is_readable($_root . DIRECTORY_SEPARATOR . $names . '.php')) {
                return new Template($_root, $names, $_data, $_layout);
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
     * @return void or data - If the callback returns something, it's responded accordingly, otherwise, nothing happens
     * @throws \Exception
     */
    public function route($request, $callback)
    {

        // support PATH_INFO when using mod_rewrite
        if (empty($_SERVER['REDIRECT_URL'])) {
            $_SERVER['REDIRECT_URL'] = preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']);
        }

        $p = sprintf('/%s(\\/%s)?(.*)/',
            str_replace('/', '\\/', dirname($_SERVER['SCRIPT_NAME'])),
            str_replace('.', '\\.', basename($_SERVER['SCRIPT_NAME']))
        );

        if (empty($_SERVER['PATH_INFO']) && preg_match($p, $_SERVER['REDIRECT_URL'], $m)) {
            $_SERVER['PATH_INFO'] = (empty($m[2]) || $m[2][0] != '/') ? '/' . $m[2] : $m[2];
        }

        if (preg_match(sprintf("/^%s$/", str_replace('/', '\/', $request)), "{$_SERVER['REQUEST_METHOD']} {$_SERVER['PATH_INFO']}", $params)) {

            array_shift($params);

            // route expects a callable
            // this can be:
            //  - a function()
            //  - [ $instance, 'method' ]
            //  - [ 'Classname', 'method' ]

            // For the third case, we need to instantiate the class
            if (is_array($callback) && is_string($callback[0])) {
                $callback[0] = $this->create($callback[0], $params);
            }

            if (!is_callable($callback)) {
                throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
            }

            if (func_num_args() > 2) {
                $params = array_merge($params, array_slice(func_get_args(), 2));
            }

            try {
                $response = call_user_func_array($callback, $params);
                if ($response !== NULL) {
                    if (is_object($response) && self::hasSerializer(get_class($response))) {
                        self::$serializers[get_class($response)]($response);
                    } else {
                        self::respond($response);
                    }
                }
            } catch (\Exception $ex) {
                if (!empty(self::$serializers[get_class($ex)])) {
                    self::$serializers[get_class($ex)]($ex);
                } else {
                    if ($ex->getCode() >= 500) {
                        error_log(get_class($ex) . ' ' . $ex->getMessage() . " @ " . $ex->getTraceAsString());
                    }
                    self::respond(['exception' => get_class($ex), 'message' => $ex->getMessage()], $ex->getCode());
                }
            }
        }
    }

    /**
     * Runs $callable with arguments if it's callable, otherwise, does nothing
     * @param $callable
     * @return mixed|null
     */
    private function _call($callable)
    {
        if (is_callable($callable)) {
            $args = func_get_args();
            array_shift($args);
            return call_user_func_array($callable, $args);
        }

        return null;
    }

    /**
     * Binds a controller get/post/put/destroy or custom functions to HTTP methods
     * @param $path String path prefix (/path)
     * @param $controller mixed class name or class
     * @param ... mixed passed to controller instantiation
     * @throws \Exception
     */
    public function controller($path, $controller)
    {
        $args = func_get_args();
        array_splice($args, 0, 2);

        $re = sprintf("([A-Z]+) %s(?:$|/)(.*)", $path);
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

            $method = strtolower($method);

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
                // TODO move to Middleware
                if ($this->cors && $fn == 'options'
                    && isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])
                    && isset($_SERVER['HTTP_ORIGIN'])) {

                    header("Access-Control-Allow-Origin: $this->cors");
                    header("Access-Control-Allow-Credentials: true");
                    header("Access-Control-Allow-Methods: GET, PATCH, POST, PUT, DELETE, OPTIONS");
                    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
                        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

                    exit("");
                }

                throw new \Exception(sprintf(_("%s\\%s: Route not found"), get_class($controller), $fn), 404);
            }

            if ($this->cors) {
                header("Access-Control-Allow-Origin: $this->cors");
                header("Access-Control-Allow-Credentials: true");
                header("Access-Control-Expose-Headers: content-range");
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
                    // auto deserialize when type hinted as class and jms/serializer is available
                    if ($fn_param && $fn_param->getClass() && class_exists('\JMS\Serializer\SerializerBuilder')) {
                        $serializer = \JMS\Serializer\SerializerBuilder::create()->build();
                        $type = new \JMS\Serializer\Annotation\Type;
                        $params[] = $serializer->deserialize(Router::parse_post_body(false),
                            $fn_param->getClass()->getName(), 'json');
                    } // hinting as array allows overriding _deserialize
                    elseif ($fn_param && $fn_param->isArray()) {
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

            // Check middlewares

            // Start with default set of middlewares (applied to all requests)
            // Note: will be overridden by class Attributes if mw class is the same
            $middlewares = $this->config['middlewares'];

            // Class Middlewares
            foreach ($refClass->getAttributes(Middleware::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                /** @var Middleware $mw */
                $mw = $attr->newInstance();
                $middlewares[$mw->getClass()] = $mw->getArgs();
            }

            // Method Middlewares (override class middlewares if they exist with the same class name)
            foreach ($refMethod->getAttributes(Middleware::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                $mw = $attr->newInstance();
                unset($middlewares[$mw->getClass()]); // ensure method middleware is inserted after class middlewares
                $middlewares[$mw->getClass()] = $mw->getArgs();
            }

            // With the middlewares list, let's instantiate and execute each
            foreach ($middlewares as $mw_class => $mw_args) {
                // instantiate middleware
                $mw = $this->create($mw_class, $mw_args, [get_class($controller) . $mw_class]);

                $middlewares[$mw_class] = $mw;

                // execute before() middleware functions
                if (method_exists($mw, 'before')) {
                    $params = call_user_func([$mw, 'before'], $method, $fn, $params);
                }
            }

            // we're testing this here in case Middlewares end the request prematurely
            // (needed for OPTIONS in CORS)
            if (!is_callable([$controller, $fn])) {
                throw new \Exception(sprintf(_("%s\\%s: Route not found"), get_class($controller), $fn), 404);
            }

            $response = call_user_func_array([$controller, $fn], $params);

            // execute after() middleware functions in reverse order
            foreach (array_reverse($middlewares) as $mw) {
                $response = call_user_func([$mw, 'after'], $method, $fn, $params, $response);
            }

            // if response is an object OR if the client wants json, return right away
            // the response will be encoded by route() and respond()
            if (
                (is_object($response) && is_callable([$response, 'render']))
                || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'json'))
            ) {
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

            $templates = array_unique(["$template_path$fn", "$$template_path$method"]);

            $template = $this->template($templates, $response);

            // in case no template is available, return the raw response
            return $template ?? $response;
        });
    }

    /**
     * Matches a DELETE request,
     * Callback is called with regex matches + $_GET arguments
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., $_GET)
     * @throws \Exception
     */
    public function DELETE($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("DELETE $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = $_GET;

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Matches a GET request,
     * Callback is called with regex matches + $_GET arguments
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., $_GET)
     * @throws \Exception
     */
    public function GET($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("GET $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = $_GET;

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Matches a POST request,
     * Callback is called with regex matches + decoded post body
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., <$post_body>)
     * @throws \Exception
     */
    public function POST($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("POST $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = Router::parse_post_body();

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Matches a PUT request,
     * Callback is called with regex matches + decoded post body
     * @param $path
     * @param callable $callback function(match[1], match[2], ..., <$post_body>)
     * @throws \Exception
     */
    public function PUT($path, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        $this->route("PUT $path", function () use ($callback) {
            $args = func_get_args();
            $args[] = Router::parse_post_body();

            return call_user_func_array($callback, $args);
        });
    }

    /**
     * Bootstraps an endpoint based on $namespace
     */
    public function run($namespace)
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
     * Constructs an URL for a given path
     *  - If the given url is external or exists as a file on disk, return that file's url
     *  - If the file does not exist, construct a url based on the current script + path info
     *  - If portions of the path exist, treat the rest as parameters (point to another controller)
     *
     * If the given path is NULL, returns the current url with protocol, port and so on
     *
     * Examples
     *  url('css/style.css'); returns '/some_root/my_application/css/style.css'
     *  url('1'); returns '/some_root/my_application/controller.php/1' (if we ran that command from controller.php)
     *  url('othercontroller.php/1/2'); returns '/some_root/my_application/othercontroller.php/1/2' (if othercontroller.php exists)
     *
     * @param $str
     * @return string
     */
    public static function url($str = null)
    {
        if ($str == 'self' || empty($str)) {
            if (
                isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1)
                || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
            ) {
                $protocol = 'https://';
            } else {
                $protocol = 'http://';
            }

            $url = $protocol . $_SERVER['HTTP_HOST'];

            // use port if non default
            $port = isset($_SERVER['HTTP_X_FORWARDED_PORT'])
                ? $_SERVER['HTTP_X_FORWARDED_PORT']
                : (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '');
            $url .=
                (($protocol === 'http://' && $port != 80) || ($protocol === 'https://' && $port != 443))
                    ? ':' . $port
                    : '';

            $url .= !empty($_SERVER['SCRIPT_URL']) ? $_SERVER['SCRIPT_URL'] : $_SERVER['PHP_SELF'];

            // return current url
            return $url;
        } else {

            if (!empty($_SERVER['PATH_INFO'])) {
                if (!empty($_SERVER['SCRIPT_URL'])) {
                    $PATH = substr($_SERVER['SCRIPT_URL'], 0, -1 * strlen($_SERVER['PATH_INFO']));
                } else {
                    $PATH = dirname($_SERVER['SCRIPT_NAME']);
                }
            } else {
                $PATH = dirname($_SERVER['SCRIPT_NAME']);
            }

            return ($PATH == '/' ? '' : $PATH) . ($str[0] == '/' ? $str : '/' . $str);
        }
    }

    public static function parse_post_body($decoded = true, $as_array = true)
    {

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
            case 'PUT':
            case 'PATCH':
                if (!empty($_POST)) {
                    return is_string($_POST) && $decoded ? json_decode($_POST, $as_array) : $_POST;
                }
            default:
                $post_body = file_get_contents('php://input');
                if (strlen($post_body) > 0 && $decoded) {
                    if ($post_body[0] == '{' || $post_body[0] == '[') {
                        return json_decode($post_body, $as_array);
                    } else {
                        parse_str($post_body, $return);
                        return $return;
                    }
                } else {
                    return $post_body;
                }
        }
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
    public static function redirect($to, $code = 301)
    {
        header("HTTP/1.1 $code");

        if(preg_match('#^https?://#', $to)) {
            header("Location: $to");
        }
        else {
            header('Location: ' . Router::url($to));
        }
        exit();
    }

    public static function respond($content, $code = 200)
    {

        header("HTTP/1.1 $code");

        if (is_array($content) && !empty($content[0]) && is_object($content[0])) {
            $obj = $content[0];
        } elseif (is_object($content)) {
            $obj = $content;
        }

        // serialize the response if necessary
        if (!empty($obj)) {
            if (is_callable([$obj, 'render'])) {
                // TODO passar content_type se tiver a header accept
                $content = call_user_func([$obj, 'render']);
            } elseif (!empty(self::$serializers[get_class($obj)])) {
                $content = self::$serializers[get_class($obj)]($content);
            } elseif (class_exists('\JMS\Serializer\SerializerBuilder')) {
                $serializer = \JMS\Serializer\SerializerBuilder::create()->build();
                $content = $serializer->serialize($content, 'json', \JMS\Serializer\SerializationContext::create()->enableMaxDepthChecks());
            } else {
                $content = json_encode($content);
            }
        } elseif (is_array($content)) {
            $content = json_encode($content);
        }

        if (!empty($content) && is_string($content) && ($content[0] == '{' || $content[0] == '[')) {
            header('Content-type: application/json');
        }

        exit($content);
    }

    public static function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
