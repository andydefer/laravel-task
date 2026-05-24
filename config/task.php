<?php

// config/task.php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Tasks Storage Path
    |--------------------------------------------------------------------------
    |
    | This option defines where task files are stored. The task system will
    | create three subdirectories: pending/, recurring/, and completed/
    |
    */
    'storage_path' => env('TASKS_STORAGE_PATH', storage_path('tasks')),

    /*
    |--------------------------------------------------------------------------
    | Default Task Configuration
    |--------------------------------------------------------------------------
    |
    | Default values for task configuration when not specified in the task itself
    |
    */
    'defaults' => [
        'max_attempts' => 3,
        'delay_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Poller Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the task poller directive
    |
    */

    'poller' => [
        'default_duration' => 60,
        'graceful_timeout' => 30,
        'use_sequential_mode' => env('TASKS_USE_SEQUENTIAL_MODE', true),
        'lock_path' => env('TASKS_LOCK_PATH', null),
    ],

    'grace_period' => [
        'enabled' => env('TASKS_GRACE_PERIOD_ENABLED', true),
        'seconds' => env('TASKS_GRACE_PERIOD_SECONDS', 86400), // 24 heures
    ],
];
