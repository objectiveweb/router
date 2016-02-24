# Objectiveweb URL Router ![Build Status](https://travis-ci.org/objectiveweb/router.svg?branch=master)

Lightweight url router with dependency injection support.

## Instalation

Add the dependency to `composer.json`, then `composer install`

    {
        "require": {
            "objectiveweb/router": "~2.0"
        }
    }

## Basic Usage

In Objectiveweb applications, an endpoint refers to a php file which responds to url routes.

Under the hood, the `route($regex, $callable)` function tests `$regex` against the current 
request method and uri (e.g. "GET endpoint.php/something/([0-9]+)/?"), passing the captured 
parameters to `$callable` when matched.

### Example endpoint

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
    
    // catch all
    $app->router("([A_Z]+) (.*), function ($method, $path) {
        return "Request matched $method and $path";
    });

  * GET http://server/endpoint.php displays `Hello index`
  * GET http://server/endpoint.php/data returns a json-encoded array with values 1, 2, 3
  * GET http://server/endpoint.php/test returns a not found error with a json object on its body
  * POST http://server/endpoint.php/panic raises a 500 Internal server error with 'Panic!' as the response

## Controllers

PHP classes may be bound to an url using the `controller($path, $class)` on public-facing endpoint (index.php)
        
    $app->controller('/', 'ExampleController');
    
In this case, the request is mapped to the corresponding class method as follows

    <?php
    
    class ExampleController {
    
        // GET /?k=v
        function index($querystring) {
        
        }
        
        // GET /(.*)?k=v
        function get($path1, $path2, ..., $querystring) {
        
        }
        
        // POST /
        function post($body) {
        
        }
        
        // Other request methods are also valid, i.e. head(), options(), etc
        
    }

When a function named like the first parameter ($path[0]) exists on the controller, it gets called with the 
remaining parameters

    // (GET|POST|PUT|...) /example/(.*)
    function example($path[1], $path[2], ...) {
        // check $_SERVER['REQUEST_METHOD'] and process data
    }
    
This function name may also be prefixed with the request method. In this case the query parameters are passed as the 
last argument

    // GET /example/(.*)
    function getExample($path[1], $path[2], ..., $_GET) {
    
    }
    
    // POST /example/(.*)
    function postExample($path[1], $path[2], ..., $decoded_post_body) {
    
    }
    
Other request methods are also valid (i.e. HEAD, OPTIONS, etc), check the example subdir for other uses.

### Automatic routing

You can bootstrap the application on a particular namespace using

    $app->run('Namespace');

When run, the Router automatically maps the incoming requests to the given namespace. For example, a request to
/products would instantiate the `Namespace\ProductsController` class.

If the Controller doesn't exist, the request is passed to the `Namespace\HomeController`. Check `example/app-run.php`
for a working demo.

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
    
When bound to paths, Controller  (And dependencies of those dependencies) get automatically resolved. 
For example, if you define the controller

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

When `MyApplication\MyController` gets instantiated by the Router, a configured instance of PDO will 
be injected and reused as necessary.

You can inject dependencies adding type-hinted parameters to your controller's constructor:

    function __construct(\Util\Gmaps $gmaps, \DB\ProductsRepository $products) {
      $this->gmaps = $gmaps;
      $this->products = $products;
    }
    
    // Use $this->gmaps and $this->products on other functions

In a another example, let's instantiate Twig
    
    // index.php
    
    $app = new \Objectiveweb\Router();
    
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
    
Then, inject it on your controller's constructor
    
    class MyController {
    
        private $twig;
        private $pdo;
        
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
