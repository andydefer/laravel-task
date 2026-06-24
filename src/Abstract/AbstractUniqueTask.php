<?php

declare(strict_types=1);

namespace AndyDefer\Task\Abstract;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Contracts\Abstract\TaskInterface;
use AndyDefer\Task\ValueObjects\DescriptionVO;

abstract class AbstractUniqueTask implements TaskInterface
{
    protected UniqueTaskContext $context;

    protected LoggerInterface $logger;

    protected HydrationService $hydration;

    final public function __construct(
        UniqueTaskContext $context,
        LoggerInterface $logger,
        HydrationService $hydration,
    ) {
        $this->context = $context;
        $this->logger = $logger;
        $this->hydration = $hydration;
    }

    abstract protected function process(): void;

    protected function before(): void {}

    protected function after(bool $success, ?string $error = null): void {}

    final public function execute(StrictDataObject $payload): void
    {
        $this->context->setPayload($payload);

        $this->logger->info(new LogDataRecord(
            type: 'unique_task',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'task_started',
                'task_id' => $this->context->getTaskId()->value,
                'alias' => $this->context->getAlias()->value,
                'scheduled_at' => $this->context->getScheduledAt()->value,
            ])
        ));

        $this->before();

        try {
            $this->process();
            $this->after(true);

            $this->logger->info(new LogDataRecord(
                type: 'unique_task',
                payload: $this->hydration->hydrate(StrictDataObject::class, [
                    'event' => 'task_completed',
                    'task_id' => $this->context->getTaskId()->value,
                    'status' => 'success',
                ])
            ));
        } catch (\Throwable $e) {
            $this->after(false, $e->getMessage());

            $this->logger->error(new LogDataRecord(
                type: 'unique_task',
                payload: $this->hydration->hydrate(StrictDataObject::class, [
                    'event' => 'task_failed',
                    'task_id' => $this->context->getTaskId()->value,
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
            type: 'unique_task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'info',
                'message' => $message->getValue(),
            ])
        ));
    }

    public function error(DescriptionVO $message): void
    {
        $this->logger->error(new LogDataRecord(
            type: 'unique_task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'error',
                'message' => $message->getValue(),
            ])
        ));
    }
}
