<?php

namespace Objectiveweb\Router;

interface RequestMiddlewareInterface
{
    /**
     * Runs once for the incoming request before route matching begins.
     */
    public function before(
        string $method,
        string $path
    ): void;

    /**
     * Runs after a matched callback/controller produces a response and may transform it.
     */
    public function after(
        string $method,
        string $path,
        mixed $response
    ): mixed;
}
