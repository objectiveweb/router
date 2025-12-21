<?php

namespace Objectiveweb\Router;

class Middleware {
    public function before($method, $fn, $params): mixed {
        return $params;
    }

    public function after($method, $fn, $params, $response): mixed {
        return $response;
    }
}