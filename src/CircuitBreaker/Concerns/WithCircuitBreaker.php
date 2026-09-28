<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker\Concerns;

use AndyDefer\Task\CircuitBreaker\Contracts\CircuitBreakerFactoryInterface;
use AndyDefer\Task\CircuitBreaker\Contracts\CircuitBreakerInterface;

/**
 * Opt-in trait providing a `withBreaker()` helper to task classes.
 *
 * Tasks that do not use this trait keep working unchanged. Tasks that use
 * it get access to the circuit breaker without altering the interface or
 * the base class contract.
 */
trait WithCircuitBreaker
{
    /**
     * Execute a callback under the given circuit breaker.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withBreaker(string $key, callable $callback): mixed
    {
        return $this->circuitBreaker($key)->execute($callback);
    }

    protected function circuitBreaker(string $key): CircuitBreakerInterface
    {
        /** @var CircuitBreakerFactoryInterface $factory */
        $factory = app(CircuitBreakerFactoryInterface::class);

        return $factory->for($key);
    }
}
