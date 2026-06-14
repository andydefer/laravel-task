<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Configs;

/**
 * Interface for Task system configuration.
 *
 * Provides access to task storage paths, grace period settings,
 * and batch processing limits.
 *
 * This interface serves as a contract - services depend on this interface,
 * never on the concrete implementation.
 */
interface TaskConfigInterface
{
    // ==================== Storage Paths ====================

    /**
     * Get the base storage path for all task data.
     */
    public function storagePath(): string;

    /**
     * Get the path for grace period records.
     */
    public function storageGracePeriodPath(): string;

    /**
     * Get the path for pending tasks.
     */
    public function storagePendingPath(): string;

    /**
     * Get the path for recurring tasks.
     */
    public function storageRecurringPath(): string;

    /**
     * Get the path for completed tasks.
     */
    public function storageCompletedPath(): string;

    // ==================== Grace Period ====================

    /**
     * Check if grace period is enabled for expired tasks.
     */
    public function gracePeriodEnabled(): bool;

    /**
     * Get the grace period duration in seconds.
     */
    public function gracePeriodSeconds(): int;

    // ==================== Batch Processing ====================

    /**
     * Get the batch processing limit (null if unlimited).
     */
    public function batchLimit(): ?int;

    /**
     * Get the batch processing order ('oldest' or 'newest').
     */
    public function batchOrder(): string;

    // ==================== Utility Methods ====================

    /**
     * Check if batch order is set to 'oldest' (FIFO).
     */
    public function isOldestOrder(): bool;

    /**
     * Check if batch order is set to 'newest' (LIFO).
     */
    public function isNewestOrder(): bool;

    /**
     * Check if a batch limit is configured.
     */
    public function hasLimit(): bool;

    /**
     * Get the effective processing limit.
     *
     * @param int|null $customLimit Custom limit (0 = none, null = use config)
     * @return int|null Effective limit to use
     */
    public function getEffectiveLimit(?int $customLimit = null): ?int;
}
