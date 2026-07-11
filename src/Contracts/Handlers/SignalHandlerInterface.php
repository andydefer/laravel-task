<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Handlers;

interface SignalHandlerInterface
{
    /**
     * Install signal handlers for SIGINT and SIGTERM.
     */
    public function install(): void;

    /**
     * Dispatch any pending signals.
     */
    public function dispatch(): void;

    /**
     * Check if a stop signal has been received.
     */
    public function shouldStop(): bool;

    /**
     * Reset the stop flag to false.
     */
    public function reset(): void;
}
