# Objectiveweb URL Router

Lightweight router for php controllers.

## Instalation

Add the dependency to `composer.json`, then `composer install`

    {
        "require": {
            "objectiveweb/router": "~1.0"
        }
    }

## Usage

In Objectiveweb applications, a controller is a php file which responds to url routes.

`Router::route($regex, $callable)` tests `$regex` against the request method and uri (e.g. "GET /something/([0-9]+)/?"),
passing the captured parameters to `$callable` when matched

### Example controller.php

    <?php
    // include the composer autoloader
    require_once('../vendor/autoload.php')

    use Objectiveweb\Router;

    // Routes are matched in order

    Router::route('GET /?', function() {
        echo "Hello index';

    });

    Router::route('GET /([a-z]+)/?', function($key) {

        switch($key) {
            case 'data':
                // If the callback returns something, it's sent with status 200 (OK)
                // Arrays are automatically encoded to json
                return [ 1, 2, 3 ];
                break;
            default:
                Router::respond([ 'key' => $key, 'empty' => true ], 404); // respond with custom code
                break;
        }
    });

    Router::route('POST /panic', function() {
        // Exceptions are captured, message is send with the proper code
        throw new Exception('Panic!', 500);
    });

  * GET http://server/controller.php displays `Hello index`
  * GET http://server/controller.php/data returns a json-encoded array with values 1, 2, 3
  * GET http://server/controller.php/test returns a not found error with a json object on its body
  * POST http://server/controller.php/panic raises a 500 Internal server error with 'Panic!' as the response

## Utility methods

  * Router::url($path) returns an url relative to the current controller

        // controller.php

        Router::url('/css/styles.css'); // css/styles.css
        Router::url('/path'); // controller.php/path (if path does not exist)

  * Router::parse_post_body($decoded = true) returns the post body (optionally json-decoded) either from $_POST or php://stdin

  * Router::redirect($to) redirects the user to another location

  * Router::respond($content, $code = 200) sends `$content` to the browser, if `$content` is an array it is
  properly json-encoded before the output.