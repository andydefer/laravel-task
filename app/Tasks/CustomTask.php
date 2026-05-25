<?php

declare(strict_types=1);

namespace {{ namespace }};

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Enums\TaskMode;

final class CustomTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'CustomTask',
            description: '{{ description }}',
            delaySeconds: {{ delay_seconds }},
            maxAttempts: {{ max_attempts }},
            startAt: {{ start_at }},
            endAt: {{ end_at }},
        );
    }

    protected function before(): void
    {
        // Code executed before the main process
        $this->info('Starting CustomTask execution...');
    }

    protected function process(): void
    {
        // Your task logic here
        $this->info('Executing CustomTask...');
        
        // Access payload if needed
        // $payload = $this->payload;
        
        // Add your business logic
    }

    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->info('CustomTask completed successfully');
        } else {
            $this->error("CustomTask failed: {$error}");
        }
    }
}