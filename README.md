# Objectiveweb URL Router ![Build Status](https://travis-ci.org/objectiveweb/router.svg?branch=master)

Lightweight router for php controllers with dependency injection support.

## Instalation

Add the dependency to `composer.json`, then `composer install`

    {
        "require": {
            "objectiveweb/router": "~2.0"
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

    $app = new Router();
    
    // Routes are matched in order

    $app->GET('/?', function() {
        echo "Hello index';
    });

    $app->GET('/([a-z]+)/?', function($key) {

        switch($key) {
            case 'data':
                // If the callback returns something, it's sent with status 200 (OK)
                // Arrays are automatically encoded to json
                return [ 1, 2, 3 ];
                break;
            default:
                throw new \Exception([ 'key' => $key, 'empty' => true ], 404); // respond with custom code
                break;
        }
    });

    $app->POST('/panic', function() {
        // Exceptions are captured, message is send with the proper code
        throw new \Exception('Panic!', 500);
    });

  * GET http://server/controller.php displays `Hello index`
  * GET http://server/controller.php/data returns a json-encoded array with values 1, 2, 3
  * GET http://server/controller.php/test returns a not found error with a json object on its body
  * POST http://server/controller.php/panic raises a 500 Internal server error with 'Panic!' as the response

## Controllers

PHP classes may be bound directly to an endpoint. In this case, the request method is mapped to the corresponding
class method as follows

 * GET /      => $controller->index($_GET);
 * POST /       => $controller->post($decoded_post_body);
 * PUT /      => $controller->put($decoded_post_body);
 * PATCH /    => $controller->patch($decoded_post_body);
 * (Other request methods are also valid, i.e. HEAD, OPTIONS, etc)
 * GET /(.*)  => $controller->get($path[0], $path[1], ..., $_GET)

When a function named like the first parameter ($path[0]) exists on the controller, it gets called with the 
remaining parameters

 * GET /example/(.*) => $controller->example($path[1], ...)
 
The function name may also be prefixed with the request method. In this case the query parameters are passed as the 
last argument

 * GET /example/(.*) => $controller->getExample($path[1], ..., $_GET);
 * POST /example/(.*) => $controller->postExample($path[1], ..., $decoded_post_body);

Other request methods are also valid (i.e. HEAD, OPTIONS, etc), check the included example for more information.
 
## Dependency Injection

Since version 2.0, the Router extends [Dice](https://r.je/dice.html), which provides a dependency injection 
container to the application. 

    <?php
    // include the composer autoloader
    require_once('../vendor/autoload.php')

    use Objectiveweb\Router;

    $app = new Router();
    
    // Configure the DI container rules for the PDO class
    $app->addRule('PDO', [
        'shared' => true,
        'constructParams' => ['mysql:host=127.0.0.1;dbname=mydb', 'username', 'password'] 
    ]);

    // From now on, you can get a configured PDO instance using
    $pdo = $app->create('PDO');
    
When bound to paths, Controller dependencies are also automatically resolved. For example, 
if you defined the controller

    <?php
    
    namespace MyApplication;
    
    class MyController {
    
        private $pdo;
        
        function __construct(PDO $pdo) {
            $this->pdo = pdo;
        }
        
        function index() {
            // query the database using $this->pdo
        }
    }

And the public-facing endpoint (index.php)
        
    // When `MyApplication\MyController` gets instantiated, all the constructor 
    // dependencies (And dependencies of those dependencies) are be automatically resolved.
    // In this case, PDO will be reused
    $app->controller('/products', 'MyApplication\MyController');
    
In a more complicated example, let's instantiate Twig
    
    // index.php
    
    $app->addRule('Twig_Loader_Filesystem', array(
        'shared' => true,
        'constructParams' => [ TEMPLATE_ROOT ]
    ));
    
    $app->addRule('Twig_Environment', array(
        'shared' => true,
        'constructParams' => [
            [ 'instance' => 'Twig_Loader_Filesystem' ],
            [ 'cache' => APP_ROOT.'/cache' ],
            [ 'auto_reload' => true ]
        ],
        'call' => [
            [ 'addGlobal', [ 'server', $_SERVER['SERVER_NAME'] ] ],
            [ 'addGlobal', [ 'app_name', APP_NAME ] ],
            [ 'addGlobal', [ 'session', $_SESSION ] ],
            [ 'addFunction', [ new Twig_SimpleFunction('url', function ($path) {
                return \Objectiveweb\Router::url($path);
            })]]
        ]
    ));

    $app->controller('/', 'MyController')
    
Then, inject it on your controller constructor
    
    class MyController {
        function __construct(Twig_Environment $twig, PDO $pdo) {
            $this->twig = $twig;
            $this->pdo = $pdo;
        }
        
        function index() {
            return $this->twig->render(...);
        }
    }
    
You can also fetch the twig reference using
    
    $twig = $app->create('Twig_Environment');
    
### Rules

Dice Rules can be configured with these properties:

  * shared (boolean) - Whether a single instance is used throughout the container. 
  [View Example](https://r.je/dice.html#example2-2)
  * inherit (boolean) - Whether the rule will also apply to subclasses (defaults to true). 
  [View Example](https://r.je/dice.html#example3-2)
  * constructParams (array) - Additional parameters passed to the constructor. 
  [View Example](https://r.je/dice.html#example3-3)
  * substitutions (array) - key->value substitutions for dependencies. 
  [View Example](https://r.je/dice.html#example3-1)
  * call (multidimensional array) - A list of methods and their arguments which will be 
  called after the object has been constructed. [View Example](https://r.je/dice.html#example3-4)
  * instanceOf (string) - The name of the class to initiate. Used when the class name is not passed 
  to `$app->addRule()`. [View Example](https://r.je/dice.html#example3-6)
  * shareInstances (array) - A list of class names that will be shared throughout a single object 
  tree. [View Example](https://r.je/dice.html#example3-7)
  