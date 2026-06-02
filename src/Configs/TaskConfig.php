<?php

declare(strict_types=1);

namespace AndyDefer\Task\Configs;

use AndyDefer\DomainStructures\Abstracts\AbstractConfig;

/**
 * Configuration for the task system.
 *
 * Provides access to task storage paths, grace period settings,
 * and batch processing limits.
 *
 * All values are read directly from Laravel config.
 * NO CONSTRUCTOR, NO PROPERTIES.
 */
class TaskConfig extends AbstractConfig
{
    public function storagePath(): string
    {
        return config('task.storage_path', storage_path('tasks'));
    }

    public function storageGracePeriodPath(): string
    {
        return $this->storagePath() . '/grace_period';
    }

    public function storagePendingPath(): string
    {
        return $this->storagePath() . '/pending';
    }

    public function storageRecurringPath(): string
    {
        return $this->storagePath() . '/recurring';
    }

    public function storageCompletedPath(): string
    {
        return $this->storagePath() . '/completed';
    }

    public function gracePeriodEnabled(): bool
    {
        return config('task.grace_period.enabled', true);
    }

    public function gracePeriodSeconds(): int
    {
        return config('task.grace_period.seconds', 86400);
    }

    public function batchLimit(): ?int
    {
        $limit = config('task.batch.limit', 1000);

        return $limit > 0 ? $limit : null;
    }

    public function batchOrder(): string
    {
        return config('task.batch.order', 'oldest');
    }

    public function isOldestOrder(): bool
    {
        return $this->batchOrder() === 'oldest';
    }

    public function isNewestOrder(): bool
    {
        return $this->batchOrder() === 'newest';
    }

    public function hasLimit(): bool
    {
        return $this->batchLimit() !== null;
    }

    public function getEffectiveLimit(?int $customLimit = null): ?int
    {
        if ($customLimit === 0) {
            return 0;
        }

        if ($customLimit !== null) {
            return $customLimit;
        }

        return $this->batchLimit();
    }
}
