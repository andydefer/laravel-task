<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

final class RecurringTaskConfigVO extends AbstractValueObject
{
    public function __construct(
        public readonly TaskTypeVO $type,
        public readonly DescriptionVO $description,
        public readonly CounterVO $interval_seconds,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly MaxFailedAttemptsVO $max_attempts = new MaxFailedAttemptsVO(3),
    ) {}

    public function getType(): string
    {
        return $this->type->getValue();
    }

    public function getDescription(): string
    {
        return $this->description->getValue();
    }

    public function getIntervalSeconds(): CounterVO
    {
        return $this->interval_seconds;
    }

    public function getStartAt(): ?Iso8601DateTimeVO
    {
        return $this->start_at;
    }

    public function getEndAt(): ?Iso8601DateTimeVO
    {
        return $this->end_at;
    }

    public function getMaxAttempts(): MaxFailedAttemptsVO
    {
        return $this->max_attempts;
    }

    public function getValue(): StrictDataObject
    {
        return new StrictDataObject($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->getValue(),
            'description' => $this->description->getValue(),
            'interval_seconds' => $this->interval_seconds->value,
            'start_at' => $this->start_at?->value,
            'end_at' => $this->end_at?->value,
            'max_attempts' => $this->max_attempts->value,
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}
