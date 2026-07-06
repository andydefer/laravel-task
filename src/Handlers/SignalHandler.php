<?php

declare(strict_types=1);

namespace AndyDefer\Task\Handlers;

use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Enums\SignalName;

/**
 * Handles POSIX signals for graceful shutdown of the watch loop.
 *
 * Installs signal handlers for SIGINT and SIGTERM to allow
 * the watch loop to stop gracefully and render appropriate
 * messages to the user.
 */
final class SignalHandler
{
    /**
     * @var bool Indicates whether a stop signal has been received
     */
    private bool $shouldStop = false;

    /**
     * @var array<int, SignalName> Mapping of signal numbers to their names
     */
    private const SIGNAL_MAP = [
        SIGINT => SignalName::SIGINT,
        SIGTERM => SignalName::SIGTERM,
    ];

    /**
     * Constructor for the signal handler.
     *
     * @param  WatchRendererInterface  $renderer  The renderer for output display
     */
    public function __construct(
        private readonly WatchRendererInterface $renderer
    ) {}

    /**
     * Installs signal handlers for SIGINT and SIGTERM.
     *
     * If pcntl extension is not available, this method does nothing.
     */
    public function install(): void
    {
        if (! $this->isPcntlAvailable()) {
            return;
        }

        $this->registerSignalHandler(SIGINT);
        $this->registerSignalHandler(SIGTERM);
    }

    /**
     * Dispatches any pending signals.
     *
     * Should be called periodically during the watch loop to process
     * any signals that have been received.
     */
    public function dispatch(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Checks if the watch loop should stop.
     *
     * @return bool True if a stop signal has been received
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Resets the stop flag to false.
     *
     * Useful for testing or restarting the watch loop after a stop.
     */
    public function reset(): void
    {
        $this->shouldStop = false;
    }

    /**
     * Checks if the pcntl extension is available.
     *
     * @return bool True if pcntl functions are available
     */
    private function isPcntlAvailable(): bool
    {
        return function_exists('pcntl_signal');
    }

    /**
     * Registers a signal handler for a specific signal.
     *
     * @param  int  $signal  The signal number to register
     */
    private function registerSignalHandler(int $signal): void
    {
        pcntl_signal($signal, function () use ($signal): void {
            $this->shouldStop = true;
            $this->renderer->renderInterruptSignal($this->getSignalName($signal));
        });
    }

    /**
     * Gets the SignalName enum for a signal number.
     *
     * @param  int  $signal  The signal number
     * @return SignalName The corresponding signal name enum
     */
    private function getSignalName(int $signal): SignalName
    {
        return self::SIGNAL_MAP[$signal] ?? SignalName::SIGTERM;
    }
}
