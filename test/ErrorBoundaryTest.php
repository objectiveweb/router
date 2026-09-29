<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TestableRouter.php';

use PHPUnit\Framework\TestCase;
use Test\Router;

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

        global $response_value, $response_code;
        $response_value = null;
        $response_code = null;
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

    public function testThrowableUsesStandardErrorEnvelope(): void
    {
        $response = Router::prepareResponseForTest(
            new \TypeError('Bad argument'),
            'application/json'
        );

        $this->assertSame('application/json', $response['content_type']);

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(\TypeError::class, $body['exception']);
        $this->assertSame('Bad argument', $body['message']);
    }
}
