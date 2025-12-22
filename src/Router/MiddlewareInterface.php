<?php

namespace Objectiveweb\Router;
interface MiddlewareInterface
{
    /**
     * Runs before controller execution.
     * Must return updated params or throw.
     */
    public function before(
        string $method,
        string $fn,
        array  $params
    ): mixed;

    public function after(
        string $method,
        string $fn,
        array  $params,
        array|null  $response): mixed;
}