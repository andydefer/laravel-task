<?php

declare(strict_types=1);

namespace AndyDefer\Task\Helpers;

/**
 * Helper for storing task execution results in JSONL format.
 */
final class JsonlResultHelper
{
    private static ?string $sessionId = null;

    private static ?string $filePath = null;

    /**
     * Initialize the JSONL result storage with a session ID.
     */
    public static function init(string $sessionId): void
    {
        self::$sessionId = $sessionId;
        self::$filePath = sys_get_temp_dir().'/task_results_'.$sessionId.'.jsonl';
    }

    /**
     * Append a result to the JSONL file.
     */
    public static function append(
        string $alias,
        string $type,
        string $status,
        int $success,
        int $failed,
        ?string $error = null
    ): void {
        if (self::$filePath === null) {
            return;
        }

        $data = [
            'session_id' => self::$sessionId,
            'alias' => $alias,
            'type' => $type,
            'status' => $status,
            'success' => $success,
            'failed' => $failed,
            'error' => $error,
            'timestamp' => time(),
        ];

        file_put_contents(self::$filePath, json_encode($data)."\n", FILE_APPEND);
    }

    /**
     * Get all results for the current session.
     */
    public static function getResults(): array
    {
        if (self::$filePath === null || ! file_exists(self::$filePath)) {
            return [];
        }

        $results = [];
        $lines = file(self::$filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if ($decoded !== null) {
                $results[] = $decoded;
            }
        }

        return $results;
    }

    /**
     * Count results by type and status.
     */
    public static function countResults(): array
    {
        $results = self::getResults();

        $stats = [
            'unique_completed' => 0,
            'unique_failed' => 0,
            'recurring_completed' => 0,
            'recurring_failed' => 0,
        ];

        foreach ($results as $result) {
            $key = $result['type'].'_'.$result['status'];
            if (isset($stats[$key])) {
                $stats[$key] += 1;
            }
        }

        return $stats;
    }

    /**
     * Delete the JSONL file.
     */
    public static function cleanup(): void
    {
        if (self::$filePath !== null && file_exists(self::$filePath)) {
            @unlink(self::$filePath);
        }
        self::$filePath = null;
        self::$sessionId = null;
    }

    /**
     * Get the file path.
     */
    public static function getFilePath(): ?string
    {
        return self::$filePath;
    }

    /**
     * Get the session ID.
     */
    public static function getSessionId(): ?string
    {
        return self::$sessionId;
    }
}
