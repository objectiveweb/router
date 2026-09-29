<?php

require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/example/App/HomeController.php';
require dirname(__DIR__) . '/example/App/ProductsController.php';
require dirname(__DIR__) . '/example/App/DB/ProductsRepository.php';
require dirname(__DIR__) . '/example/App/Model/Product.php';
require_once __DIR__ . '/TestableRouter.php';

use App\Model\Product;
use App\ProductsController;
use PHPUnit\Framework\TestCase;
use Test\Router;

class ControllerTest extends TestCase
{
    private Router $app;

    protected function setUp(): void
    {
        $this->app = new Router();
        $this->app->addRule('App\\DB\\ProductsRepository', [
            'shared' => true,
            'constructParams' => [
                [
                    new Product(1, 'Cassete Recorder', 100.00),
                    new Product(2, 'Tractor Beam', 7.99),
                ],
            ],
        ]);

        $_GET = [];
        $_POST = [];

        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);

        global $response_value, $response_code;
        $response_value = null;
        $response_code = null;
    }

    private function route(string $method, string $path): void
    {
        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REDIRECT_URL'] = $path;

        $this->app->controller('/', ProductsController::class, 'TEST');
    }

    public function testIndex(): void
    {
        global $response_value;

        $this->route('GET', '/');

        $this->assertCount(2, $response_value);
        $this->assertSame(1, $response_value[0]->sku);
    }

    public function testGet(): void
    {
        global $response_value;

        $this->route('GET', '/2');

        $this->assertSame(2, $response_value->sku);
    }

    public function testPost(): void
    {
        global $response_value;

        $controller = $this->app->create(ProductsController::class);
        $repository = $this->app->create('App\\DB\\ProductsRepository');

        $_POST = '{ "sku" : 10, "name" : "Test Product", "price" : 89.99 }';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';

        $this->app->controller('/', $controller);

        $this->assertInstanceOf(Product::class, $response_value);
        $this->assertSame(3, $repository->count());
        $this->assertSame(89.99, $repository->get(10)->price);
    }

    public function testClassTypedBodyRejectsUnsupportedContentType(): void
    {
        global $response_value, $response_code;

        $controller = $this->app->create(ProductsController::class);
        $repository = $this->app->create('App\DB\ProductsRepository');

        $_POST = 'sku=10&name=Test+Product&price=89.99';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';

        $this->app->controller('/', $controller);

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame(415, $response_code);
        $this->assertSame(2, $repository->count());
    }

    public function testClassTypedBodyRejectsMissingContentType(): void
    {
        global $response_value, $response_code;

        $controller = $this->app->create(ProductsController::class);

        $_POST = '{"sku":10,"name":"Test Product","price":89.99}';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';

        $this->app->controller('/', $controller);

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame(415, $response_code);
    }

    public function testClassTypedBodyRejectsMalformedJson(): void
    {
        global $response_value, $response_code;

        $controller = $this->app->create(ProductsController::class);
        $repository = $this->app->create('App\DB\ProductsRepository');

        $_POST = '{"sku":10,"name":"broken"';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';

        $this->app->controller('/', $controller);

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame('Invalid JSON request body', $response_value->getMessage());
        $this->assertSame(400, $response_code);
        $this->assertSame(2, $repository->count());
    }

    public function testClassTypedBodyAcceptsStructuredJsonMediaType(): void
    {
        global $response_value;

        $controller = $this->app->create(ProductsController::class);

        $_POST = '{"sku":10,"name":"Test Product","price":89.99}';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/vnd.objectiveweb+json';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';

        $this->app->controller('/', $controller);

        $this->assertInstanceOf(Product::class, $response_value);
        $this->assertSame(10, $response_value->sku);
    }

    public function testPut(): void
    {
        global $response_value;

        $repository = $this->app->create('App\\DB\\ProductsRepository');
        $controller = $this->app->create(ProductsController::class);

        $_POST = '{ "name" : "Test Rename", "price" : 89.99 }';
        $_SERVER['PATH_INFO'] = '/2';
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/2';
        $_SERVER['REDIRECT_URL'] = '/2';

        $this->app->controller('/', $controller);

        $this->assertSame('Test Rename', $response_value->name);
        $this->assertSame('Test Rename', $repository->get(2)->name);
    }

    public function testCustomMethod(): void
    {
        global $response_value;

        $this->route('GET', '/sale');

        $this->assertSame(90, $response_value[0]->price);
    }

    public function testControllerParameters(): void
    {
        global $response_value;

        $this->route('GET', '/hello');

        $this->assertSame('Hello TEST', $response_value);
    }

    public function testCustomMethodFallback(): void
    {
        global $response_value;

        $this->route('VIEW', '/sale/8777');

        $this->assertSame('8777', $response_value[0]->price);
    }

    public function testControllerObjectResponseBypassesTemplateLookup(): void
    {
        global $response_value, $response_code;

        $controller = new class {
            public function index(): object
            {
                return (object) ['ok' => true];
            }
        };

        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';

        $this->app->controller('/', $controller);

        $this->assertIsObject($response_value);
        $this->assertTrue($response_value->ok);
        $this->assertSame(200, $response_code);
    }

    public function testRegisteredSerializerDoesNotBypassRespond(): void
    {
        global $response_value, $response_code;

        $response = new class {
            public string $value = 'test';
        };
        $serializerCalled = false;

        Router::addSerializer(get_class($response), function () use (&$serializerCalled) {
            $serializerCalled = true;
            return ['serialized' => true];
        });

        $_SERVER['PATH_INFO'] = '/serialized';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/serialized';
        $_SERVER['REDIRECT_URL'] = '/serialized';

        $this->app->GET('/serialized', fn () => $response);

        $this->assertSame($response, $response_value);
        $this->assertSame(200, $response_code);
        $this->assertFalse($serializerCalled);
    }

    public function testRegisteredExceptionSerializerDoesNotBypassRespond(): void
    {
        global $response_value, $response_code;

        $exception = new class('Teapot', 418) extends \Exception {
        };
        $serializerCalled = false;

        Router::addSerializer(get_class($exception), function () use (&$serializerCalled) {
            $serializerCalled = true;
            return ['serialized' => true];
        });

        $_SERVER['PATH_INFO'] = '/exception';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/exception';
        $_SERVER['REDIRECT_URL'] = '/exception';

        $this->app->GET('/exception', function () use ($exception) {
            throw $exception;
        });

        $this->assertSame($exception, $response_value);
        $this->assertSame(418, $response_code);
        $this->assertFalse($serializerCalled);
    }

    public function testAppRun(): void
    {
        global $response_value;

        $_SERVER['PATH_INFO'] = '/say/hello';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/say/hello';
        $_SERVER['REDIRECT_URL'] = '/say/hello';

        $this->app->run('App');

        $this->assertSame('hello', $response_value);
    }
}
