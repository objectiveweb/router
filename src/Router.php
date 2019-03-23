<?php

namespace Objectiveweb;

use JMS\Serializer\SerializationContext;

class Router extends \Dice\Dice
{

    private static $serializers = [];

    static function addSerializer($type, $callback) {
        self::$serializers[$type] = $callback;
    }

    static function hasSerializer($type) {
        return !empty(self::$serializers[$type]);
    }

    /**
     * Route a particular request to a callback
     *
     *
     * @throws \Exception
     * @param $request - HTTP Request Method + Request-URI Regex e.g. "GET /something/([0-9]+)/?"
     * @param $callback - A valid callback. Regex capture groups are passed as arguments to this function, using
     *   array('Namespace\ClassNameAsString', 'method') triggers the dependency injector to instantiate the given class
     * @return void or data - If the callback returns something, it's responded accordingly, otherwise, nothing happens
     */
    public function route($request, $callback)
    {
        if (is_array($callback) && is_string($callback[0])) {
            $callback[0] = $this->create($callback[0]);
        }

        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        if (!isset($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = '/';
        }

        // support PATH_INFO when using mod_rewrite
        if ($_SERVER['PATH_INFO'] == '/' && substr($_SERVER['REQUEST_URI'], 0, strlen($_SERVER['SCRIPT_NAME'])) != $_SERVER['SCRIPT_NAME']) {
            $_SERVER['PATH_INFO'] = substr($_SERVER['REQUEST_URI'], strlen(dirname($_SERVER['SCRIPT_NAME'])));
        }

        if (preg_match(sprintf("/^%s$/", str_replace('/', '\/', $request)), "{$_SERVER['REQUEST_METHOD']} {$_SERVER['PATH_INFO']}", $params)) {
            array_shift($params);

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
                    self::respond($ex->getMessage(), $ex->getCode());
                }
            }
        }
    }

    /**
     * Runs $callable with arguments if it's callable, otherwise, does nothing
     * @param $callable
     */
    private function _call($callable)
    {
        if (is_callable($callable)) {
            $args = func_get_args();
            array_shift($args);
            call_user_func_array($callable, $args);
        }
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

        $this->route("([A-Z]+) $path/?(.*)", function ($method, $params) use ($controller, $args) {

            if (is_string($controller)) {
                $controller = $this->create($controller, $args);
            }

            $method = strtolower($method);

            // url parameters
            $params = explode("/", $params);

            // An GET $path/1/2/3/4 request will be parsed into
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



            switch ($method) {
                // append the decoded body to the argument list for (post|put|patch).* methods
                case "post":
                case "put":
                case "patch":
                    $r = new \ReflectionMethod($controller, $fn);
                    $rparams = $r->getParameters();
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

            // Process controller.before
            $p = $this->_call([$controller, 'before'], $method, $fn, $params);

            if($p) {
                $params = $p;
            }

            // Process controller.before[Post|Get|Put|Delete|...]
            $p = $this->_call([$controller, 'before' . ucfirst($method)], $fn, $params);

            if($p) {
                $params = $p;
            }

            return call_user_func_array(array($controller, $fn), $params);
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
    public function run($namespace) {
      
        $router = $this;
      
        $this->route("([A-Z]+) /(.*)", function($method, $path) use ($router, $namespace) {
            
            if(!empty($path)) {
                $path = explode("/", $path); 
                $class = "$namespace\\".ucfirst($path[0])."Controller";
              
                if(class_exists($class)) {
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
     * @param $to URL to redirect to
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
        header('Location: ' . Router::url($to));
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

        if ($content[0] == '{' || $content[0] == '[') {
            header('Content-type: application/json');
        }

        exit($content);
    }

    public static function render($_template, $_data = [])
    {

        if (!is_readable($_template)) {
            throw new \Exception("Cannot read $_template", 404);
        }

        extract($_data);

        ob_start();
        include $_template;
        $contents = ob_get_contents();
        ob_end_clean();

        return $contents;
    }
}
