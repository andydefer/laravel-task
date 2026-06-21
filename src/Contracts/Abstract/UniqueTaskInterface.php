<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Abstract;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;

interface UniqueTaskInterface
{
    public function getConfig(): UniqueTaskConfigInterface;

    public function execute(StrictDataObject $payload): void;

    public function info(string $message): void;

    public function error(string $message): void;
}
