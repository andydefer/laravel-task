<?php

declare(strict_types=1);

namespace AndyDefer\Task\Handlers;

use AndyDefer\ConsoleWriter\Console\Contracts\ConsoleInterface;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Enums\LogLevel;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Logger\Records\LogRecord;

/**
 * Centralizes console output and logging for task directives.
 *
 * Handles both muted and verbose modes. When muted, all console output
 * is suppressed and only logs are written.
 */
final class OutputHandler
{
    private ConsoleInterface $console;

    private LoggerInterface $logger;

    private bool $isMuted;

    private bool $isVerbose;

    public function __construct(
        ConsoleInterface $console,
        LoggerInterface $logger,
        bool $isMuted = false,
        bool $isVerbose = false
    ) {
        $this->console = $console;
        $this->logger = $logger;
        $this->isMuted = $isMuted;
        $this->isVerbose = $isVerbose;
    }

    public function info(string $message, array $payload = []): self
    {
        if (! $this->isMuted) {
            $this->console->info($message);
        }
        $this->log(LogLevel::INFO, $message, $payload);

        return $this;
    }

    public function success(string $message, array $payload = []): self
    {
        if (! $this->isMuted) {
            $this->console->success($message);
        }
        $this->log(LogLevel::INFO, $message, $payload);

        return $this;
    }

    public function error(string $message, array $payload = []): self
    {
        if (! $this->isMuted) {
            $this->console->error($message);
        }
        $this->log(LogLevel::ERROR, $message, $payload);

        return $this;
    }

    public function warning(string $message, array $payload = []): self
    {
        if (! $this->isMuted) {
            $this->console->alertWarning($message);
        }
        $this->log(LogLevel::WARNING, $message, $payload);

        return $this;
    }

    public function debug(string $message, array $payload = []): self
    {
        if ($this->isVerbose && ! $this->isMuted) {
            $this->console->logDebug($message);
        }
        $this->log(LogLevel::DEBUG, $message, $payload);

        return $this;
    }

    public function title(string $message, array $payload = []): self
    {
        if (! $this->isMuted) {
            $this->console->title($message);
        }
        $this->log(LogLevel::INFO, $message, $payload);

        return $this;
    }

    public function line(string $message = ''): self
    {
        if (! $this->isMuted) {
            $this->console->line($message);
        }

        if ($message !== '') {
            $this->log(LogLevel::DEBUG, $message);
        }

        return $this;
    }

    public function raw(string $line): self
    {
        if (! $this->isMuted) {
            $this->console->raw($line);
        }
        $this->log(LogLevel::DEBUG, $line);

        return $this;
    }

    public function keyValue(array|object $data, string $valueColor = 'green'): self
    {
        if (! $this->isMuted) {
            $this->console->keyValueWithValueColor($data, $valueColor);
        }
        $this->log(LogLevel::DEBUG, 'KeyValue: '.json_encode($data));

        return $this;
    }

    public function json(array|string $data, int $maxDepth = 3): self
    {
        if (! $this->isMuted) {
            $this->console->json($data);
        }

        if (is_array($data)) {
            $this->log(LogLevel::DEBUG, 'JSON: '.json_encode($data));
        }

        return $this;
    }

    public function alert(string $message, string $type = 'info', array $payload = []): self
    {
        if (! $this->isMuted) {
            match ($type) {
                'success' => $this->console->alertSuccess($message),
                'error' => $this->console->alertError($message),
                'warning' => $this->console->alertWarning($message),
                default => $this->console->alertInfo($message),
            };
        }

        $this->log(LogLevel::INFO, "[{$type}] {$message}", $payload);

        return $this;
    }

    /**
     * Display remaining tasks count.
     */
    public function remainingTasks(int $uniquePending, int $recurringPlaying, int $recurringWaiting): self
    {
        if ($this->isMuted) {
            return $this;
        }

        $this->line();
        $this->line('📊 Remaining tasks:');
        $this->line(sprintf('   🔵 Unique pending   : %d', $uniquePending));
        $this->line(sprintf('   ▶️  Recurring playing: %d', $recurringPlaying));
        $this->line(sprintf('   ⏳ Recurring waiting: %d', $recurringWaiting));
        $this->line(sprintf('   📦 Total remaining  : %d', $uniquePending + $recurringPlaying + $recurringWaiting));
        $this->line();

        return $this;
    }

    /**
     * Log a cycle summary with success/failed/total counts.
     */
    public function cycleSummary(int $cycleNumber, int $success, int $failed, int $total): self
    {
        $message = sprintf(
            'Cycle #%d | Success: %d | Failed: %d | Total: %d',
            $cycleNumber,
            $success,
            $failed,
            $total
        );

        if (! $this->isMuted) {
            $this->console->logDebug($message);
        }

        $this->log(LogLevel::DEBUG, $message);

        return $this;
    }

    /**
     * Log a detailed cycle summary with breakdown by task type in JSON format.
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
    ): self {
        if ($this->isMuted) {
            return $this;
        }

        $data = [
            'cycle' => $cycleNumber,
            'total' => [
                'success' => $totalSuccess,
                'failed' => $totalFailed,
                'errors' => $totalErrors,
            ],
            'unique' => [
                'success' => $uniqueSuccess,
                'failed' => $uniqueFailed,
            ],
            'recurring' => [
                'success' => $recurringSuccess,
                'failed' => $recurringFailed,
            ],
        ];

        $this->console->line(sprintf('Cycle #%d Details:', $cycleNumber));
        $this->json($data);

        $this->log(LogLevel::DEBUG, 'Cycle summary', $data);

        return $this;
    }

    /**
     * Display final summary with detailed breakdown in JSON format.
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
    ): self {
        if ($this->isMuted) {
            return $this;
        }

        $this->line();
        $this->title('Watch Summary');
        $this->line();

        $data = [
            'summary' => [
                'cycles' => $totalCycles,
                'total' => [
                    'success' => $totalSuccess,
                    'failed' => $totalFailed,
                    'errors' => $totalErrors,
                ],
                'unique' => [
                    'success' => $uniqueSuccess,
                    'failed' => $uniqueFailed,
                ],
                'recurring' => [
                    'success' => $recurringSuccess,
                    'failed' => $recurringFailed,
                ],
                'duration' => [
                    'elapsed' => ceil($elapsedSeconds),
                    'planned' => $plannedDuration,
                ],
                'workers' => $workers,
                'stopped_by_signal' => $stoppedBySignal,
            ],
        ];

        $this->json($data);
        $this->line();

        $this->log(LogLevel::INFO, 'Final summary', $data);

        return $this;
    }

    public function log(LogLevel $level, string $message, array $payload = []): self
    {
        $logData = LogDataRecord::from([
            'type' => 'task',
            'payload' => array_merge(
                ['message' => $message],
                $payload
            ),
        ]);

        $record = LogRecord::from([
            'time' => date('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'data' => $logData,
        ]);

        $this->logger->log($record);

        return $this;
    }

    public function isMuted(): bool
    {
        return $this->isMuted;
    }

    public function isVerbose(): bool
    {
        return $this->isVerbose;
    }

    /**
     * Creates a child OutputHandler with the same configuration.
     */
    public function withContext(array $context): self
    {
        return new self(
            $this->console,
            $this->logger,
            $this->isMuted,
            $this->isVerbose
        );
    }
}
