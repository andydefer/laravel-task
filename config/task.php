<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | The base directory where all task data will be stored.
    |
    */
    'storage_path' => env('TASK_STORAGE_PATH', storage_path('tasks')),

    /*
    |--------------------------------------------------------------------------
    | Grace Period Settings
    |--------------------------------------------------------------------------
    |
    | Grace period allows expired tasks to be executed within a certain
    | window after their expiration date.
    |
    */
    'grace_period' => [
        'enabled' => env('TASK_GRACE_PERIOD_ENABLED', true),
        'seconds' => (int) env('TASK_GRACE_PERIOD_SECONDS', 86400), // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing Settings
    |--------------------------------------------------------------------------
    |
    | Configure how tasks are processed in batches.
    |
    */
    'batch' => [
        'limit' => (int) env('TASK_BATCH_LIMIT', 1000),
        'order' => env('TASK_BATCH_ORDER', 'oldest'), // 'oldest' or 'newest'
    ],
];
