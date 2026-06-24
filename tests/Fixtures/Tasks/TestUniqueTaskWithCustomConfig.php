<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class TestUniqueTaskWithCustomConfig extends AbstractUniqueTask
{
    public function getConfig(): UniqueTaskConfigInterface
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('test-unique-default'),
            description: 'Test unique task with default config',
            scheduled_at: new Iso8601DateTimeVO(now()->addHours(24)->toIso8601String()),
            max_attempts: new MaxFailedAttemptsVO(3),
        );
    }

    protected function process(): void
    {
        $this->info('Processing with custom config');
    }
}
