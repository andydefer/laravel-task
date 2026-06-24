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

final class TaskExecutionDebugService implements TaskExecutionDebugServiceInterface
{
    public function __construct(
        private readonly TaskExecutionDebugRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {}

    public function findByAlias(TaskAliasVO $alias): TaskExecutionDebugRecordCollection
    {
        try {
            $models = $this->repository->findByAlias($alias);

            $collection = new TaskExecutionDebugRecordCollection;
            foreach ($models as $model) {
                $collection->add($this->repository->modelToRecord($model));
            }

            return $collection;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'task_debug_find_by_alias',
                'payload' => [
                    'alias' => $alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return new TaskExecutionDebugRecordCollection;
        }
    }

    public function findByFqcn(TaskFqcnVO $fqcn): TaskExecutionDebugRecordCollection
    {
        try {
            $models = $this->repository->findByFqcn($fqcn);

            $collection = new TaskExecutionDebugRecordCollection;
            foreach ($models as $model) {
                $collection->add($this->repository->modelToRecord($model));
            }

            return $collection;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'task_debug_find_by_fqcn',
                'payload' => [
                    'fqcn' => $fqcn->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return new TaskExecutionDebugRecordCollection;
        }
    }

    public function findByRecurringTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection
    {
        return $this->findByAlias($alias);
    }

    public function findByUniqueTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection
    {
        return $this->findByAlias($alias);
    }

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
                $this->logger->debug(LogDataRecord::from([
                    'type' => 'task_debug_added',
                    'payload' => [
                        'alias' => $alias->getValue(),
                        'fqcn' => $fqcn->getValue(),
                        'status' => $status->value,
                        'info' => $info->getValue(),
                        'data' => $data->toArray(),
                    ],
                ]));
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'task_debug_add_error',
                'payload' => [
                    'alias' => $alias->getValue(),
                    'fqcn' => $fqcn->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function addDebugForRecurringTask(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool {
        return $this->addDebug($alias, $fqcn, $status, $info, $data);
    }

    public function addDebugForUniqueTask(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool {
        return $this->addDebug($alias, $fqcn, $status, $info, $data);
    }

    public function clearTaskDebug(TaskAliasVO $alias): bool
    {
        try {
            $this->repository->clearByAlias($alias);

            $this->logger->info(LogDataRecord::from([
                'type' => 'task_debug_cleared',
                'payload' => [
                    'alias' => $alias->getValue(),
                ],
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'task_debug_clear_error',
                'payload' => [
                    'alias' => $alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function clearTaskDebugByFqcn(TaskFqcnVO $fqcn): bool
    {
        try {
            $this->repository->clearByFqcn($fqcn);

            $this->logger->info(LogDataRecord::from([
                'type' => 'task_debug_cleared_by_fqcn',
                'payload' => [
                    'fqcn' => $fqcn->getValue(),
                ],
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'task_debug_clear_by_fqcn_error',
                'payload' => [
                    'fqcn' => $fqcn->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function countTaskDebug(TaskAliasVO $alias): CounterVO
    {
        try {
            return $this->repository->countByAlias($alias);
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'task_debug_count_error',
                'payload' => [
                    'alias' => $alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return new CounterVO(0);
        }
    }

    public function countTaskDebugByFqcn(TaskFqcnVO $fqcn): CounterVO
    {
        try {
            return $this->repository->countByFqcn($fqcn);
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'task_debug_count_by_fqcn_error',
                'payload' => [
                    'fqcn' => $fqcn->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return new CounterVO(0);
        }
    }

    public function hasDebug(TaskAliasVO $alias): bool
    {
        return $this->countTaskDebug($alias)->isPositive();
    }

    public function hasDebugByFqcn(TaskFqcnVO $fqcn): bool
    {
        return $this->countTaskDebugByFqcn($fqcn)->isPositive();
    }
}
