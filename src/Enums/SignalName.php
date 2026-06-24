<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Enum representing POSIX signal names.
 *
 * @author Andy Defer
 */
enum SignalName: string
{
    case SIGINT = 'SIGINT';
    case SIGTERM = 'SIGTERM';
    case SIGKILL = 'SIGKILL';
    case SIGSTOP = 'SIGSTOP';
    case SIGCONT = 'SIGCONT';
    case SIGHUP = 'SIGHUP';
    case SIGQUIT = 'SIGQUIT';
    case SIGABRT = 'SIGABRT';
    case SIGALRM = 'SIGALRM';
    case SIGCHLD = 'SIGCHLD';
    case SIGFPE = 'SIGFPE';
    case SIGILL = 'SIGILL';
    case SIGPIPE = 'SIGPIPE';
    case SIGSEGV = 'SIGSEGV';
    case SIGUSR1 = 'SIGUSR1';
    case SIGUSR2 = 'SIGUSR2';

    /**
     * Get the human-readable label.
     *
     * @return string The label
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::SIGINT => 'Interrupt (Ctrl+C)',
            self::SIGTERM => 'Terminate',
            self::SIGKILL => 'Kill (forced)',
            self::SIGSTOP => 'Stop (pause)',
            self::SIGCONT => 'Continue',
            self::SIGHUP => 'Hangup',
            self::SIGQUIT => 'Quit',
            self::SIGABRT => 'Abort',
            self::SIGALRM => 'Alarm',
            self::SIGCHLD => 'Child process',
            self::SIGFPE => 'Floating point exception',
            self::SIGILL => 'Illegal instruction',
            self::SIGPIPE => 'Broken pipe',
            self::SIGSEGV => 'Segmentation fault',
            self::SIGUSR1 => 'User signal 1',
            self::SIGUSR2 => 'User signal 2',
        };
    }

    /**
     * Get the description of the signal.
     *
     * @return string The description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::SIGINT => 'Interrupt from keyboard (Ctrl+C)',
            self::SIGTERM => 'Request process termination',
            self::SIGKILL => 'Force immediate process termination',
            self::SIGSTOP => 'Pause process execution',
            self::SIGCONT => 'Resume paused process',
            self::SIGHUP => 'Hangup detected on controlling terminal',
            self::SIGQUIT => 'Quit from keyboard (Ctrl+\)',
            self::SIGABRT => 'Abort signal from abort()',
            self::SIGALRM => 'Alarm clock signal',
            self::SIGCHLD => 'Child process stopped or terminated',
            self::SIGFPE => 'Floating point exception',
            self::SIGILL => 'Illegal instruction',
            self::SIGPIPE => 'Broken pipe: write to pipe with no readers',
            self::SIGSEGV => 'Invalid memory reference',
            self::SIGUSR1 => 'User-defined signal 1',
            self::SIGUSR2 => 'User-defined signal 2',
        };
    }

    /**
     * Check if the signal can be handled.
     *
     * @return bool True if the signal can be caught/handled
     */
    public function isCaught(): bool
    {
        return match ($this) {
            self::SIGKILL, self::SIGSTOP => false,
            default => true,
        };
    }

    /**
     * Check if the signal is terminal (stops the process).
     *
     * @return bool True if the signal terminates the process
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::SIGINT, self::SIGTERM, self::SIGKILL, self::SIGQUIT, self::SIGHUP, self::SIGABRT => true,
            default => false,
        };
    }

    /**
     * Convert from signal number.
     *
     * @param  int  $signalNumber  The signal number
     * @return self|null The signal enum, or null if not found
     */
    public static function fromNumber(int $signalNumber): ?self
    {
        return match ($signalNumber) {
            SIGINT => self::SIGINT,
            SIGTERM => self::SIGTERM,
            SIGKILL => self::SIGKILL,
            SIGSTOP => self::SIGSTOP,
            SIGCONT => self::SIGCONT,
            SIGHUP => self::SIGHUP,
            SIGQUIT => self::SIGQUIT,
            SIGABRT => self::SIGABRT,
            SIGALRM => self::SIGALRM,
            SIGCHLD => self::SIGCHLD,
            SIGFPE => self::SIGFPE,
            SIGILL => self::SIGILL,
            SIGPIPE => self::SIGPIPE,
            SIGSEGV => self::SIGSEGV,
            SIGUSR1 => self::SIGUSR1,
            SIGUSR2 => self::SIGUSR2,
            default => null,
        };
    }

    /**
     * Get the signal number.
     *
     * @return int The signal number
     */
    public function toNumber(): int
    {
        return match ($this) {
            self::SIGINT => SIGINT,
            self::SIGTERM => SIGTERM,
            self::SIGKILL => SIGKILL,
            self::SIGSTOP => SIGSTOP,
            self::SIGCONT => SIGCONT,
            self::SIGHUP => SIGHUP,
            self::SIGQUIT => SIGQUIT,
            self::SIGABRT => SIGABRT,
            self::SIGALRM => SIGALRM,
            self::SIGCHLD => SIGCHLD,
            self::SIGFPE => SIGFPE,
            self::SIGILL => SIGILL,
            self::SIGPIPE => SIGPIPE,
            self::SIGSEGV => SIGSEGV,
            self::SIGUSR1 => SIGUSR1,
            self::SIGUSR2 => SIGUSR2,
        };
    }
}
