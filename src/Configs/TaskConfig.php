<?php

declare(strict_types=1);

namespace AndyDefer\Task\Configs;

use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Configuration for the task system - Laravel implementation.
 *
 * Provides access to task storage paths, grace period settings,
 * and batch processing limits.
 *
 * All values are read directly from Laravel config via ConfigRepository injection.
 * NO PROPERTIES, NO STATE.
 *
 * @see TaskConfigInterface
 */
final class TaskConfig implements TaskConfigInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    // ==================== Storage Paths ====================

    public function storagePath(): string
    {
        return $this->config->get('task.storage_path', storage_path('tasks'));
    }

    public function storageGracePeriodPath(): string
    {
        return $this->storagePath().'/grace_period';
    }

    public function storagePendingPath(): string
    {
        return $this->storagePath().'/pending';
    }

    public function storageRecurringPath(): string
    {
        return $this->storagePath().'/recurring';
    }

    public function storageCompletedPath(): string
    {
        return $this->storagePath().'/completed';
    }

    // ==================== Grace Period ====================

    public function gracePeriodEnabled(): bool
    {
        return $this->config->get('task.grace_period.enabled', true);
    }

    public function gracePeriodSeconds(): int
    {
        return (int) $this->config->get('task.grace_period.seconds', 86400);
    }

    // ==================== Batch Processing ====================

    public function batchLimit(): ?int
    {
        $limit = $this->config->get('task.batch.limit', 1000);

        return $limit > 0 ? $limit : null;
    }

    public function batchOrder(): string
    {
        return $this->config->get('task.batch.order', 'oldest');
    }

    // ==================== Utility Methods ====================

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
