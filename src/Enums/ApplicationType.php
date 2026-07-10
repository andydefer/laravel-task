<?php

namespace AndyDefer\Task\Enums;

/**
 * Enum for application types.
 */
enum ApplicationType: string
{
    case WEB_APPLICATION = 'web_application';
    case PACKAGE = 'package';
    case UNKNOWN = 'unknown';
}
