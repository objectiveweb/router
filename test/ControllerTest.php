<?php

require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/example/ProductsController.php';
require __DIR__ . '/TestableRouter.php';

use MyApplication\ProductsController;

use Test\Router;

class ControllerTest extends PHPUnit_Framework_TestCase
{

    /** @var ProductsController */
    static protected $controller;

    public static function route($method, $path)
    {

        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;

        Router::controller("/", self::$controller);
    }

    public function setUp()
    {
        self::$controller = new ProductsController("Test");
    }


    public function testCallbackController() {
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        Router::controller("/", function() {
            return new ProductsController("CallbackItem");
        });

        $this->assertEquals(3, count(Router::$response));
        $this->assertEquals(1, Router::$response[0]['sku']);
    }

    public function testControllerFactory() {
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        Router::$factory = function($class, $args) {
            return new $class($args[0]);
        };

        Router::controller("/", 'MyApplication\\ProductsController', 'Factory');

        $this->assertEquals(3, count(Router::$response));
        $this->assertEquals(1, Router::$response[0]['sku']);


        Router::$factory = null;
    }

    public function testIndex()
    {

        self::route("GET", "/");

        $this->assertEquals(3, count(Router::$response));
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
        self::$controller->auth = true;

        $_POST = array(
            "sku" => 10,
            "name" => "Test Product",
            "value" => 89.99
        );

        self::route("POST", "/");

        $this->assertEquals(4, count(self::$controller->products));
        $this->assertEquals(10, self::$controller->products[3]['sku']);

    }

    public function testCustomMethod()
    {
        // will call $controller->getSale

        self::route("GET", "/sale");

        $this->assertEquals(10, Router::$response[0]['price']);
    }

    public function testCustomMethodFallback()
    {
        // will call $controller->sale() as there is no $controller->viewSale() defined
        self::route("VIEW", "/sale");

        $this->assertEquals(90, Router::$response[0]['price']);
    }

}