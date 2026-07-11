<?php

declare(strict_types=1);

namespace AndyDefer\Task\Handlers;

use AndyDefer\ConsoleWriter\Console\Contracts\ConsoleInterface;
use AndyDefer\Task\Contracts\Handlers\SignalHandlerInterface;
use AndyDefer\Task\Enums\SignalName;

final class SignalHandler implements SignalHandlerInterface
{
    private bool $shouldStop = false;

    private const SIGNAL_MAP = [
        SIGINT => SignalName::SIGINT,
        SIGTERM => SignalName::SIGTERM,
    ];

    public function __construct(
        private readonly ConsoleInterface $console
    ) {}

    public function install(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $this->registerSignalHandler(SIGINT);
        $this->registerSignalHandler(SIGTERM);
    }

    public function dispatch(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    public function reset(): void
    {
        $this->shouldStop = false;
    }

    private function registerSignalHandler(int $signal): void
    {
        pcntl_signal($signal, function () use ($signal): void {
            $this->shouldStop = true;
            $this->renderInterruptSignal($this->getSignalName($signal));
        });
    }

    private function renderInterruptSignal(SignalName $signal): void
    {
        $this->console->alertWarning(sprintf(
            '🛑 Received %s signal, stopping gracefully...',
            $signal->value
        ));
    }

    private function getSignalName(int $signal): SignalName
    {
        return self::SIGNAL_MAP[$signal] ?? SignalName::SIGTERM;
    }
}
