<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Handlers;

use AndyDefer\Logger\Enums\LogLevel;

/**
 * Interface for output handling in task directives.
 *
 * Centralizes console output and logging with support for muted and verbose modes.
 */
interface OutputHandlerInterface
{
    /**
     * Display an info message.
     *
     * @param  string  $message  The message to display
     * @param  array  $payload  Additional data to log
     */
    public function info(string $message, array $payload = []): self;

    /**
     * Display a success message.
     *
     * @param  string  $message  The message to display
     * @param  array  $payload  Additional data to log
     */
    public function success(string $message, array $payload = []): self;

    /**
     * Display an error message.
     *
     * @param  string  $message  The message to display
     * @param  array  $payload  Additional data to log
     */
    public function error(string $message, array $payload = []): self;

    /**
     * Display a warning message.
     *
     * @param  string  $message  The message to display
     * @param  array  $payload  Additional data to log
     */
    public function warning(string $message, array $payload = []): self;

    /**
     * Display a debug message (only in verbose mode).
     *
     * @param  string  $message  The message to display
     * @param  array  $payload  Additional data to log
     */
    public function debug(string $message, array $payload = []): self;

    /**
     * Display a title message.
     *
     * @param  string  $message  The title to display
     * @param  array  $payload  Additional data to log
     */
    public function title(string $message, array $payload = []): self;

    /**
     * Display a line of text.
     *
     * @param  string  $message  The text to display
     */
    public function line(string $message = ''): self;

    /**
     * Display raw text without formatting.
     *
     * @param  string  $line  The raw text to display
     */
    public function raw(string $line): self;

    /**
     * Display key-value data with colored values.
     *
     * @param  array|object  $data  The data to display
     * @param  string  $valueColor  The color for values
     */
    public function keyValue(array|object $data, string $valueColor = 'green'): self;

    /**
     * Display data as JSON.
     *
     * @param  array|string  $data  The data to display
     * @param  int  $maxDepth  Maximum depth for JSON encoding
     */
    public function json(array|string $data, int $maxDepth = 3): self;

    /**
     * Display an alert message with a specific type.
     *
     * @param  string  $message  The message to display
     * @param  string  $type  The alert type (info, success, error, warning)
     * @param  array  $payload  Additional data to log
     */
    public function alert(string $message, string $type = 'info', array $payload = []): self;

    /**
     * Display remaining tasks count.
     *
     * @param  int  $uniquePending  Number of pending unique tasks
     * @param  int  $recurringPlaying  Number of playing recurring tasks
     * @param  int  $recurringWaiting  Number of waiting recurring tasks
     */
    public function remainingTasks(int $uniquePending, int $recurringPlaying, int $recurringWaiting): self;

    /**
     * Log a cycle summary with success/failed/total counts.
     *
     * @param  int  $cycleNumber  The cycle number
     * @param  int  $success  Number of successful tasks
     * @param  int  $failed  Number of failed tasks
     * @param  int  $total  Total number of tasks
     */
    public function cycleSummary(int $cycleNumber, int $success, int $failed, int $total): self;

    /**
     * Log a detailed cycle summary with breakdown by task type.
     *
     * @param  int  $cycleNumber  The cycle number
     * @param  int  $totalSuccess  Total successful tasks
     * @param  int  $totalFailed  Total failed tasks
     * @param  int  $totalErrors  Total errors
     * @param  int  $uniqueSuccess  Unique task successes
     * @param  int  $uniqueFailed  Unique task failures
     * @param  int  $recurringSuccess  Recurring task successes
     * @param  int  $recurringFailed  Recurring task failures
     */
    public function cycleSummaryDetailed(
        int $cycleNumber,
        int $totalSuccess,
        int $totalFailed,
        int $totalErrors,
        int $uniqueSuccess,
        int $uniqueFailed,
        int $recurringSuccess,
        int $recurringFailed
    ): self;

    /**
     * Display final summary with detailed breakdown.
     *
     * @param  int  $totalCycles  Total number of cycles
     * @param  int  $totalSuccess  Total successful tasks
     * @param  int  $totalFailed  Total failed tasks
     * @param  int  $totalErrors  Total errors
     * @param  int  $uniqueSuccess  Unique task successes
     * @param  int  $uniqueFailed  Unique task failures
     * @param  int  $recurringSuccess  Recurring task successes
     * @param  int  $recurringFailed  Recurring task failures
     * @param  float  $elapsedSeconds  Elapsed time in seconds
     * @param  int|null  $plannedDuration  Planned duration in seconds
     * @param  bool  $stoppedBySignal  Whether stopped by signal
     * @param  int|null  $workers  Number of workers
     */
    public function finalSummary(
        int $totalCycles,
        int $totalSuccess,
        int $totalFailed,
        int $totalErrors,
        int $uniqueSuccess,
        int $uniqueFailed,
        int $recurringSuccess,
        int $recurringFailed,
        float $elapsedSeconds,
        ?int $plannedDuration = null,
        bool $stoppedBySignal = false,
        ?int $workers = null
    ): self;

    /**
     * Log a message without displaying it.
     *
     * @param  LogLevel  $level  The log level
     * @param  string  $message  The message to log
     * @param  array  $payload  Additional data to log
     */
    public function log(LogLevel $level, string $message, array $payload = []): self;

    /**
     * Check if output is muted.
     *
     * @return bool True if muted
     */
    public function isMuted(): bool;

    /**
     * Check if verbose mode is enabled.
     *
     * @return bool True if verbose
     */
    public function isVerbose(): bool;

    /**
     * Creates a child OutputHandler with the same configuration.
     *
     * @param  array  $context  Context data for the child handler
     */
    public function withContext(array $context): self;
}
