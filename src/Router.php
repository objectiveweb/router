<?php

namespace Objectiveweb;


class Router {

    /**
     * Route a particular request to a callback
     *
     *
     * @throws \Exception
     * @param $request - HTTP Request Method + Request-URI Regex e.g. "GET /something/([0-9]+)/?"
     * @param $callback - A valid callback. Regex capture groups are passed as arguments to this function
     * @return void or data - If the callback returns something, it's responded accordingly, otherwise, nothing happens
     */
    public static function route($request, $callback) {
        if (!is_callable($callback)) {
            throw new \Exception(sprintf(_('%s: Invalid callback'), $callback), 500);
        }

        if(!isset($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = '/';
        }

        // TODO check if using PATH_INFO is ok in all cases (rewrite, different servers, etc)

        if (preg_match(sprintf("/^%s$/", str_replace('/', '\/', $request)), "{$_SERVER['REQUEST_METHOD']} {$_SERVER['PATH_INFO']}", $params)) {
            array_shift($params);

            if (func_num_args() > 2) {
                $params = array_merge($params, array_slice(func_get_args(), 2));
            }

            try {
                $response = call_user_func_array($callback, $params);
                if ($response !== NULL) {
                    Router::respond($response);
                }
            }
            catch (\Exception $ex) {
                Router::respond($ex->getMessage(), $ex->getCode());
            }
        }
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
    public static function url($str = null) {
        if ($str == 'self' || empty($str)) {
            if (
                isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1)
                || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
            ) {
                $protocol = 'https://';
            }
            else {
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
        }
        else {

            if(!empty($_SERVER['PATH_INFO'])) {
                if(!empty($_SERVER['SCRIPT_URL'])) {
                    $PATH = substr($_SERVER['SCRIPT_URL'], 0, -1 * strlen($_SERVER['PATH_INFO']));
                }
                else {
                    $PATH = dirname($_SERVER['SCRIPT_NAME']);
                }
            }
            else {
                $PATH = dirname($_SERVER['SCRIPT_NAME']);
            }

            return $PATH. ($str[0] == '/' ? $str : '/' . $str);

        }

    }


    public static function parse_post_body($decoded = true) {

        switch($_SERVER['REQUEST_METHOD']) {

            case 'POST':
                if (!empty($_POST)) {
                    return $_POST;
                };
            case 'PUT':
                $post_body = file_get_contents('php://input');
                if(strlen($post_body) > 0 && $decoded) {
                    if($post_body[0] == '{' || $post_body[0] == '[') {
                        return json_decode($post_body, true);
                    }
                    else {
                        parse_str($post_body, $return);
                        return $return;
                    }
                }
                else {
                    return $post_body;
                }
        }
    }

    public static function redirect($to) {
        header('Location: ' . Router::url($to, true));
        exit();
    }

    public static function respond($content, $code = 200) {

        header("HTTP/1.1 $code");

        if (is_array($content)) {

            $content = json_encode($content);
        }

        if ($content[0] == '{' || $content[0] == '[') {
            header('Content-type: application/json');
        }

        exit($content);
    }


}