<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Support\Carbon;

final class FailingUniqueTaskForProcessor extends AbstractUniqueTask
{
    public function getConfig(): UniqueTaskConfigInterface
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('failing-unique-processor'),
            description: 'Task that always fails - for processor tests',
            scheduled_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            max_attempts: new MaxFailedAttemptsVO(3),
        );
    }

    protected function process(): void
    {
        throw new \RuntimeException('Test exception');
    }
}
