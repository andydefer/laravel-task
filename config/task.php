<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Tasks Storage Path
    |--------------------------------------------------------------------------
    |
    | This path is used by the task system to store pending, recurring,
    | and completed tasks. Ensure this directory is writable.
    |
    */
    'storage_path' => env('TASK_STORAGE_PATH', storage_path('tasks')),

    /*
    |--------------------------------------------------------------------------
    | Grace Period
    |--------------------------------------------------------------------------
    |
    | Configuration for expired task grace period. Tasks that have passed their
    | end_at date can still be executed within this grace period.
    |
    */
    'grace_period' => [
        'enabled' => env('TASK_GRACE_PERIOD_ENABLED', true),
        'seconds' => env('TASK_GRACE_PERIOD_SECONDS', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing Limits
    |--------------------------------------------------------------------------
    |
    | Maximum number of tasks to process in a single batch.
    | Set to null or 0 for no limit.
    |
    */
    'batch' => [
        'limit' => env('TASK_BATCH_LIMIT', 1000),
        'order' => env('TASK_BATCH_ORDER', 'oldest'), // 'oldest' or 'newest'
    ],
];
