<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker;

use AndyDefer\Task\CircuitBreaker\Contracts\CircuitBreakerInterface;
use AndyDefer\Task\CircuitBreaker\Enums\CircuitBreakerState;
use AndyDefer\Task\CircuitBreaker\Exceptions\CircuitOpenException;
use AndyDefer\Task\CircuitBreaker\ValueObjects\CircuitBreakerKeyVO;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

final class CircuitBreaker implements CircuitBreakerInterface
{
    private const CACHE_PREFIX = 'task:circuit:';

    private function __construct(
        private readonly CircuitBreakerKeyVO $key,
        private readonly CacheRepository $cache,
        private readonly int $failureThreshold,
        private readonly int $successThreshold,
        private readonly int $openSeconds,
    ) {}

    public static function create(
        CircuitBreakerKeyVO $key,
        CacheRepository $cache,
        int $failureThreshold,
        int $successThreshold,
        int $openSeconds,
    ): self {
        return new self(
            $key,
            $cache,
            max(1, $failureThreshold),
            max(1, $successThreshold),
            max(1, $openSeconds),
        );
    }

    public function key(): string
    {
        return $this->key->getValue();
    }

    public function state(): CircuitBreakerState
    {
        $raw = $this->cache->get($this->stateKey(), CircuitBreakerState::CLOSED->value);

        return CircuitBreakerState::tryFrom($raw) ?? CircuitBreakerState::CLOSED;
    }

    public function execute(callable $callback): mixed
    {
        $this->assertAllowsExecution();

        try {
            $result = $callback();
            $this->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $this->recordFailure();

            throw $e;
        }
    }

    public function recordSuccess(): void
    {
        $state = $this->state();

        if ($state === CircuitBreakerState::HALF_OPEN) {
            $this->incrementSuccessCounter();

            if ($this->successCount() >= $this->successThreshold) {
                $this->reset();
            }

            return;
        }

        $this->cache->forget($this->failureKey());
    }

    public function recordFailure(): void
    {
        $this->cache->increment($this->failureKey());
        $this->cache->forever($this->failureKey().':at', now()->toIso8601String());

        if ($this->failureCount() >= $this->failureThreshold) {
            $this->open();
        }
    }

    public function reset(): void
    {
        $this->cache->forget($this->failureKey());
        $this->cache->forget($this->failureKey().':at');
        $this->cache->forget($this->successKey());
        $this->cache->forever($this->stateKey(), CircuitBreakerState::CLOSED->value);
    }

    private function assertAllowsExecution(): void
    {
        $state = $this->state();

        if ($state !== CircuitBreakerState::OPEN) {
            if ($state === CircuitBreakerState::CLOSED && $this->shouldHalfOpen()) {
                $this->cache->forever($this->stateKey(), CircuitBreakerState::HALF_OPEN->value);
                $this->cache->forget($this->successKey());
            }

            return;
        }

        $openedAt = $this->cache->get($this->openedAtKey());

        if ($openedAt === null) {
            $this->reset();

            return;
        }

        $elapsed = now()->diffInSeconds($openedAt, true);

        if ($elapsed >= $this->openSeconds) {
            $this->cache->forever($this->stateKey(), CircuitBreakerState::HALF_OPEN->value);
            $this->cache->forget($this->successKey());

            return;
        }

        throw CircuitOpenException::forKey(
            $this->key->getValue(),
            max(0, $this->openSeconds - (int) $elapsed),
        );
    }

    private function open(): void
    {
        $this->cache->forever($this->stateKey(), CircuitBreakerState::OPEN->value);
        $this->cache->forever($this->openedAtKey(), now()->toIso8601String());
        $this->cache->forget($this->successKey());
    }

    private function shouldHalfOpen(): bool
    {
        $openedAt = $this->cache->get($this->openedAtKey());

        if ($openedAt === null) {
            return false;
        }

        return now()->diffInSeconds($openedAt, true) >= $this->openSeconds;
    }

    private function failureCount(): int
    {
        return (int) $this->cache->get($this->failureKey(), 0);
    }

    private function successCount(): int
    {
        return (int) $this->cache->get($this->successKey(), 0);
    }

    private function incrementSuccessCounter(): void
    {
        $this->cache->increment($this->successKey());
    }

    private function stateKey(): string
    {
        return self::CACHE_PREFIX.$this->key->getValue().':state';
    }

    private function failureKey(): string
    {
        return self::CACHE_PREFIX.$this->key->getValue().':failures';
    }

    private function successKey(): string
    {
        return self::CACHE_PREFIX.$this->key->getValue().':successes';
    }

    private function openedAtKey(): string
    {
        return self::CACHE_PREFIX.$this->key->getValue().':opened_at';
    }
}
