<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker\Contracts;

interface CircuitBreakerFactoryInterface
{
    public function for(string $key): CircuitBreakerInterface;
}
