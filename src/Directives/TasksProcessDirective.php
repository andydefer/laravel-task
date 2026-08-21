<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Components\KeyValue;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\ConsoleWriter\Utils\ProgressManager;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\DomainStructures\Utils\MapCollection;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Helpers\ArrayHelper;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final class TasksProcessDirective extends AbstractDirective
{
    private Console $console;

    private bool $isVerbose;

    private bool $isMuted;

    private ?int $limit;

    private string $executionId;

    private ProgressManager $progress;

    private int $processedCount = 0;

    private int $totalTasks = 0;

    private int $currentTypeTotal = 0;

    private TaskType $currentType;

    public function getSignature(): string
    {
        return 'tasks:process 
                    {limit=infinite}#"Maximum number of tasks to process (infinite for no limit)" 
                    {--unique-only}#"Process only unique tasks" 
                    {--recurring-only}#"Process only recurring tasks" 
                    {--verbose}#"Show detailed output including errors" 
                    {--mute}#"Suppress all console output"';
    }

    public function getDescription(): string
    {
        return 'Process all pending tasks in a single batch (no polling, no waiting)';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['task-process', 'tp']);
    }

    public function execute(): ExitCode
    {
        try {
            $app = $this->getApplication();

            if ($app === null) {
                if (! $this->isMuted()) {
                    $this->console->error('Laravel container is not available');
                }

                return ExitCode::RUNTIME_ERROR;
            }

            $this->console = $app->make(Console::class);
            $this->isVerbose = $this->isFlagActive('verbose');
            $this->isMuted = $this->isFlagActive('mute');

            // Initialisation du ProgressManager
            $this->progress = new ProgressManager($this->console);

            // Validation centralisée du limit
            try {
                $this->limit = $this->validateAndGetLimit();
            } catch (InvalidArgumentException $e) {
                if (! $this->isMuted()) {
                    $this->console->error($e->getMessage());
                }

                return ExitCode::INVALID_ARGUMENT;
            }

            $this->executionId = Uuid::uuid4()->toString();

            // Validation des options
            $validationResult = $this->validateOptions();
            if ($validationResult !== ExitCode::SUCCESS) {
                return $validationResult;
            }

            $uniqueOnly = $this->isFlagActive('unique-only');
            $recurringOnly = $this->isFlagActive('recurring-only');

            if (! $this->isMuted()) {
                $this->renderStart();
            }

            // Calculer le nombre total de tâches pour la barre de progression
            $this->totalTasks = $this->calculateTotalTasks($uniqueOnly, $recurringOnly);

            // Démarrer la barre de progression si on a des tâches et qu'on est en verbose
            if ($this->isVerbose && ! $this->isMuted() && $this->totalTasks > 0) {
                $this->progress->start('Processing tasks', $this->totalTasks);
            }

            $hasFailures = match (true) {
                $uniqueOnly => $this->processTasks(TaskType::UNIQUE),
                $recurringOnly => $this->processTasks(TaskType::RECURRING),
                default => $this->processBothTypes(),
            };

            // S'assurer que la barre de progression est terminée
            if ($this->progress->isActive()) {
                $this->progress->finish('✅ Processing completed');
            }

            return $hasFailures ? ExitCode::FAILURE : ExitCode::SUCCESS;

        } catch (Throwable $e) {
            // S'assurer que la barre de progression est terminée en cas d'erreur
            if (isset($this->progress) && $this->progress->isActive()) {
                $this->progress->finish('❌ Error: '.$e->getMessage());
            }

            if (! $this->isMuted() && isset($this->console)) {
                $this->console->error('❌ Error processing tasks: '.$e->getMessage());
            }

            return ExitCode::RUNTIME_ERROR;
        }
    }

    // ==================== TASK PROCESSING ====================

    private function processTasks(TaskType $type): bool
    {
        $this->currentType = $type;
        $service = $this->getService($type);

        // Récupérer le nombre total de tâches à traiter pour ce type
        $this->currentTypeTotal = $this->getTotalTasks($type);

        // Récupérer le nombre total de tâches (pour la progression)
        $limit = $this->limit;
        if ($limit !== null && $limit < $this->currentTypeTotal) {
            $this->currentTypeTotal = $limit;
        }

        // Démarrer la barre de progression si on est en verbose et pas en mode silencieux
        if ($this->isVerbose && ! $this->isMuted() && $this->currentTypeTotal > 0) {
            $label = $this->getTaskTypeLabel($type).' tasks';
            if (! $this->progress->isActive()) {
                $this->progress->start($label, $this->currentTypeTotal);
            } else {
                $this->progress->finish('✅ '.$this->getTaskTypeLabel($type).' tasks');
                $this->progress->start($label, $this->currentTypeTotal);
            }
        }

        // Exécuter les tâches avec un LimitVO
        $result = $this->limit !== null
            ? $service->process(new LimitVO($this->limit))
            : $service->process();

        // Fin de la barre de progression pour ce type
        if ($this->isVerbose && ! $this->isMuted() && $this->progress->isActive()) {
            $this->progress->finish('✅ '.$this->getTaskTypeLabel($type).' tasks processed');
        }

        $label = $this->getTaskTypeLabel($type);

        if (! $this->isMuted()) {
            $this->renderResult($result, $label);
            $this->renderErrors($result->errors, $label);
        }

        $this->storeResult($this->executionId, $result, $type);

        return $result->failed->isPositive();
    }

    private function processBothTypes(): bool
    {
        // Récupérer le nombre total de tâches pour les deux types
        $totalUnique = $this->getTotalTasks(TaskType::UNIQUE);
        $totalRecurring = $this->getTotalTasks(TaskType::RECURRING);
        $this->totalTasks = $totalUnique + $totalRecurring;

        // Démarrer la barre de progression globale
        if ($this->isVerbose && ! $this->isMuted() && $this->totalTasks > 0) {
            $this->progress->start('Processing all tasks', $this->totalTasks);
        }

        if (! $this->isMuted()) {
            $this->console->info('Processing Unique tasks...');
        }

        $uniqueResult = $this->processTasksWithoutRendering(TaskType::UNIQUE);

        // Mettre à jour la progression après les tâches uniques
        if ($this->isVerbose && ! $this->isMuted() && $this->progress->isActive()) {
            $processed = $uniqueResult->success->add($uniqueResult->failed);
            $this->progress->update(
                $processed->getValue(),
                "Unique: {$processed->getValue()} tasks"
            );
        }

        if (! $this->isMuted()) {
            $this->console->info('Processing Recurring tasks...');
        }

        $recurringResult = $this->processTasksWithoutRendering(TaskType::RECURRING);

        // Mettre à jour la progression après les tâches récurrentes
        if ($this->isVerbose && ! $this->isMuted() && $this->progress->isActive()) {
            $totalProcessed = $uniqueResult->success->add($uniqueResult->failed)
                ->add($recurringResult->success->add($recurringResult->failed));
            $this->progress->update(
                $totalProcessed->getValue(),
                "All tasks: {$totalProcessed->getValue()} / {$this->totalTasks}"
            );
            $this->progress->finish('✅ All tasks processed');
        }

        $hasFailures = $uniqueResult->failed->isPositive() || $recurringResult->failed->isPositive();

        if (! $this->isMuted()) {
            $this->renderCombinedResults($uniqueResult, $recurringResult);
            $this->renderErrorsFromMultiple($uniqueResult->errors, $recurringResult->errors);
        }

        $this->storeFullResult($this->executionId, $uniqueResult, $recurringResult);

        return $hasFailures;
    }

    private function processTasksWithoutRendering(TaskType $type): ProcessResultRecord
    {
        $service = $this->getService($type);

        return $this->limit !== null
            ? $service->process(new LimitVO($this->limit))
            : $service->process();
    }

    private function getService(TaskType $type): UniqueTaskServiceInterface|RecurringTaskServiceInterface
    {
        $container = $this->getApplication();

        if ($container === null) {
            throw new RuntimeException('Laravel container is not available. Task processing requires Laravel.');
        }

        return match ($type) {
            TaskType::UNIQUE => $container->make(UniqueTaskServiceInterface::class),
            TaskType::RECURRING => $container->make(RecurringTaskServiceInterface::class),
        };
    }

    private function getTaskTypeLabel(TaskType $type): string
    {
        return match ($type) {
            TaskType::UNIQUE => 'Unique',
            TaskType::RECURRING => 'Recurring',
        };
    }

    /**
     * Récupère le nombre total de tâches à traiter pour un type donné.
     */
    private function getTotalTasks(TaskType $type): int
    {
        $service = $this->getService($type);

        return match ($type) {
            TaskType::UNIQUE => $service->countPending()->getValue(),
            TaskType::RECURRING => $service->countPlaying()->getValue(),
        };
    }

    /**
     * Calcule le nombre total de tâches à traiter.
     */
    private function calculateTotalTasks(bool $uniqueOnly, bool $recurringOnly): int
    {
        if ($uniqueOnly) {
            return $this->getTotalTasks(TaskType::UNIQUE);
        }

        if ($recurringOnly) {
            return $this->getTotalTasks(TaskType::RECURRING);
        }

        return $this->getTotalTasks(TaskType::UNIQUE) + $this->getTotalTasks(TaskType::RECURRING);
    }

    // ==================== VALIDATION ====================

    private function validateOptions(): ExitCode
    {
        $uniqueOnly = $this->isFlagActive('unique-only');
        $recurringOnly = $this->isFlagActive('recurring-only');

        if ($uniqueOnly && $recurringOnly) {
            if (! $this->isMuted()) {
                $this->console->error('Cannot use both --unique-only and --recurring-only');
            }

            return ExitCode::INVALID_ARGUMENT;
        }

        return ExitCode::SUCCESS;
    }

    private function validateAndGetLimit(): ?int
    {
        $limitRaw = $this->getArgument('limit');

        if ($limitRaw === null || $limitRaw === 'infinite' || $limitRaw === '0') {
            return null;
        }

        $limit = (int) $limitRaw;
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be a positive integer, "infinite", or 0 (no limit)');
        }

        return $limit;
    }

    private function isMuted(): bool
    {
        return $this->isMuted ?? false;
    }

    // ==================== RENDERING ====================

    private function renderStart(): void
    {
        $this->console->info('Processing tasks...');

        $data = MapCollection::from([
            'Limit' => $this->limit !== null ? $this->limit : 'infinite (no limit)',
        ]);

        $this->console->raw(KeyValue::renderWithValueColor($data, 'cyan'));
        $this->console->line();
    }

    private function renderResult(ProcessResultRecord $result, string $type): void
    {
        $total = $result->success->add($result->failed);

        $this->console->title("=== {$type} Batch Results ===");

        $data = MapCollection::from([
            '✅ Success' => $result->success,
            '❌ Failed' => $result->failed,
            '📦 Total' => $total,
        ]);

        $this->console->raw(KeyValue::renderWithValueColor($data, 'green'));
        $this->console->line();
    }

    private function renderCombinedResults(ProcessResultRecord $unique, ProcessResultRecord $recurring): void
    {
        $totalSuccess = $unique->success->add($recurring->success);
        $totalFailed = $unique->failed->add($recurring->failed);
        $totalProcessed = $totalSuccess->add($totalFailed);

        $this->console->title('=== Batch Results ===');

        $data = MapCollection::from([
            '✅ Unique Success' => $unique->success,
            '❌ Unique Failed' => $unique->failed,
            '✅ Recurring Success' => $recurring->success,
            '❌ Recurring Failed' => $recurring->failed,
            '📦 Total Success' => $totalSuccess,
            '📦 Total Failed' => $totalFailed,
            '📊 Total Processed' => $totalProcessed,
        ]);

        $this->console->raw(KeyValue::renderWithValueColor($data, 'green'));
        $this->console->line();
    }

    private function renderErrors(iterable $errors, string $type): void
    {
        if (! $this->isVerbose) {
            return;
        }

        $errorsArray = iterator_to_array($errors);

        if (empty($errorsArray)) {
            return;
        }

        $this->console->error("=== Failed {$type} Tasks ===");

        foreach ($errorsArray as $error) {
            $this->renderError($error);
        }
    }

    private function renderErrorsFromMultiple(iterable $uniqueErrors, iterable $recurringErrors): void
    {
        if (! $this->isVerbose) {
            return;
        }

        $uniqueErrorsArray = iterator_to_array($uniqueErrors);
        $recurringErrorsArray = iterator_to_array($recurringErrors);

        $hasUnique = ! empty($uniqueErrorsArray);
        $hasRecurring = ! empty($recurringErrorsArray);

        if (! $hasUnique && ! $hasRecurring) {
            return;
        }

        $this->console->error('=== Failed Tasks ===');

        if ($hasUnique) {
            $this->console->info('  Unique tasks:');
            foreach ($uniqueErrorsArray as $error) {
                $this->renderError($error);
            }
        }

        if ($hasRecurring) {
            $this->console->info('  Recurring tasks:');
            foreach ($recurringErrorsArray as $error) {
                $this->renderError($error);
            }
        }
    }

    private function renderError(mixed $error): void
    {
        $this->console->raw(KeyValue::renderWithValueColor(
            ArrayHelper::toPascalMap($error),
            'red'
        ));
        $this->console->line();
    }

    // ==================== CONTEXT STORAGE ====================

    private function storeResult(string $uuid, ProcessResultRecord $result, TaskType $type): void
    {
        $key = $type->value.'-'.$uuid;

        $endedAt = new Iso8601DateTimeVO;
        $durationMs = $result->started_at->diffInMilliseconds($result->ended_at);
        $total = $result->success->add($result->failed);

        $record = TaskExecutionResultRecord::from([
            'id' => $uuid,
            'started_at' => $result->started_at,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'success' => $result->success,
            'failed' => $result->failed,
            'total' => $total,
            'errors' => $result->errors,
            'has_failures' => $result->failed->isPositive(),
            'type' => $type,
        ]);

        $this->contextSet($key, $record);
    }

    private function storeFullResult(string $uuid, ProcessResultRecord $unique, ProcessResultRecord $recurring): void
    {
        $this->storeResult($uuid, $unique, TaskType::UNIQUE);
        $this->storeResult($uuid, $recurring, TaskType::RECURRING);
    }
}
