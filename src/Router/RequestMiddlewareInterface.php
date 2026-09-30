<?php

namespace Objectiveweb\Router;

interface RequestMiddlewareInterface
{
    /**
     * Runs after a route matches but before callback/controller resolution.
     */
    public function before(
        string $method,
        string $path
    ): void;

    /**
     * Runs after callback/controller execution and may transform the response.
     */
    public function after(
        string $method,
        string $path,
        mixed $response
    ): mixed;
}
