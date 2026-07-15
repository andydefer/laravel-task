<?php

declare(strict_types=1);

namespace AndyDefer\Task\Helpers;

/**
 * Helper for managing session IDs across processes.
 */
final class SessionHelper
{
    private static ?string $sessionId = null;

    private static ?string $sessionFile = null;

    /**
     * Generate a new session ID and create the session file.
     *
     * @return string The generated session ID
     */
    public static function generate(): string
    {
        self::$sessionId = uniqid('session_', true).'_'.time();
        self::$sessionFile = sys_get_temp_dir().'/task_session_'.self::$sessionId.'.tmp';
        file_put_contents(self::$sessionFile, self::$sessionId);

        return self::$sessionId;
    }

    /**
     * Get the current session ID.
     * If called from a child process, reads from the session file.
     *
     * @return string|null The session ID, or null if not found
     */
    public static function get(): ?string
    {
        if (self::$sessionId !== null) {
            return self::$sessionId;
        }

        $files = glob(sys_get_temp_dir().'/task_session_*.tmp');
        if (empty($files)) {
            return null;
        }

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        self::$sessionId = file_get_contents($files[0]);
        self::$sessionFile = $files[0];

        return self::$sessionId;
    }

    /**
     * Delete the session file.
     */
    public static function delete(): void
    {
        if (self::$sessionFile !== null && file_exists(self::$sessionFile)) {
            @unlink(self::$sessionFile);
        }

        $files = glob(sys_get_temp_dir().'/task_session_*.tmp');
        foreach ($files as $file) {
            if (filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }

        self::$sessionId = null;
        self::$sessionFile = null;
    }

    /**
     * Check if a session exists.
     */
    public static function exists(): bool
    {
        $files = glob(sys_get_temp_dir().'/task_session_*.tmp');

        return ! empty($files);
    }

    /**
     * Get the session file path.
     */
    public static function getSessionFile(): ?string
    {
        return self::$sessionFile;
    }

    /**
     * Get the session ID without generating a new one.
     */
    public static function peek(): ?string
    {
        if (self::$sessionId !== null) {
            return self::$sessionId;
        }

        $files = glob(sys_get_temp_dir().'/task_session_*.tmp');
        if (empty($files)) {
            return null;
        }

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return file_get_contents($files[0]);
    }
}
