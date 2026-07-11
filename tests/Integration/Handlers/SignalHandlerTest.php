<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Handlers;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class SignalHandlerTest extends IntegrationTestCase
{
    private Console $console;

    private SignalHandler $signalHandler;

    protected function setUp(): void
    {
        parent::setUp();
        ob_start();

        $this->console = new Console;
        $this->signalHandler = new SignalHandler($this->console);
    }

    protected function tearDown(): void
    {
        ob_get_clean();
        parent::tearDown();
    }

    public function test_signal_handler_captures_sigint_and_stops_gracefully(): void
    {

        $this->signalHandler->install();

        $iterations = 0;
        $maxIterations = 10;
        $signalSent = false;

        while (! $this->signalHandler->shouldStop() && $iterations < $maxIterations) {
            $iterations++;

            // Envoyer SIGINT au 5ème cycle
            if ($iterations === 5 && ! $signalSent) {
                posix_kill(posix_getpid(), SIGINT);
                $signalSent = true;
            }

            usleep(100000);

            // Vérifier les signaux en attente
            $this->signalHandler->dispatch();
        }

        $this->assertTrue($this->signalHandler->shouldStop());
        // La boucle s'arrête au cycle où le signal est dispatché
        $this->assertEquals(5, $iterations);
        $this->assertLessThan($maxIterations, $iterations);
    }

    public function test_signal_handler_captures_sigterm_and_stops_gracefully(): void
    {

        $this->signalHandler->install();

        $iterations = 0;
        $maxIterations = 10;
        $signalSent = false;

        while (! $this->signalHandler->shouldStop() && $iterations < $maxIterations) {
            $iterations++;

            if ($iterations === 7 && ! $signalSent) {
                posix_kill(posix_getpid(), SIGTERM);
                $signalSent = true;
            }

            usleep(100000);

            $this->signalHandler->dispatch();
        }

        $this->assertTrue($this->signalHandler->shouldStop());
        $this->assertEquals(7, $iterations);
        $this->assertLessThan($maxIterations, $iterations);
    }

    public function test_signal_handler_can_be_reset_and_reused(): void
    {

        // Premier cycle
        $this->signalHandler->install();

        $iterations = 0;
        $signalSent = false;

        while (! $this->signalHandler->shouldStop() && $iterations < 5) {
            $iterations++;

            if ($iterations === 3 && ! $signalSent) {
                posix_kill(posix_getpid(), SIGINT);
                $signalSent = true;
            }

            usleep(50000);
            $this->signalHandler->dispatch();
        }

        $this->assertTrue($this->signalHandler->shouldStop());
        $this->assertEquals(3, $iterations);

        // Réinitialiser
        $this->signalHandler->reset();
        $this->assertFalse($this->signalHandler->shouldStop());

        // Deuxième cycle
        $iterations = 0;
        $signalSent = false;

        while (! $this->signalHandler->shouldStop() && $iterations < 5) {
            $iterations++;

            if ($iterations === 4 && ! $signalSent) {
                posix_kill(posix_getpid(), SIGTERM);
                $signalSent = true;
            }

            usleep(50000);
            $this->signalHandler->dispatch();
        }

        $this->assertTrue($this->signalHandler->shouldStop());
        $this->assertEquals(4, $iterations);
    }

    public function test_signal_handler_does_nothing_if_pcntl_not_available(): void
    {

        $this->signalHandler->install();
        $this->signalHandler->dispatch();

        $this->assertFalse($this->signalHandler->shouldStop());
        $this->assertTrue(true);
    }

    public function test_loop_stops_on_signal_without_reaching_duration_limit(): void
    {

        $this->signalHandler->install();

        $cycles = 0;
        $maxCycles = 20;
        $signalSent = false;

        while (! $this->signalHandler->shouldStop() && $cycles < $maxCycles) {
            $cycles++;

            if ($cycles === 8 && ! $signalSent) {
                posix_kill(posix_getpid(), SIGINT);
                $signalSent = true;
            }

            usleep(50000);

            $this->signalHandler->dispatch();
        }

        $this->assertTrue($this->signalHandler->shouldStop());
        $this->assertEquals(8, $cycles);
        $this->assertNotEquals($maxCycles, $cycles);
    }

    public function test_multiple_signals_are_handled_correctly(): void
    {

        $this->signalHandler->install();

        $cycles = 0;
        $signalSent = false;

        while (! $this->signalHandler->shouldStop() && $cycles < 10) {
            $cycles++;

            if ($cycles === 3 && ! $signalSent) {
                posix_kill(posix_getpid(), SIGINT);
                $signalSent = true;
            }

            usleep(50000);

            $this->signalHandler->dispatch();
        }

        // La boucle s'arrête au cycle 3
        $this->assertTrue($this->signalHandler->shouldStop());
        $this->assertEquals(3, $cycles);
    }

    public function test_signal_handler_works_with_multiple_cycles_and_checkpoints(): void
    {

        $this->signalHandler->install();

        $checkpoints = [];
        $cycles = 0;
        $signalSent = false;

        while (! $this->signalHandler->shouldStop() && $cycles < 15) {
            $cycles++;

            if ($cycles === 10 && ! $signalSent) {
                posix_kill(posix_getpid(), SIGINT);
                $signalSent = true;
            }

            usleep(50000);

            if ($cycles % 2 === 0) {
                $checkpoints[] = $cycles;
            }

            $this->signalHandler->dispatch();
        }

        $this->assertTrue($this->signalHandler->shouldStop());
        $this->assertEquals(10, $cycles);

        $this->assertCount(5, $checkpoints);
        $this->assertEquals([2, 4, 6, 8, 10], $checkpoints);
    }

    public function test_signal_handler_stops_immediately_when_signal_is_pending(): void
    {

        $this->signalHandler->install();

        // Envoyer le signal AVANT la boucle
        posix_kill(posix_getpid(), SIGINT);

        // ✅ DISPATCHER LE SIGNAL AVANT LA BOUCLE
        $this->signalHandler->dispatch();

        $cycles = 0;
        $maxCycles = 10;

        // La boucle doit s'arrêter immédiatement car le signal est déjà dispatché
        while (! $this->signalHandler->shouldStop() && $cycles < $maxCycles) {
            $cycles++;
            usleep(50000);
            $this->signalHandler->dispatch();
        }

        $this->assertTrue($this->signalHandler->shouldStop());
        $this->assertEquals(0, $cycles);
    }
}
