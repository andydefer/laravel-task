<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Services\TaskValidatorServiceInterface;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\UnixTimestampVO;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;

/**
 * Service for validating tasks and determining their executability.
 */
class TaskValidatorService implements TaskValidatorServiceInterface
{
    public function __construct(
        private readonly TaskConfigInterface $config,
        private readonly HydrationService $hydration,
        private readonly LoggerInterface $logger,
        private readonly Application $app,
    ) {}

    public function validateTaskClass(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        // Créer un contexte minimal pour l'instantiation
        $context = new TaskContext;
        $context->setLaravelApp($this->app);

        $instance = new $className($context, $this->logger, $this->hydration);

        return $instance instanceof AbstractTask;
    }

    public function canRunTask(TaskRecord $task): bool
    {
        if (! $task->status->isPending()) {
            return false;
        }

        if ($task->attempts->value >= $task->max_attempts->value) {
            return false;
        }

        $now = $this->getCurrentTimestamp();
        $start_at = new UnixTimestampVO(strtotime($task->start_at->value));
        $end_at = $task->end_at !== null
            ? new UnixTimestampVO(strtotime($task->end_at->value))
            : null;

        if ($now->isBefore($start_at)) {
            return false;
        }

        // Exact schedule enforcement - no grace period
        if ($task->enforce_exact_schedule) {
            return $end_at === null || $now->isBefore($end_at) || $now->equals($end_at);
        }

        // Grace period for unique tasks (delay_seconds === 0)
        if ($task->delay_seconds->value === 0 && $this->config->gracePeriodEnabled()) {
            $grace_end = $end_at !== null
                ? new UnixTimestampVO($end_at->value + $this->config->gracePeriodSeconds())
                : null;

            return $grace_end === null || $now->isBefore($grace_end) || $now->equals($grace_end);
        }

        // Normal behavior for recurring tasks
        return $end_at === null || $now->isBefore($end_at) || $now->equals($end_at);
    }

    public function isTaskExpired(TaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $end_at = $task->end_at !== null
            ? new UnixTimestampVO(strtotime($task->end_at->value))
            : null;

        if ($end_at === null) {
            return false;
        }

        // Exact schedule enforcement - no grace period
        if ($task->enforce_exact_schedule) {
            return $now->isAfter($end_at);
        }

        // Grace period for unique tasks
        if ($task->delay_seconds->value === 0 && $this->config->gracePeriodEnabled()) {
            $grace_end = new UnixTimestampVO($end_at->value + $this->config->gracePeriodSeconds());

            return $now->isAfter($grace_end);
        }

        return $now->isAfter($end_at);
    }

    public function shouldRunRecurringNow(RecurringTaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $start_at = new UnixTimestampVO(strtotime($task->start_at->value));
        $end_at = $task->end_at !== null
            ? new UnixTimestampVO(strtotime($task->end_at->value))
            : null;
        $next_run_at = new UnixTimestampVO(strtotime($task->next_run_at->value));

        if ($now->isBefore($start_at)) {
            return false;
        }

        if ($end_at !== null && $now->isAfter($end_at)) {
            return false;
        }

        if ($now->isBefore($next_run_at)) {
            return false;
        }

        return true;
    }

    public function shouldRunTaskNow(TaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $start_at = new UnixTimestampVO(strtotime($task->start_at->value));
        $end_at = $task->end_at !== null
            ? new UnixTimestampVO(strtotime($task->end_at->value))
            : null;

        if ($now->isBefore($start_at)) {
            return false;
        }

        if ($end_at !== null && $now->isAfter($end_at)) {
            return false;
        }

        if (! $task->status->isPending()) {
            return false;
        }

        if ($task->attempts->value >= $task->max_attempts->value) {
            return false;
        }

        return true;
    }

    public function getDelaySecondsForTask(TaskRecord $task): int
    {
        return $task->delay_seconds->value;
    }

    public function getGracePeriodDelay(TaskRecord $task): int
    {
        if (! $this->isUniqueTaskWithGracePeriod($task)) {
            return 0;
        }

        $end_at = $task->end_at !== null
            ? new UnixTimestampVO(strtotime($task->end_at->value))
            : $this->getCurrentTimestamp();

        $now = $this->getCurrentTimestamp();

        return max(0, $now->value - $end_at->value);
    }

    public function isUniqueTaskWithGracePeriod(TaskRecord $task): bool
    {
        return $task->delay_seconds->value === 0
            && $this->config->gracePeriodEnabled()
            && ! $task->enforce_exact_schedule;
    }

    private function getCurrentTimestamp(): UnixTimestampVO
    {
        $carbonNow = Carbon::getTestNow();
        if ($carbonNow) {
            return new UnixTimestampVO($carbonNow->timestamp);
        }

        return new UnixTimestampVO;
    }
}
