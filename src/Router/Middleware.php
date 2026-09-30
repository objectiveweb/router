<?php

namespace Objectiveweb\Router;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    private array $args;

    public function __construct(
        private string $class,
        mixed ...$args
    ) {
        $this->args = $args;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getArgs(): array
    {
        return $this->args;
    }
}
