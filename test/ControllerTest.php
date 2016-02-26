<?php

require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/example/App/HomeController.php';
require dirname(__DIR__) . '/example/App/ProductsController.php';
require dirname(__DIR__) . '/example/App/DB/ProductsRepository.php';
require dirname(__DIR__) . '/example/App/Model/Product.php';
require __DIR__ . '/TestableRouter.php';

use App\ProductsController;
use App\Model\Product;
use Test\Router;

class ControllerTest extends PHPUnit_Framework_TestCase
{

    /** @var ProductsController */
    static protected $controller;

    /** @var  Router */
    static protected $app;

    public static function setUpBeforeClass()
    {
        self::$app = new Router();
        self::$app->addRule('App\DB\ProductsRepository', [
            'shared' => true,
            'constructParams' => [
                array(
                    new Product(1,"Cassete Recorder", 100.00),
                    new Product(2, "Tractor Beam", 7.99)
                )
            ]
        ]);
    }

    public static function route($method, $path)
    {

        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;

        self::$app->controller("/", 'App\ProductsController', 'TEST');
    }

    public function testIndex()
    {

        self::route("GET", "/");

        $this->assertEquals(2, count(Router::$response));
        $this->assertEquals(1, Router::$response[0]->sku);

    }

    public function testGet()
    {
        self::route("GET", "/2");

        $this->assertEquals(2, Router::$response->sku);
    }

    public function testBeforePost()
    {

        self::route("POST", "/");

        $this->assertEquals(403, Router::$code);

    }

    public function testPost()
    {
        $controller = self::$app->create('App\ProductsController');
        $repository = self::$app->create('App\DB\ProductsRepository');
        
        $controller->auth = true;

        $_POST = '{ "sku" : 10, "name" : "Test Product", "price" : 89.99 }';
        
        $_SERVER['PATH_INFO'] = "/";
        $_SERVER['REQUEST_METHOD'] = "POST";
        try {
            self::$app->controller("/", $controller);
        }
        catch(\Exception $ex) {
            exit($ex->getMessage());
        }

        $r = Router::$response;
        $this->assertEquals('App\Model\Product', get_class($r));
        $this->assertEquals(3, $repository->count());
        $v = $repository->get(10);
        $this->assertEquals(89.99, $v->price);
        

    }

    public function testPut() {
        $repository = self::$app->create('App\DB\ProductsRepository');
        $controller = self::$app->create('App\ProductsController');
        $controller->auth = true;
        
        $_POST = '{ "name" : "Test Rename", "price" : 89.99 }';
        
        $_SERVER['PATH_INFO'] = "/10";
        $_SERVER['REQUEST_METHOD'] = "PUT";
        
        self::$app->controller("/", $controller);
        $e = Router::$response;
        $this->assertEquals("Test Rename", $e->name);

    }
    
    public function testCustomMethod()
    {
        // will call $controller->getSale

        self::route("GET", "/sale");

        $this->assertEquals(90, Router::$response[0]->price);
    }

    public function testControllerParameters() {
        self::route("GET", "/hello");
        $this->assertEquals("Hello TEST", Router::$response);
    }
    
    public function testCustomMethodFallback()
    {
        // will call $controller->sale() as there is no $controller->viewSale() defined
        self::route("VIEW", "/sale");
        
        $this->assertEquals(12345, Router::$response[0]->price);
    }
    
    public function testAppRun() {
        
        $_SERVER['PATH_INFO'] = "/say/hello";
        $_SERVER['REQUEST_METHOD'] = "GET";

        self::$app->run('App');
        
        $this->assertEquals('hello', Router::$response);
        
//         $_SERVER['PATH_INFO'] = "/products";
//         $_SERVER['REQUEST_METHOD'] = "GET";

//         self::$app->run('App');
//         print_r(Router::$response);
//         $this->assertEquals(2, count(Router::$response));
//         $this->assertEquals(1, Router::$response[0]['sku']);
        
    }
}