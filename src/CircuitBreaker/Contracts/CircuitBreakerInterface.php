<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker\Contracts;

use AndyDefer\Task\CircuitBreaker\Enums\CircuitBreakerState;
use AndyDefer\Task\CircuitBreaker\Exceptions\CircuitOpenException;

interface CircuitBreakerInterface
{
    public function key(): string;

    public function state(): CircuitBreakerState;

    /**
     * Execute a callback under the circuit breaker protection.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws CircuitOpenException
     * @throws \Throwable
     */
    public function execute(callable $callback): mixed;

    public function recordSuccess(): void;

    public function recordFailure(): void;

    public function reset(): void;
}
