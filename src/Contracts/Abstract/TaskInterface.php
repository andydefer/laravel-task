<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Abstract;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\DescriptionVO;

interface TaskInterface
{
    public function execute(StrictDataObject $payload): void;

    public function info(DescriptionVO $message): void;

    public function error(DescriptionVO $message): void;
}
