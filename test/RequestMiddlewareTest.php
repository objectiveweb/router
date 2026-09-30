<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TestableRouter.php';

use Objectiveweb\Router\CorsMiddleware;
use Objectiveweb\Router\Middleware;
use Objectiveweb\Router\RequestMiddlewareInterface;
use PHPUnit\Framework\TestCase;
use Test\Router;

class RequestMiddlewareEvents
{
    public static array $events = [];
}

class RequestDependency
{
}

class RecordingRequestMiddleware implements RequestMiddlewareInterface
{
    public static array $dependencyIds = [];

    public function __construct(
        private RequestDependency $dependency,
        private string $name
    ) {
    }

    public function before(string $method, string $path): void
    {
        self::$dependencyIds[] = spl_object_id($this->dependency);
        RequestMiddlewareEvents::$events[] = "request-before:{$this->name}:$method:$path";
    }

    public function after(string $method, string $path, mixed $response): mixed
    {
        RequestMiddlewareEvents::$events[] = "request-after:{$this->name}:$method:$path";

        return is_string($response)
            ? $response . '|' . $this->name
            : $response;
    }
}

class RecordingControllerMiddleware
{
    public function before(string $method, string $fn, array $params): array
    {
        RequestMiddlewareEvents::$events[] = 'controller-before';

        return $params;
    }

    public function after(string $method, string $fn, array $params, mixed $response): mixed
    {
        RequestMiddlewareEvents::$events[] = 'controller-after';

        return $response;
    }
}

#[Middleware(RecordingControllerMiddleware::class)]
class RequestMiddlewareController
{
    public function index(array $query): string
    {
        RequestMiddlewareEvents::$events[] = 'controller';

        return 'ok';
    }
}

class RequestMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        RequestMiddlewareEvents::$events = [];
        RecordingRequestMiddleware::$dependencyIds = [];

        $_GET = [];
        $_POST = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';
        unset(
            $_SERVER['HTTP_ACCEPT'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'],
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']
        );

        global $response_value, $response_code;
        $response_value = null;
        $response_code = null;
    }

    public function testRequestMiddlewareWrapsControllerMiddleware(): void
    {
        global $response_value;

        $router = new Router(null, [
            'request.middlewares' => [
                RecordingRequestMiddleware::class => ['outer'],
            ],
        ]);
        $router->addRule(RequestDependency::class, ['shared' => true]);
        $router->addRequestMiddleware(RecordingRequestMiddleware::class, ['inner']);

        $router->controller('/', RequestMiddlewareController::class);

        $this->assertSame('ok|inner|outer', $response_value);
        $this->assertSame([
            'request-before:outer:GET:/',
            'request-before:inner:GET:/',
            'controller-before',
            'controller',
            'controller-after',
            'request-after:inner:GET:/',
            'request-after:outer:GET:/',
        ], RequestMiddlewareEvents::$events);

        $this->assertCount(2, RecordingRequestMiddleware::$dependencyIds);
        $this->assertSame(
            RecordingRequestMiddleware::$dependencyIds[0],
            RecordingRequestMiddleware::$dependencyIds[1]
        );
    }

    public function testRequestBeforeRunsBeforeMethodSpecificRouteMatching(): void
    {
        global $response_value;

        $_SERVER['PATH_INFO'] = '/direct';
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $_SERVER['REQUEST_URI'] = '/direct';
        $_SERVER['REDIRECT_URL'] = '/direct';

        $router = new Router();
        $router->addRequestMiddleware(RecordingRequestMiddleware::class, ['global']);

        $router->GET('/direct', static fn (): string => 'unreachable');

        $this->assertNull($response_value);
        $this->assertSame([
            'request-before:global:OPTIONS:/direct',
        ], RequestMiddlewareEvents::$events);
    }

    public function testRequestMiddlewareWrapsDirectRoutes(): void
    {
        global $response_value;

        $_SERVER['PATH_INFO'] = '/direct';
        $_SERVER['REQUEST_URI'] = '/direct';
        $_SERVER['REDIRECT_URL'] = '/direct';

        $router = new Router();
        $router->addRequestMiddleware(RecordingRequestMiddleware::class, ['direct']);

        $router->GET('/direct', function (): string {
            RequestMiddlewareEvents::$events[] = 'callback';

            return 'ok';
        });

        $this->assertSame('ok|direct', $response_value);
        $this->assertSame([
            'request-before:direct:GET:/direct',
            'callback',
            'request-after:direct:GET:/direct',
        ], RequestMiddlewareEvents::$events);
    }

    public function testRequestAfterDoesNotRunWhenCallbackThrows(): void
    {
        global $response_value, $response_code;

        $_SERVER['PATH_INFO'] = '/failure';
        $_SERVER['REQUEST_URI'] = '/failure';
        $_SERVER['REDIRECT_URL'] = '/failure';

        $router = new Router();
        $router->addRequestMiddleware(RecordingRequestMiddleware::class, ['request']);

        $router->GET('/failure', function (): void {
            RequestMiddlewareEvents::$events[] = 'callback';
            throw new \RuntimeException('failure', 500);
        });

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame(500, $response_code);
        $this->assertSame([
            'request-before:request:GET:/failure',
            'callback',
        ], RequestMiddlewareEvents::$events);
    }

    public function testCorsAfterReturnsResponseUnchanged(): void
    {
        $cors = new CorsMiddleware('https://app.example');

        $this->assertSame(
            ['ok' => true],
            $cors->after('GET', '/products', ['ok' => true])
        );
    }
}
