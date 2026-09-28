<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Components\TableList;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\DomainStructures\Utils\ListCollection;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\ValueObjects\TaskAliasVO;

final class TasksSearchDirective extends AbstractDirective
{
    public function getSignature(): string
    {
        return 'tasks:search {aliases*}#"Task aliases to search"';
    }

    public function getDescription(): string
    {
        return 'Search unique and recurring tasks by alias';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from([
            'tasks:find',
            't:find',
        ]);
    }

    protected function beforeExecute(): void
    {
        $aliases = $this->getVariadic('aliases');

        if ($aliases === []) {
            throw new \InvalidArgumentException('At least one alias is required.');
        }
    }

    protected function execute(): ExitCode
    {
        $aliases = $this->getVariadic('aliases');

        $uniqueService = app(UniqueTaskServiceInterface::class);
        $recurringService = app(RecurringTaskServiceInterface::class);

        $rows = ListCollection::from([]);
        $notFound = [];

        foreach ($aliases as $alias) {
            $aliasVO = new TaskAliasVO($alias);

            $unique = $uniqueService->find($aliasVO);

            if ($unique !== null) {
                $rows = $rows->add(ListCollection::from([
                    'unique',
                    (string) $unique->alias->getValue(),
                    (string) $unique->fqcn->getValue(),
                    (string) ($unique->status?->value ?? '-'),
                    (string) ($unique->scheduled_at?->getValue() ?? '-'),
                    (string) ($unique->attempts?->getValue() ?? 0),
                ]));

                continue;
            }

            $recurring = $recurringService->find($aliasVO);

            if ($recurring !== null) {
                $rows = $rows->add(ListCollection::from([
                    'recurring',
                    (string) $recurring->alias->getValue(),
                    (string) $recurring->fqcn->getValue(),
                    (string) ($recurring->status?->value ?? '-'),
                    (string) ($recurring->last_run_at?->getValue() ?? $recurring->start_at?->getValue() ?? '-'),
                    (string) ($recurring->failed_attempts?->getValue() ?? 0),
                ]));

                continue;
            }

            $notFound[] = $alias;
        }

        if ($rows->isEmpty()) {
            $this->error('No matching task found.');

            foreach ($notFound as $alias) {
                $this->warn(sprintf('Task not found: %s', $alias));
            }

            return ExitCode::FAILURE;
        }

        $headers = ListCollection::from([
            'Kind',
            'Alias',
            'FQCN',
            'Status',
            'Next / Last run',
            'Attempts',
        ]);

        echo TableList::renderWithTitle($headers, $rows, '🔎 Tasks')."\n";

        if ($notFound !== []) {
            foreach ($notFound as $alias) {
                $this->warn(sprintf('Task not found: %s', $alias));
            }

            return ExitCode::FAILURE;
        }

        return ExitCode::SUCCESS;
    }
}
