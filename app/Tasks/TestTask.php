<?php

declare(strict_types=1);

namespace {{ namespace }};

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Enums\TaskMode;

final class TestTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'TestTask',
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
        $this->info('Starting TestTask execution...');
    }

    protected function process(): void
    {
        // Your task logic here
        $this->info('Executing TestTask...');
        
        // Access payload if needed
        // $payload = $this->payload;
        
        // Add your business logic
    }

    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->info('TestTask completed successfully');
        } else {
            $this->error("TestTask failed: {$error}");
        }
    }
}