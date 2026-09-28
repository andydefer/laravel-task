<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker;

use AndyDefer\Task\CircuitBreaker\Contracts\CircuitBreakerFactoryInterface;
use AndyDefer\Task\CircuitBreaker\Contracts\CircuitBreakerInterface;
use AndyDefer\Task\CircuitBreaker\ValueObjects\CircuitBreakerKeyVO;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class CircuitBreakerFactory implements CircuitBreakerFactoryInterface
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $failureThreshold = 5,
        private readonly int $successThreshold = 2,
        private readonly int $openSeconds = 60,
    ) {}

    public function for(string $key): CircuitBreakerInterface
    {
        return CircuitBreaker::create(
            new CircuitBreakerKeyVO($key),
            $this->cache,
            $this->failureThreshold,
            $this->successThreshold,
            $this->openSeconds,
        );
    }
}
