<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TestableRouter.php';

use PHPUnit\Framework\TestCase;
use Test\Router;

class ErrorBoundaryVerbController
{
    public function get(array $query): array
    {
        return $query;
    }

    public function post(array $body): array
    {
        return $body;
    }
}

class ErrorBoundaryTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();

        $_GET = [];
        $_POST = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';
        unset($_SERVER['HTTP_ACCEPT']);

        global $response_value, $response_code, $response_debug;
        $response_value = null;
        $response_code = null;
        $response_debug = null;
    }

    public function testTypeErrorIsCapturedAsInternalServerError(): void
    {
        global $response_value, $response_code;

        $_SERVER['PATH_INFO'] = '/type-error';
        $_SERVER['REQUEST_URI'] = '/type-error';
        $_SERVER['REDIRECT_URL'] = '/type-error';

        $this->router->GET('/type-error', function () {
            strlen([]);
        });

        $this->assertInstanceOf(\TypeError::class, $response_value);
        $this->assertSame(500, $response_code);
    }

    public function testExceptionCodeZeroIsNormalizedTo500(): void
    {
        global $response_value, $response_code;

        $_SERVER['PATH_INFO'] = '/zero';
        $_SERVER['REQUEST_URI'] = '/zero';
        $_SERVER['REDIRECT_URL'] = '/zero';

        $this->router->GET('/zero', function () {
            throw new \RuntimeException('Failure');
        });

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame(500, $response_code);
    }

    public function testValidHttpExceptionCodeIsPreserved(): void
    {
        global $response_value, $response_code;

        $_SERVER['PATH_INFO'] = '/missing';
        $_SERVER['REQUEST_URI'] = '/missing';
        $_SERVER['REDIRECT_URL'] = '/missing';

        $this->router->GET('/missing', function () {
            throw new \RuntimeException('Missing', 404);
        });

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame(404, $response_code);
    }

    public function testDependencyInjectionFailureIsInsideBoundary(): void
    {
        global $response_value, $response_code;

        $_SERVER['PATH_INFO'] = '/di';
        $_SERVER['REQUEST_URI'] = '/di';
        $_SERVER['REDIRECT_URL'] = '/di';

        $this->router->route(
            'GET /di',
            ['Objectiveweb\\Router\\Tests\\MissingController', 'index']
        );

        $this->assertInstanceOf(\Throwable::class, $response_value);
        $this->assertSame(500, $response_code);
    }

    public function testInvalidCallbackIsInsideBoundary(): void
    {
        global $response_value, $response_code;

        $_SERVER['PATH_INFO'] = '/invalid';
        $_SERVER['REQUEST_URI'] = '/invalid';
        $_SERVER['REDIRECT_URL'] = '/invalid';

        $this->router->route('GET /invalid', 'not-a-callable');

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame(500, $response_code);
    }

    public function testVerbHelpersKeepInvalidCallbacksInsideBoundary(): void
    {
        global $response_value, $response_code;

        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $response_value = null;
            $response_code = null;

            $_POST = [];
            $_SERVER['PATH_INFO'] = '/invalid-helper';
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['REQUEST_URI'] = '/invalid-helper';
            $_SERVER['REDIRECT_URL'] = '/invalid-helper';
            unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);

            $this->router->{$method}('/invalid-helper', 'not-a-callable');

            $this->assertInstanceOf(\RuntimeException::class, $response_value);
            $this->assertSame(500, $response_code);
        }
    }

    public function testGetHelperResolvesClassCallbackThroughDice(): void
    {
        global $response_value, $response_code;

        $_GET = ['filter' => 'active'];
        $_SERVER['PATH_INFO'] = '/class-helper';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/class-helper';
        $_SERVER['REDIRECT_URL'] = '/class-helper';

        $this->router->GET(
            '/class-helper',
            [ErrorBoundaryVerbController::class, 'get']
        );

        $this->assertSame(['filter' => 'active'], $response_value);
        $this->assertSame(200, $response_code);
    }

    public function testPostHelperResolvesClassCallbackAndParsesBodyInsideBoundary(): void
    {
        global $response_value, $response_code;

        $_POST = '{"name":"router"}';
        $_SERVER['PATH_INFO'] = '/class-helper';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/class-helper';
        $_SERVER['REDIRECT_URL'] = '/class-helper';

        $this->router->POST(
            '/class-helper',
            [ErrorBoundaryVerbController::class, 'post']
        );

        $this->assertSame(['name' => 'router'], $response_value);
        $this->assertSame(200, $response_code);
    }

    public function testPostHelperBodyParsingFailureStaysInsideBoundary(): void
    {
        global $response_value, $response_code;

        $_POST = '{"name":';
        $_SERVER['PATH_INFO'] = '/body-error';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/body-error';
        $_SERVER['REDIRECT_URL'] = '/body-error';

        $this->router->POST('/body-error', static fn (array $body) => $body);

        $this->assertInstanceOf(\RuntimeException::class, $response_value);
        $this->assertSame('Invalid JSON request body', $response_value->getMessage());
        $this->assertSame(400, $response_code);
    }

    public function testRouterDebugConfigurationPropagatesToResponsePipeline(): void
    {
        global $response_debug;

        $router = new Router(null, ['debug' => true]);

        $_SERVER['PATH_INFO'] = '/debug';
        $_SERVER['REQUEST_URI'] = '/debug';
        $_SERVER['REDIRECT_URL'] = '/debug';

        $router->GET('/debug', static function (): void {
            throw new \RuntimeException('debug detail');
        });

        $this->assertTrue($response_debug);
    }

    public function testDebugConfigurationMustBeBoolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Router(null, ['debug' => 'yes']);
    }

    public function testThrowableUsesStandardErrorEnvelope(): void
    {
        $response = Router::prepareResponseForTest(
            new \TypeError('Bad argument'),
            'application/json',
            500,
            true
        );

        $this->assertSame('application/json', $response['content_type']);

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(\TypeError::class, $body['exception']);
        $this->assertSame('Bad argument', $body['message']);
    }
}
