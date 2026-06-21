<?php

declare(strict_types=1);

namespace AndyDefer\Task\Abstract;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Abstract\RecurringTaskInterface;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;

abstract class RecurringTask implements RecurringTaskInterface
{
    protected RecurringTaskContext $context;

    protected LoggerInterface $logger;

    protected HydrationService $hydration;

    final public function __construct(
        RecurringTaskContext $context,
        LoggerInterface $logger,
        HydrationService $hydration,
    ) {
        $this->context = $context;
        $this->logger = $logger;
        $this->hydration = $hydration;
    }

    abstract public function getConfig(): RecurringTaskConfigInterface;

    abstract protected function process(): void;

    protected function before(): void {}

    protected function after(bool $success, ?string $error = null): void {}

    final public function execute(StrictDataObject $payload): void
    {
        $this->context->setPayload($payload);

        $this->logger->info(new LogDataRecord(
            type: 'recurring_task',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'task_started',
                'alias' => $this->context->getAlias()->value,
                'interval_seconds' => $this->context->getIntervalSeconds()->value,
                'next_run_at' => $this->context->getNextRunAt()?->value,
            ])
        ));

        $this->before();

        try {
            $this->process();
            $this->after(true);

            $this->logger->info(new LogDataRecord(
                type: 'recurring_task',
                payload: $this->hydration->hydrate(StrictDataObject::class, [
                    'event' => 'task_completed',
                    'alias' => $this->context->getAlias()->value,
                    'status' => 'success',
                ])
            ));
        } catch (\Throwable $e) {
            $this->after(false, $e->getMessage());

            $this->logger->error(new LogDataRecord(
                type: 'recurring_task',
                payload: $this->hydration->hydrate(StrictDataObject::class, [
                    'event' => 'task_failed',
                    'alias' => $this->context->getAlias()->value,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ])
            ));

            throw $e;
        }
    }

    public function info(string $message): void
    {
        $this->logger->info(new LogDataRecord(
            type: 'recurring_task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'info',
                'message' => $message,
            ])
        ));
    }

    public function error(string $message): void
    {
        $this->logger->error(new LogDataRecord(
            type: 'recurring_task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'error',
                'message' => $message,
            ])
        ));
    }
}
