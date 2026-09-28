<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Components\TableList;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\DomainStructures\Utils\ListCollection;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;

final class TasksListDirective extends AbstractDirective
{
    public function getSignature(): string
    {
        return 'tasks:list
                {limit=50}#"Maximum number of tasks to display"
                {fqcns*}#"Filter by fully qualified class names (dots instead of backslashes)"
                {kinds*>[unique,recurring]}#"Task kinds to display"
                {unique_statuses*>[pending,completed,in_progress,failed,canceled]}#"Unique task statuses to include"
                {recurring_statuses*>[waiting,playing,paused,finished,canceled]}#"Recurring task statuses to include"';
    }

    public function getDescription(): string
    {
        return 'List persisted unique and recurring tasks';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from([
            'tasks:ls',
            't:ls',
        ]);
    }

    protected function beforeExecute(): void
    {
        $limit = (int) $this->getArgument('limit');

        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be at least 1.');
        }
    }

    protected function execute(): ExitCode
    {
        $limit = new LimitVO((int) $this->getArgument('limit'));
        $fqcns = $this->resolveFqcns($this->getVariadic('fqcns'));
        $kinds = $this->getVariadic('kinds');
        $uniqueStatuses = $this->resolveUniqueStatuses($this->getVariadic('unique_statuses'));
        $recurringStatuses = $this->resolveRecurringStatuses($this->getVariadic('recurring_statuses'));

        if ($kinds === []) {
            $kinds = ['unique', 'recurring'];
        }

        $rows = ListCollection::from([]);

        if (in_array('unique', $kinds, true)) {
            $rows = $this->appendUniqueRows($rows, $limit, $uniqueStatuses, $fqcns);
        }

        if (in_array('recurring', $kinds, true)) {
            $rows = $this->appendRecurringRows($rows, $limit, $recurringStatuses, $fqcns);
        }

        $headers = ListCollection::from([
            'Kind',
            'Alias',
            'FQCN',
            'Status',
            'Next / Last run',
            'Attempts',
        ]);

        echo TableList::renderWithTitle($headers, $rows, '🗂️ Tasks')."\n";

        return ExitCode::SUCCESS;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, UniqueTaskStatus>
     */
    private function resolveUniqueStatuses(array $names): array
    {
        if ($names === []) {
            return UniqueTaskStatus::cases();
        }

        return array_map(
            static fn (string $name): UniqueTaskStatus => UniqueTaskStatus::from($name),
            $names,
        );
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, RecurringTaskStatus>
     */
    private function resolveRecurringStatuses(array $names): array
    {
        if ($names === []) {
            return RecurringTaskStatus::cases();
        }

        return array_map(
            static fn (string $name): RecurringTaskStatus => RecurringTaskStatus::from($name),
            $names,
        );
    }

    /**
     * @param  array<int, string>  $rawFqcns
     */
    private function resolveFqcns(array $rawFqcns): TaskFqcnVOCollection
    {
        $collection = new TaskFqcnVOCollection;

        foreach ($rawFqcns as $fqcn) {
            $collection->add(new UniqueTaskFqcnVO(
                str_replace('.', '\\', $fqcn)
            ));
        }

        return $collection;
    }

    /**
     * @param  array<int, UniqueTaskStatus>  $statuses
     */
    private function appendUniqueRows(
        ListCollection $rows,
        LimitVO $limit,
        array $statuses,
        TaskFqcnVOCollection $fqcns,
    ): ListCollection {
        /** @var UniqueTaskServiceInterface $service */
        $service = app(UniqueTaskServiceInterface::class);

        foreach ($statuses as $status) {
            $tasks = match ($status) {
                UniqueTaskStatus::PENDING => $service->findPending($limit),
                UniqueTaskStatus::COMPLETED => $service->findCompleted($limit),
                UniqueTaskStatus::IN_PROGRESS => $service->findPending($limit),
                UniqueTaskStatus::FAILED => $service->findFailed($limit),
                UniqueTaskStatus::CANCELED => $service->findCanceled($limit),
            };

            foreach ($tasks as $task) {
                if (! $fqcns->isEmpty() && ! $this->matchFqcn($task->fqcn->getValue(), $fqcns)) {
                    continue;
                }

                $rows = $rows->add(ListCollection::from([
                    'unique',
                    (string) $task->alias->getValue(),
                    (string) $task->fqcn->getValue(),
                    $status->value,
                    (string) ($task->scheduled_at?->getValue() ?? '-'),
                    (string) ($task->attempts?->getValue() ?? 0),
                ]));
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, RecurringTaskStatus>  $statuses
     */
    private function appendRecurringRows(
        ListCollection $rows,
        LimitVO $limit,
        array $statuses,
        TaskFqcnVOCollection $fqcns,
    ): ListCollection {
        /** @var RecurringTaskServiceInterface $service */
        $service = app(RecurringTaskServiceInterface::class);

        foreach ($statuses as $status) {
            $tasks = match ($status) {
                RecurringTaskStatus::WAITING => $service->findWaiting($limit),
                RecurringTaskStatus::PLAYING => $service->findPlaying($limit),
                RecurringTaskStatus::PAUSED => $service->findPaused($limit),
                RecurringTaskStatus::FINISHED => $service->findFinished($limit),
                RecurringTaskStatus::CANCELED => $service->findCanceled($limit),
            };

            foreach ($tasks as $task) {
                if (! $fqcns->isEmpty() && ! $this->matchFqcn($task->fqcn->getValue(), $fqcns)) {
                    continue;
                }

                $rows = $rows->add(ListCollection::from([
                    'recurring',
                    (string) $task->alias->getValue(),
                    (string) $task->fqcn->getValue(),
                    $status->value,
                    (string) ($task->last_run_at?->getValue() ?? $task->start_at?->getValue() ?? '-'),
                    (string) ($task->failed_attempts?->getValue() ?? 0),
                ]));
            }
        }

        return $rows;
    }

    private function matchFqcn(string $fqcn, TaskFqcnVOCollection $fqcns): bool
    {
        foreach ($fqcns as $candidate) {
            if ($candidate->getValue() === $fqcn) {
                return true;
            }
        }

        return false;
    }
}
