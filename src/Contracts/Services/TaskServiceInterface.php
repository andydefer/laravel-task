<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

/**
 * Interface centralisant toutes les fonctionnalités du package Laravel Task.
 *
 * Cette interface étend les interfaces spécialisées pour fournir une API unifiée.
 *
 * @author Andy Defer
 */
interface TaskServiceInterface extends BatchResultServiceInterface, TaskBatchServiceInterface, TaskFinderServiceInterface, TaskRegistryServiceInterface, TaskRunnerServiceInterface, TaskValidatorServiceInterface {}
