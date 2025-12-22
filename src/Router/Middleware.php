<?php

namespace Objectiveweb\Router;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware {

    private $args;

    public function __construct(private string $class, ...$args) {
        $this->args = $args;
    }

    public function getClass() {
        return $this->class;
    }

    public function getArgs() {
        return $this->args;
    }
}