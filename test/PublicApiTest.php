<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Objectiveweb\Router;
use PHPUnit\Framework\TestCase;

class PublicApiTest extends TestCase
{
    public function testDeadCallHelperIsNotPartOfRouter(): void
    {
        $this->assertFalse(method_exists(Router::class, '_call'));
    }

    public function testIsAjaxHasBooleanContract(): void
    {
        $method = new \ReflectionMethod(Router::class, 'isAjax');

        $this->assertSame('bool', (string) $method->getReturnType());

        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $this->assertFalse(Router::isAjax());

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $this->assertTrue(Router::isAjax());

        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    public function testStraightforwardPublicMethodsExposeTypedContracts(): void
    {
        $expectations = [
            'addSerializer' => ['void', 2],
            'hasSerializer' => ['bool', 1],
            'addRule' => ['void', 2],
            'create' => ['object', 2],
            'template' => ['?Objectiveweb\\Router\\Template', 3],
            'route' => ['void', 3],
            'controller' => ['void', 3],
            'DELETE' => ['void', 2],
            'GET' => ['void', 2],
            'POST' => ['void', 2],
            'PUT' => ['void', 2],
            'PATCH' => ['void', 2],
            'run' => ['void', 1],
            'url' => ['string', 1],
            'parse_post_body' => ['mixed', 2],
            'redirect' => ['never', 2],
            'negotiateContentType' => ['?string', 2],
            'isAjax' => ['bool', 0],
        ];

        foreach ($expectations as $methodName => [$returnType, $parameters]) {
            $method = new \ReflectionMethod(Router::class, $methodName);

            $this->assertSame(
                $returnType,
                (string) $method->getReturnType(),
                "$methodName return type"
            );
            $this->assertSame(
                $parameters,
                $method->getNumberOfParameters(),
                "$methodName parameter count"
            );
        }
    }

    public function testRouteAndControllerExposeVariadicArguments(): void
    {
        foreach (['route', 'controller'] as $methodName) {
            $method = new \ReflectionMethod(Router::class, $methodName);
            $params = $method->getParameters();

            $this->assertCount(3, $params);
            $this->assertTrue($params[2]->isVariadic(), "$methodName args must be variadic");
            $this->assertSame('mixed', (string) $params[2]->getType());
            $this->assertSame(2, $method->getNumberOfRequiredParameters());
        }
    }

    public function testRouteCallbackRemainsMixedForControlledErrorBoundary(): void
    {
        $method = new \ReflectionMethod(Router::class, 'route');
        $callback = $method->getParameters()[1];

        $this->assertSame('mixed', (string) $callback->getType());
    }
}
