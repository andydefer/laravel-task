<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Collections\TaskExecutionDebugRecordCollection;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Contracts\Services\TaskExecutionDebugServiceInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use Throwable;

/**
 * Service for managing task execution debug information.
 *
 * Provides CRUD operations for task debug records including retrieval
 * by alias or FQCN, adding debug entries, clearing records, and counting.
 */
final class TaskExecutionDebugService implements TaskExecutionDebugServiceInterface
{
    private const LOG_PREFIX = 'task_debug';

    /**
     * Constructor for the task execution debug service.
     *
     * @param  TaskExecutionDebugRepositoryInterface  $repository  The debug repository
     * @param  LoggerInterface  $logger  The logger instance
     */
    public function __construct(
        private readonly TaskExecutionDebugRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findByAlias(TaskAliasVO $alias): TaskExecutionDebugRecordCollection
    {
        try {
            $models = $this->repository->findByAlias($alias);

            return $this->convertModelsToCollection($models);
        } catch (Throwable $e) {
            $this->logError('find_by_alias', ['alias' => $alias->getValue(), 'error' => $e->getMessage()]);

            return new TaskExecutionDebugRecordCollection;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findByFqcn(TaskFqcnVO $fqcn): TaskExecutionDebugRecordCollection
    {
        try {
            $models = $this->repository->findByFqcn($fqcn);

            return $this->convertModelsToCollection($models);
        } catch (Throwable $e) {
            $this->logError('find_by_fqcn', ['fqcn' => $fqcn->getValue(), 'error' => $e->getMessage()]);

            return new TaskExecutionDebugRecordCollection;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findByRecurringTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection
    {
        return $this->findByAlias($alias);
    }

    /**
     * {@inheritDoc}
     */
    public function findByUniqueTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection
    {
        return $this->findByAlias($alias);
    }

    /**
     * {@inheritDoc}
     */
    public function addDebug(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool {
        try {
            $this->repository->addDebug($alias, $fqcn, $status, $info);

            if ($data !== null) {
                $this->logger->debug($this->createLogRecord('added', [
                    'alias' => $alias->getValue(),
                    'fqcn' => $fqcn->getValue(),
                    'status' => $status->value,
                    'info' => $info->getValue(),
                    'data' => $data->toArray(),
                ]));
            }

            return true;
        } catch (Throwable $e) {
            $this->logError('add_error', [
                'alias' => $alias->getValue(),
                'fqcn' => $fqcn->getValue(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addDebugForRecurringTask(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool {
        return $this->addDebug($alias, $fqcn, $status, $info, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function addDebugForUniqueTask(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool {
        return $this->addDebug($alias, $fqcn, $status, $info, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function clearTaskDebug(TaskAliasVO $alias): bool
    {
        try {
            $this->repository->clearByAlias($alias);

            $this->logger->info($this->createLogRecord('cleared', [
                'alias' => $alias->getValue(),
            ]));

            return true;
        } catch (Throwable $e) {
            $this->logError('clear_error', [
                'alias' => $alias->getValue(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clearTaskDebugByFqcn(TaskFqcnVO $fqcn): bool
    {
        try {
            $this->repository->clearByFqcn($fqcn);

            $this->logger->info($this->createLogRecord('cleared_by_fqcn', [
                'fqcn' => $fqcn->getValue(),
            ]));

            return true;
        } catch (Throwable $e) {
            $this->logError('clear_by_fqcn_error', [
                'fqcn' => $fqcn->getValue(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function countTaskDebug(TaskAliasVO $alias): CounterVO
    {
        try {
            return $this->repository->countByAlias($alias);
        } catch (Throwable $e) {
            $this->logError('count_error', [
                'alias' => $alias->getValue(),
                'error' => $e->getMessage(),
            ]);

            return new CounterVO(0);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function countTaskDebugByFqcn(TaskFqcnVO $fqcn): CounterVO
    {
        try {
            return $this->repository->countByFqcn($fqcn);
        } catch (Throwable $e) {
            $this->logError('count_by_fqcn_error', [
                'fqcn' => $fqcn->getValue(),
                'error' => $e->getMessage(),
            ]);

            return new CounterVO(0);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hasDebug(TaskAliasVO $alias): bool
    {
        return $this->countTaskDebug($alias)->isPositive();
    }

    /**
     * {@inheritDoc}
     */
    public function hasDebugByFqcn(TaskFqcnVO $fqcn): bool
    {
        return $this->countTaskDebugByFqcn($fqcn)->isPositive();
    }

    /**
     * Converts a collection of models to a record collection.
     *
     * @param  iterable  $models  The models to convert
     * @return TaskExecutionDebugRecordCollection The converted collection
     */
    private function convertModelsToCollection(iterable $models): TaskExecutionDebugRecordCollection
    {
        $collection = new TaskExecutionDebugRecordCollection;

        foreach ($models as $model) {
            $collection->add($this->repository->modelToRecord($model));
        }

        return $collection;
    }

    /**
     * Creates a log record for the task debug service.
     *
     * @param  string  $event  The log event type
     * @param  array<string, mixed>  $payload  The log payload
     * @return LogDataRecord The created log record
     */
    private function createLogRecord(string $event, array $payload): LogDataRecord
    {
        return LogDataRecord::from([
            'type' => self::LOG_PREFIX.'_'.$event,
            'payload' => $payload,
        ]);
    }

    /**
     * Logs an error for the task debug service.
     *
     * @param  string  $event  The error event type
     * @param  array<string, mixed>  $payload  The error payload
     */
    private function logError(string $event, array $payload): void
    {
        $this->logger->error($this->createLogRecord($event, $payload));
    }
}
