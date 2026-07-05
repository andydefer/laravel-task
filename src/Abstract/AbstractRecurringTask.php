<?php

declare(strict_types=1);

namespace AndyDefer\Task\Abstract;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Abstract\TaskInterface;
use AndyDefer\Task\ValueObjects\DescriptionVO;

abstract class AbstractRecurringTask implements TaskInterface
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

    abstract protected function process(): void;

    protected function before(): void {}

    protected function after(bool $success, ?DescriptionVO $error = null): void {}

    final public function execute(StrictDataObject $payload): void
    {
        $this->context->setPayload($payload);

        $this->logger->info(new LogDataRecord(
            type: 'recurring_task',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'task_started',
                'alias' => $this->context->getAlias(),
                'interval_seconds' => $this->context->getIntervalSeconds(),
                'next_run_at' => $this->context->getNextRunAt(),
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
                    'alias' => $this->context->getAlias()->getValue(),
                    'status' => 'success',
                ])
            ));
        } catch (\Throwable $e) {
            $this->after(false, DescriptionVO::from($e->getMessage()));

            $this->logger->error(new LogDataRecord(
                type: 'recurring_task',
                payload: $this->hydration->hydrate(StrictDataObject::class, [
                    'event' => 'task_failed',
                    'alias' => $this->context->getAlias()->getValue(),
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ])
            ));

            throw $e;
        }
    }

    public function info(DescriptionVO $message): void
    {
        $this->logger->info(new LogDataRecord(
            type: 'recurring_task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'info',
                'message' => $message->getValue(),
            ])
        ));
    }

    public function error(DescriptionVO $message): void
    {
        $this->logger->error(new LogDataRecord(
            type: 'recurring_task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'error',
                'message' => $message->getValue(),
            ])
        ));
    }
}
