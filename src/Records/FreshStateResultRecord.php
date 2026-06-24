<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\CounterVO;

final class FreshStateResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly CounterVO $waiting_to_playing,
        public readonly CounterVO $playing_to_finished,
        public readonly CounterVO $playing_to_canceled,
    ) {}
}
