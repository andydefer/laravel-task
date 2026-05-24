<?php

// tests/UnitTestCase.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for pure unit tests that don't need Laravel.
 * No Laravel bootstrap, no database, no migrations.
 *
 * ⚠️ RULE: Tests extending this class:
 * - CANNOT use the database
 * - CANNOT use Laravel facades
 * - MUST mock all their dependencies
 */
abstract class UnitTestCase extends BaseTestCase
{
    // Pure unit tests only
    // No setUp(), no tearDown(), no Laravel
}
