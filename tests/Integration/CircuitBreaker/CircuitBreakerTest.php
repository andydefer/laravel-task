<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\CircuitBreaker;

use AndyDefer\Task\CircuitBreaker\CircuitBreaker;
use AndyDefer\Task\CircuitBreaker\Enums\CircuitBreakerState;
use AndyDefer\Task\CircuitBreaker\Exceptions\CircuitOpenException;
use AndyDefer\Task\CircuitBreaker\ValueObjects\CircuitBreakerKeyVO;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use RuntimeException;

final class CircuitBreakerTest extends IntegrationTestCase
{
    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 23, 12, 0, 0));

        $this->cache = $this->app->make(CacheRepository::class);
        $this->cache->clear();
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function makeBreaker(
        string $key = 'test.service',
        int $failureThreshold = 3,
        int $successThreshold = 2,
        int $openSeconds = 60,
    ): CircuitBreaker {
        return CircuitBreaker::create(
            new CircuitBreakerKeyVO($key),
            $this->cache,
            $failureThreshold,
            $successThreshold,
            $openSeconds,
        );
    }

    private function failingCallback(): callable
    {
        return static fn () => throw new RuntimeException('boom');
    }

    private function succeedingCallback(mixed $value = 'ok'): callable
    {
        return static fn () => $value;
    }

    private function expectExceptionFrom(callable $callback, string $exceptionClass): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return;
            }

            throw $e;
        }

        $this->assertTrue(
            false,
            sprintf('Expected exception %s was not thrown.', $exceptionClass),
        );
    }

    // ==================== INITIAL STATE ====================

    public function test_initial_state_is_closed(): void
    {
        $breaker = $this->makeBreaker();

        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());
    }

    public function test_key_is_returned(): void
    {
        $breaker = $this->makeBreaker('my.service');

        $this->assertSame('my.service', $breaker->key());
    }

    // ==================== SUCCESS PATH ====================

    public function test_execute_returns_callback_result_on_success(): void
    {
        $breaker = $this->makeBreaker();

        $result = $breaker->execute($this->succeedingCallback('payload'));

        $this->assertSame('payload', $result);
        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());
    }

    public function test_successful_execution_resets_failure_counter(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 5);

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);
        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $breaker->execute($this->succeedingCallback());

        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());
    }

    // ==================== FAILURE THRESHOLD ====================

    public function test_breaker_opens_after_threshold_failures(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 3);

        for ($i = 0; $i < 3; $i++) {
            $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);
        }

        $this->assertSame(CircuitBreakerState::OPEN, $breaker->state());
    }

    public function test_breaker_stays_closed_below_threshold(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 3);

        for ($i = 0; $i < 2; $i++) {
            $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);
        }

        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());
    }

    // ==================== OPEN STATE ====================

    public function test_open_breaker_throws_circuit_open_exception(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1);

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $this->expectException(CircuitOpenException::class);

        $breaker->execute($this->succeedingCallback());
    }

    public function test_open_breaker_message_contains_key(): void
    {
        $breaker = $this->makeBreaker('firebase.fcm', failureThreshold: 1);

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        try {
            $breaker->execute($this->succeedingCallback());
            $this->assertTrue(false, 'Expected CircuitOpenException was not thrown.');
        } catch (CircuitOpenException $e) {
            $this->assertStringContainsString('firebase.fcm', $e->getMessage());
        }
    }

    public function test_open_breaker_does_not_call_callback(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1);

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $called = false;

        try {
            $breaker->execute(function () use (&$called) {
                $called = true;

                return 'ok';
            });
        } catch (CircuitOpenException) {
            // expected
        }

        $this->assertFalse($called);
    }

    // ==================== HALF OPEN ====================

    public function test_breaker_transitions_to_half_open_after_open_seconds(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1, openSeconds: 10);

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $this->assertSame(CircuitBreakerState::OPEN, $breaker->state());

        Carbon::setTestNow(Carbon::now()->addSeconds(11));

        // Premier succès : passe en HALF_OPEN
        $breaker->execute($this->succeedingCallback());
        $this->assertSame(CircuitBreakerState::HALF_OPEN, $breaker->state());

        // Deuxième succès : ferme le breaker
        $breaker->execute($this->succeedingCallback());
        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());
    }

    public function test_half_open_requires_multiple_successes_to_close(): void
    {
        $breaker = $this->makeBreaker(
            failureThreshold: 1,
            successThreshold: 2,
            openSeconds: 10,
        );

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        Carbon::setTestNow(Carbon::now()->addSeconds(11));

        $breaker->execute($this->succeedingCallback());
        $breaker->execute($this->succeedingCallback());

        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());
    }

    public function test_half_open_failure_reopens_breaker(): void
    {
        $breaker = $this->makeBreaker(
            failureThreshold: 1,
            openSeconds: 10,
        );

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        Carbon::setTestNow(Carbon::now()->addSeconds(11));

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $this->assertSame(CircuitBreakerState::OPEN, $breaker->state());
    }

    // ==================== RECORD SUCCESS / FAILURE API ====================

    public function test_record_failure_opens_breaker_when_threshold_reached(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 2);

        $breaker->recordFailure();
        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());

        $breaker->recordFailure();
        $this->assertSame(CircuitBreakerState::OPEN, $breaker->state());
    }

    public function test_record_success_clears_failure_counter_when_closed(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordSuccess();

        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());
    }

    // ==================== RESET ====================

    public function test_reset_closes_breaker_and_clears_counters(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1);

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $this->assertSame(CircuitBreakerState::OPEN, $breaker->state());

        $breaker->reset();

        $this->assertSame(CircuitBreakerState::CLOSED, $breaker->state());

        $result = $breaker->execute($this->succeedingCallback('ok'));
        $this->assertSame('ok', $result);
    }

    // ==================== ISOLATION PER KEY ====================

    public function test_breakers_are_isolated_by_key(): void
    {
        $breakerA = $this->makeBreaker('service.a', failureThreshold: 1);
        $breakerB = $this->makeBreaker('service.b', failureThreshold: 1);

        $this->expectExceptionFrom(fn () => $breakerA->execute($this->failingCallback()), RuntimeException::class);

        $this->assertSame(CircuitBreakerState::OPEN, $breakerA->state());
        $this->assertSame(CircuitBreakerState::CLOSED, $breakerB->state());

        $result = $breakerB->execute($this->succeedingCallback('ok'));
        $this->assertSame('ok', $result);
    }

    // ==================== EXCEPTION PROPAGATION ====================

    public function test_original_exception_is_propagated(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $breaker->execute($this->failingCallback());
    }

    // ==================== CLAMPING ====================

    public function test_thresholds_are_clamped_to_minimum_one(): void
    {
        $breaker = $this->makeBreaker(
            failureThreshold: 0,
            successThreshold: 0,
            openSeconds: 0,
        );

        $this->expectExceptionFrom(fn () => $breaker->execute($this->failingCallback()), RuntimeException::class);

        $this->assertSame(CircuitBreakerState::OPEN, $breaker->state());
    }
}
