<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Fixtures\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;

final class TestRecurringTaskForRepository extends AbstractRecurringTask
{
    protected function process(): void
    {
        // Ne fait rien, juste pour les tests de repository
    }
}
