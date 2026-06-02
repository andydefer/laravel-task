<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Result object for batch task processing.
 * Contains statistics about processed tasks.
 */
final class BatchResult extends AbstractRecord
{
    private int $uniqueSuccess = 0;

    private int $uniqueFailed = 0;

    private int $recurringSuccess = 0;

    private int $recurringFailed = 0;

    /** @var array<string, bool> */
    private array $uniqueResults = [];

    /** @var array<string, bool> */
    private array $recurringResults = [];

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private readonly \DateTimeImmutable $startedAt = new \DateTimeImmutable,
    ) {}

    public function addUniqueTask(string $id, bool $success, ?string $error = null): void
    {
        $this->uniqueResults[$id] = $success;
        if ($success) {
            $this->uniqueSuccess++;
        } else {
            $this->uniqueFailed++;
            if ($error !== null) {
                $this->errors[$id] = $error;
            }
        }
    }

    public function addRecurringTask(string $signature, bool $success, ?string $error = null): void
    {
        $this->recurringResults[$signature] = $success;
        if ($success) {
            $this->recurringSuccess++;
        } else {
            $this->recurringFailed++;
            if ($error !== null) {
                $this->errors[$signature] = $error;
            }
        }
    }

    public function getUniqueSuccess(): int
    {
        return $this->uniqueSuccess;
    }

    public function getUniqueFailed(): int
    {
        return $this->uniqueFailed;
    }

    public function getRecurringSuccess(): int
    {
        return $this->recurringSuccess;
    }

    public function getRecurringFailed(): int
    {
        return $this->recurringFailed;
    }

    public function getTotalSuccess(): int
    {
        return $this->uniqueSuccess + $this->recurringSuccess;
    }

    public function getTotalFailed(): int
    {
        return $this->uniqueFailed + $this->recurringFailed;
    }

    public function getTotal(): int
    {
        return $this->getTotalSuccess() + $this->getTotalFailed();
    }

    public function hasFailures(): bool
    {
        return $this->getTotalFailed() > 0;
    }

    public function isSuccessful(): bool
    {
        return ! $this->hasFailures();
    }

    /** @return array<string, bool> */
    public function getUniqueResults(): array
    {
        return $this->uniqueResults;
    }

    /** @return array<string, bool> */
    public function getRecurringResults(): array
    {
        return $this->recurringResults;
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable;
    }

    public function getDurationMilliseconds(): int
    {
        return (int) ((microtime(true) - $this->startedAt->getTimestamp()) * 1000);
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'started_at' => $this->startedAt->format('c'),
            'unique_success' => $this->uniqueSuccess,
            'unique_failed' => $this->uniqueFailed,
            'recurring_success' => $this->recurringSuccess,
            'recurring_failed' => $this->recurringFailed,
            'total_success' => $this->getTotalSuccess(),
            'total_failed' => $this->getTotalFailed(),
            'total' => $this->getTotal(),
            'has_failures' => $this->hasFailures(),
            'duration_ms' => $this->getDurationMilliseconds(),
        ];
    }
}
