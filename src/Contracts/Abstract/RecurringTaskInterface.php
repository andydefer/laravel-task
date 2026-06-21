<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Abstract;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;

interface RecurringTaskInterface
{
    public function getConfig(): RecurringTaskConfigInterface;

    public function execute(StrictDataObject $payload): void;

    public function info(string $message): void;

    public function error(string $message): void;
}
