<?php

declare(strict_types=1);

namespace AndyDefer\Task\Handlers;

use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Enums\SignalName;

final class SignalHandler
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly WatchRendererInterface $renderer
    ) {}

    public function install(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGINT, function () {
            $this->shouldStop = true;
            $this->renderer->renderInterruptSignal($this->getSignalName(SIGINT));
        });

        pcntl_signal(SIGTERM, function () {
            $this->shouldStop = true;
            $this->renderer->renderInterruptSignal($this->getSignalName(SIGTERM));
        });
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

    private function getSignalName(int $signal): SignalName
    {
        return SignalName::fromNumber($signal) ?? SignalName::SIGTERM;
    }
}
