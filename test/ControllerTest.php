<?php

require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/example/App/HomeController.php';
require dirname(__DIR__) . '/example/App/ProductsController.php';
require dirname(__DIR__) . '/example/App/DB/ProductsRepository.php';
require __DIR__ . '/TestableRouter.php';

use App\ProductsController;

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
                    array('name' => "Cassete Recorder", 'sku' => 1, 'price' => 100.00),
                    array('name' => "Tractor Beam", 'sku' => 2, 'price' => 7.99)
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
        $this->assertEquals(1, Router::$response[0]['sku']);

    }

    public function testGet()
    {
        self::route("GET", "/2");

        $this->assertEquals(2, Router::$response['sku']);
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

        $_POST = array(
            "sku" => 10,
            "name" => "Test Product",
            "price" => 89.99
        );

        $_SERVER['PATH_INFO'] = "/";
        $_SERVER['REQUEST_METHOD'] = "POST";

        self::$app->controller("/", $controller);
        
        $this->assertEquals(3, $repository->count());
        $v = $repository->get(10);
        $this->assertEquals(89.99, $v['price']);

    }

    public function testCustomMethod()
    {
        // will call $controller->getSale

        self::route("GET", "/sale");

        $this->assertEquals(10, Router::$response[0]['price']);
    }

    public function testControllerParameters() {
        self::route("GET", "/hello");
        $this->assertEquals("Hello TEST", Router::$response);
    }
    
    public function testCustomMethodFallback()
    {
        // will call $controller->sale() as there is no $controller->viewSale() defined
        self::route("VIEW", "/sale");

        $this->assertEquals(90, Router::$response[0]['price']);
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