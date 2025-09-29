<?php

namespace Objectiveweb\Router;

class Middleware {
    public function before($method, $fn, $params): mixed {
        return null;
    }

    public function after($method, $fn, $params, $response): mixed {
        return null;
    }
}