<?php

declare(strict_types=1);

namespace AndyDefer\Task\Validators;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Enums\ExitCode;

final class OptionValidator
{
    private const MIN_INTERVAL_SECONDS = 3;

    public function validate(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?string $duration,
        ?string $interval,
        ?string $limit,
        Console $console
    ): ?ExitCode {
        if ($uniqueOnly && $recurringOnly) {
            $console->error('Cannot use both --unique-only and --recurring-only');

            return ExitCode::INVALID_ARGUMENT;
        }

        if ($duration !== null && (int) $duration <= 0) {
            $console->error('Duration must be a positive integer (in seconds)');

            return ExitCode::INVALID_ARGUMENT;
        }

        if ($interval !== null && (int) $interval < self::MIN_INTERVAL_SECONDS) {
            $console->error(sprintf(
                'Interval must be at least %d seconds',
                self::MIN_INTERVAL_SECONDS
            ));

            return ExitCode::INVALID_ARGUMENT;
        }

        if ($limit !== null && (int) $limit <= 0) {
            $console->error('Limit must be a positive integer');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }
}
