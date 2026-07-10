<?php

// config/directive.php
return [
    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, shows debug information in the console.
    |
    */
    'debug' => env('DIRECTIVE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Max Depth
    |--------------------------------------------------------------------------
    |
    | Maximum depth for recursive directory scanning.
    |
    */
    'max_depth' => env('DIRECTIVE_MAX_DEPTH', 3),

    /*
    |--------------------------------------------------------------------------
    | Custom Sources
    |--------------------------------------------------------------------------
    |
    | Additional directories to scan for directives.
    |
    */
    'custom_sources' => [],

    /*
    |--------------------------------------------------------------------------
    | Log Base Path
    |--------------------------------------------------------------------------
    |
    | Base path for execution statistics logs.
    | If not set, defaults to {base_path}/.directive
    |
    */
    'log_base_path' => env('DIRECTIVE_LOG_BASE_PATH'),
];
