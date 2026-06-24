<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

final class UniqueTaskConfigVO extends AbstractValueObject
{
    public function __construct(
        public readonly TaskTypeVO $type,
        public readonly DescriptionVO $description,
        public readonly Iso8601DateTimeVO $scheduled_at,
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

    public function getScheduledAt(): Iso8601DateTimeVO
    {
        return $this->scheduled_at;
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
            'scheduled_at' => $this->scheduled_at->value,
            'max_attempts' => $this->max_attempts->value,
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}
