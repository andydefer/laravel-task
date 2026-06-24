<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Support\Carbon;

final class TestRecurringTaskForRepository extends AbstractRecurringTask
{
    public function getConfig(): RecurringTaskConfigInterface
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('test-recurring-repository'),
            description: 'Test recurring task for repository tests',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(7)->toIso8601String()),
            max_attempts: new MaxFailedAttemptsVO(3),
        );
    }

    protected function process(): void
    {
        // Ne fait rien, juste pour les tests de repository
    }
}
