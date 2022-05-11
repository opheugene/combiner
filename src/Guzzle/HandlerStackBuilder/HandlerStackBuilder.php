<?php

namespace App\Guzzle\HandlerStackBuilder;

use GuzzleHttp\HandlerStack;

class HandlerStackBuilder
{
    /**
     * @param iterable<callable> $middlewares
     */
    public function build(iterable $middlewares, ?callable $handler = null): HandlerStack
    {
        $stack = HandlerStack::create($handler);

        foreach ($middlewares as $middleware) {
            $stack->push($middleware);
        }

        return $stack;
    }
}
