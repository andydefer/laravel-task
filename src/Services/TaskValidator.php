<?php

// src/Services/TaskValidator.php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use Carbon\Carbon;

class TaskValidator
{
    public function validateTaskClass(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        $instance = new $className;

        return $instance instanceof AbstractTask;
    }

    public function canRunTask(TaskRecord $task): bool
    {
        if (! $task->status->isPending()) {
            return false;
        }

        if ($task->attempts >= $task->maxAttempts) {
            return false;
        }

        $startAtTimestamp = strtotime($task->startAt);
        $now = $this->getCurrentTimestamp();

        if ($now < $startAtTimestamp) {
            return false;
        }

        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;

        // Si la tâche exige un schedule exact, pas de période de grâce
        if ($task->enforceExactSchedule) {
            return $now <= $endAtTimestamp;
        }

        // Période de grâce pour les tâches uniques (delaySeconds = 0)
        if ($task->delaySeconds === 0 && $this->isGracePeriodEnabled()) {
            return $now <= $endAtTimestamp + $this->getGracePeriodSeconds();
        }

        // Comportement normal pour les tâches récurrentes
        return $now <= $endAtTimestamp;
    }

    public function isTaskExpired(TaskRecord $task): bool
    {
        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;
        $now = $this->getCurrentTimestamp();

        // Si la tâche exige un schedule exact
        if ($task->enforceExactSchedule) {
            return $now > $endAtTimestamp;
        }

        // Période de grâce pour les tâches uniques
        if ($task->delaySeconds === 0 && $this->isGracePeriodEnabled()) {
            return $now > $endAtTimestamp + $this->getGracePeriodSeconds();
        }

        return $now > $endAtTimestamp;
    }

    public function shouldRunRecurringNow(RecurringTaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $startAt = strtotime($task->startAt);
        $endAt = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;
        $nextRunAt = strtotime($task->nextRunAt);

        if ($now < $startAt || $now > $endAt || $now < $nextRunAt) {
            return false;
        }

        return true;
    }

    public function shouldRunTaskNow(TaskRecord $task): bool
    {
        $now = $this->getCurrentTimestamp();
        $startAt = strtotime($task->startAt);
        $endAt = $task->endAt ? strtotime($task->endAt) : PHP_INT_MAX;

        if ($now < $startAt || $now > $endAt) {
            return false;
        }

        if (! $task->status->isPending()) {
            return false;
        }

        if ($task->attempts >= $task->maxAttempts) {
            return false;
        }

        return true;
    }

    public function getDelaySecondsForTask(TaskRecord $task): int
    {
        return $task->delaySeconds;
    }

    public function getGracePeriodDelay(TaskRecord $task): int
    {
        if (! $this->isUniqueTaskWithGracePeriod($task)) {
            return 0;
        }

        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : $this->getCurrentTimestamp();
        $now = $this->getCurrentTimestamp();

        return max(0, $now - $endAtTimestamp);
    }

    public function isUniqueTaskWithGracePeriod(TaskRecord $task): bool
    {
        return $task->delaySeconds === 0
            && $this->isGracePeriodEnabled()
            && ! $task->enforceExactSchedule;
    }

    /**
     * Retourne le timestamp actuel (simulé par Carbon si disponible)
     */
    private function getCurrentTimestamp(): int
    {
        // Vérifier si Carbon a simulé le temps
        $carbonNow = Carbon::getTestNow();
        if ($carbonNow) {
            return $carbonNow->timestamp;
        }

        return time();
    }

    /**
     * Vérifie si la période de grâce est activée
     */
    private function isGracePeriodEnabled(): bool
    {
        return config('task.grace_period.enabled', true);
    }

    /**
     * Retourne la durée de la période de grâce en secondes
     */
    private function getGracePeriodSeconds(): int
    {
        return config('task.grace_period.seconds', 86400);
    }
}
