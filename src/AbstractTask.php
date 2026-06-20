<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;

abstract class AbstractTask
{
    protected TaskContext $context;

    protected LoggerInterface $logger;

    protected HydrationService $hydration;

    final public function __construct(
        TaskContext $context,
        LoggerInterface $logger,
        HydrationService $hydration,
    ) {
        $this->context = $context;
        $this->logger = $logger;
        $this->hydration = $hydration;
    }

    abstract public function getConfig(): TaskConfigRecord;

    abstract protected function process(): void;

    protected function before(): void {}

    protected function after(bool $success, ?string $error = null): void {}

    final public function execute(TaskPayloadRecord $payload): void
    {
        $this->context->setPayload($payload);

        $logData = [
            'event' => 'task_started',
            'signature' => $this->context->getSignature()->value,
        ];

        if ($this->context->hasTaskId()) {
            $logData['task_id'] = $this->context->getTaskId()->value;
        }

        $this->logger->info(new LogDataRecord(
            type: 'task',
            payload: $this->hydration->hydrate(StrictDataObject::class, $logData)
        ));

        $this->before();

        try {
            $this->process();
            $this->after(true);

            $logData = [
                'event' => 'task_completed',
                'signature' => $this->context->getSignature()->value,
                'status' => 'success',
            ];

            if ($this->context->hasTaskId()) {
                $logData['task_id'] = $this->context->getTaskId()->value;
            }

            $this->logger->info(new LogDataRecord(
                type: 'task',
                payload: $this->hydration->hydrate(StrictDataObject::class, $logData)
            ));
        } catch (\Throwable $e) {
            $this->after(false, $e->getMessage());

            $logData = [
                'event' => 'task_failed',
                'signature' => $this->context->getSignature()->value,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];

            if ($this->context->hasTaskId()) {
                $logData['task_id'] = $this->context->getTaskId()->value;
            }

            $this->logger->error(new LogDataRecord(
                type: 'task',
                payload: $this->hydration->hydrate(StrictDataObject::class, $logData)
            ));

            throw $e;
        }
    }

    public function info(string $message): void
    {
        $this->logger->info(new LogDataRecord(
            type: 'task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'info',
                'message' => $message,
            ])
        ));
    }

    public function error(string $message): void
    {
        $this->logger->error(new LogDataRecord(
            type: 'task_output',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'error',
                'message' => $message,
            ])
        ));
    }
}
