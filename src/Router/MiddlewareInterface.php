<?php

namespace Objectiveweb\Router;

interface MiddlewareInterface
{
    /**
     * Runs before controller execution.
     * Must return the complete argument list for the controller method.
     */
    public function before(
        string $method,
        string $fn,
        array $params
    ): array;

    /**
     * Runs after controller execution and may transform the response.
     */
    public function after(
        string $method,
        string $fn,
        array $params,
        mixed $response
    ): mixed;
}
