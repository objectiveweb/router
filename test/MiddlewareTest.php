<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TestableRouter.php';

use Objectiveweb\Router\Middleware;
use PHPUnit\Framework\TestCase;
use Test\Router;

class RecordingMiddleware
{
    public static array $events = [];

    public function __construct(private string $name)
    {
    }

    public function before(string $method, string $fn, array $params): array
    {
        self::$events[] = 'before:' . $this->name;

        return $params;
    }

    public function after(string $method, string $fn, array $params, mixed $response): mixed
    {
        self::$events[] = 'after:' . $this->name;

        return $response;
    }
}

class SharedMiddlewareDependency
{
}

class DependencyAwareMiddleware
{
    public static array $middlewareIds = [];
    public static array $dependencyIds = [];

    public function __construct(
        public SharedMiddlewareDependency $dependency,
        private string $name
    ) {
    }

    public function before(string $method, string $fn, array $params): array
    {
        self::$middlewareIds[] = spl_object_id($this);
        self::$dependencyIds[] = spl_object_id($this->dependency);

        return $params;
    }
}

#[Middleware(DependencyAwareMiddleware::class, 'first')]
#[Middleware(DependencyAwareMiddleware::class, 'second')]
class DependencyAwareMiddlewareController
{
    public static ?int $dependencyId = null;

    public function __construct(SharedMiddlewareDependency $dependency)
    {
        self::$dependencyId = spl_object_id($dependency);
    }

    public function index(array $query): string
    {
        return 'ok';
    }
}

class BeforeOnlyMiddleware
{
    public static int $calls = 0;

    public function before(string $method, string $fn, array $params): array
    {
        self::$calls++;

        return $params;
    }
}

class InvalidBeforeMiddleware
{
    public function before(string $method, string $fn, array $params): string
    {
        return 'invalid';
    }
}

#[Middleware(RecordingMiddleware::class, 'class-first')]
#[Middleware(RecordingMiddleware::class, 'class-second')]
class RepeatedMiddlewareController
{
    public function index(array $query): string
    {
        RecordingMiddleware::$events[] = 'controller';

        return 'ok';
    }
}

#[Middleware(RecordingMiddleware::class, 'class')]
class MethodOverrideMiddlewareController
{
    #[Middleware(RecordingMiddleware::class, 'method-first')]
    #[Middleware(RecordingMiddleware::class, 'method-second')]
    public function index(array $query): string
    {
        RecordingMiddleware::$events[] = 'controller';

        return 'ok';
    }
}

#[Middleware(BeforeOnlyMiddleware::class)]
class BeforeOnlyMiddlewareController
{
    public function index(array $query): string
    {
        return 'ok';
    }
}

#[Middleware(InvalidBeforeMiddleware::class)]
class InvalidBeforeMiddlewareController
{
    public function index(array $query): string
    {
        return 'unreachable';
    }
}

class MiddlewareTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();

        RecordingMiddleware::$events = [];
        DependencyAwareMiddleware::$middlewareIds = [];
        DependencyAwareMiddleware::$dependencyIds = [];
        DependencyAwareMiddlewareController::$dependencyId = null;
        BeforeOnlyMiddleware::$calls = 0;

        $_GET = [];
        $_POST = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';
        unset($_SERVER['HTTP_ACCEPT']);

        global $response_value, $response_code;
        $response_value = null;
        $response_code = null;
    }

    public function testRepeatedMiddlewareOfSameClassIsPreserved(): void
    {
        global $response_value;

        $this->router->controller('/', RepeatedMiddlewareController::class);

        $this->assertSame('ok', $response_value);
        $this->assertSame([
            'before:class-first',
            'before:class-second',
            'controller',
            'after:class-second',
            'after:class-first',
        ], RecordingMiddleware::$events);
    }

    public function testMethodMiddlewareOverridesClassMiddlewareOfSameClassAndPreservesRepeats(): void
    {
        global $response_value;

        $this->router->controller('/', MethodOverrideMiddlewareController::class);

        $this->assertSame('ok', $response_value);
        $this->assertSame([
            'before:method-first',
            'before:method-second',
            'controller',
            'after:method-second',
            'after:method-first',
        ], RecordingMiddleware::$events);
    }

    public function testRepeatedMiddlewareInstancesAreDistinctAndShareNormalDependencies(): void
    {
        global $response_value;

        $this->router->addRule(SharedMiddlewareDependency::class, [
            'shared' => true,
        ]);

        $this->router->controller('/', DependencyAwareMiddlewareController::class);

        $this->assertSame('ok', $response_value);
        $this->assertCount(2, DependencyAwareMiddleware::$middlewareIds);
        $this->assertNotSame(
            DependencyAwareMiddleware::$middlewareIds[0],
            DependencyAwareMiddleware::$middlewareIds[1]
        );

        $this->assertCount(2, DependencyAwareMiddleware::$dependencyIds);
        $this->assertSame(
            DependencyAwareMiddleware::$dependencyIds[0],
            DependencyAwareMiddleware::$dependencyIds[1]
        );
        $this->assertSame(
            DependencyAwareMiddlewareController::$dependencyId,
            DependencyAwareMiddleware::$dependencyIds[0]
        );
    }

    public function testMiddlewareWithoutAfterHookDoesNotCrash(): void
    {
        global $response_value;

        $this->router->controller('/', BeforeOnlyMiddlewareController::class);

        $this->assertSame('ok', $response_value);
        $this->assertSame(1, BeforeOnlyMiddleware::$calls);
    }

    public function testBeforeHookMustReturnArray(): void
    {
        global $response_value, $response_code;

        $this->router->controller('/', InvalidBeforeMiddlewareController::class);

        $this->assertInstanceOf(\UnexpectedValueException::class, $response_value);
        $this->assertSame(
            InvalidBeforeMiddleware::class . '::before() must return an array',
            $response_value->getMessage()
        );
        $this->assertSame(500, $response_code);
    }
}
