<?php

declare(strict_types=1);

namespace AndyDefer\Task\Utils;

use AndyDefer\ConsoleWriter\Console\Services\VirtualTerminalService;
use AndyDefer\Task\Handlers\SignalHandler;

/**
 * Manager for displaying a countdown in the console.
 *
 * Provides a clean interface for showing and updating a countdown
 * using VirtualTerminalService.
 */
final class CountdownManager
{
    private VirtualTerminalService $vt;

    private string $key;

    private int $currentSeconds;

    private int $totalSeconds;

    private bool $isActive;

    private ?SignalHandler $signalHandler;

    /**
     * @var callable(int):string|null
     */
    private $messageFormatter;

    /**
     * @param  VirtualTerminalService  $vt  The virtual terminal service
     * @param  string  $key  The VT key for the countdown line
     */
    public function __construct(VirtualTerminalService $vt, string $key = 'countdown')
    {
        $this->vt = $vt;
        $this->key = $key;
        $this->isActive = false;
        $this->currentSeconds = 0;
        $this->totalSeconds = 0;
        $this->signalHandler = null;
        $this->messageFormatter = null;
    }

    /**
     * Sets the signal handler for interruption detection.
     */
    public function setSignalHandler(?SignalHandler $signalHandler): self
    {
        $this->signalHandler = $signalHandler;

        return $this;
    }

    /**
     * Sets a custom message formatter.
     *
     * @param  callable(int):string  $formatter
     */
    public function setMessageFormatter(callable $formatter): self
    {
        $this->messageFormatter = $formatter;

        return $this;
    }

    /**
     * Starts the countdown.
     *
     * @param  int  $seconds  Total seconds to count down from
     */
    public function start(int $seconds): self
    {
        if ($seconds <= 0) {
            return $this;
        }

        $this->totalSeconds = $seconds;
        $this->currentSeconds = $seconds;
        $this->isActive = true;

        // Ajouter la ligne dans la VT
        $this->vt->add($this->key, $this->buildMessage($seconds));
        $this->vt->render();

        return $this;
    }

    /**
     * Waits for the countdown to finish.
     *
     * @return bool True if countdown completed normally, false if interrupted
     */
    public function wait(): bool
    {
        if (! $this->isActive) {
            return true;
        }

        $start = microtime(true);
        $elapsed = 0.0;

        while ($elapsed < $this->totalSeconds) {
            // Vérifier les signaux d'interruption
            if ($this->signalHandler !== null) {
                $this->signalHandler->dispatch();
                if ($this->signalHandler->shouldStop()) {
                    $this->stop();

                    return false;
                }
            }

            $remaining = (int) ($this->totalSeconds - $elapsed);

            // Mettre à jour le compte à rebours
            if ($remaining !== $this->currentSeconds && $remaining > 0) {
                $this->currentSeconds = $remaining;
                $this->vt->update($this->key, $this->buildMessage($remaining));
                $this->vt->render();
            }

            // Attendre 100ms avant de vérifier à nouveau
            $sleepTime = min(0.1, $this->totalSeconds - $elapsed);
            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000000));
            }

            $elapsed = microtime(true) - $start;
        }

        // Supprimer la ligne quand le compte à rebours est terminé
        $this->stop();

        return true;
    }

    /**
     * Stops the countdown and removes it from display.
     */
    public function stop(): void
    {
        if ($this->isActive && $this->vt->has($this->key)) {
            $this->vt->remove($this->key);
            $this->vt->render();
        }

        $this->isActive = false;
        $this->currentSeconds = 0;
        $this->totalSeconds = 0;
    }

    /**
     * Checks if the countdown is currently active.
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Gets the current remaining seconds.
     */
    public function getCurrentSeconds(): int
    {
        return $this->currentSeconds;
    }

    /**
     * Gets the total seconds.
     */
    public function getTotalSeconds(): int
    {
        return $this->totalSeconds;
    }

    /**
     * Builds the countdown message.
     */
    private function buildMessage(int $seconds): string
    {
        // Utiliser le formateur personnalisé s'il existe
        if ($this->messageFormatter !== null) {
            return ($this->messageFormatter)($seconds);
        }

        // Message par défaut
        $label = $seconds === 1
            ? 'Next execution in 1 second'
            : "Next execution in {$seconds} seconds";

        return "⏳ {$label}";
    }

    /**
     * Creates a countdown with a specific message format.
     *
     * @param  int  $seconds  Total seconds to count down from
     * @param  string  $format  Custom message format with %d placeholder
     * @param  VirtualTerminalService  $vt  The virtual terminal service
     * @param  string  $key  The VT key
     */
    public static function createWithFormat(
        int $seconds,
        string $format,
        VirtualTerminalService $vt,
        string $key = 'countdown'
    ): self {
        $manager = new self($vt, $key);

        $manager->setMessageFormatter(function (int $seconds) use ($format): string {
            $message = sprintf($format, $seconds);
            // Gérer le singulier/pluriel
            if ($seconds === 1) {
                $message = str_replace('seconds', 'second', $message);
            }

            return $message;
        });

        return $manager;
    }
}
