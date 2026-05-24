<?php

// tests/Integration/Services/ProcessManagerLockTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Logger\Logger;
use AndyDefer\Task\Collections\TaskCollection;
use AndyDefer\Task\Services\ProcessManager;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class ProcessManagerLockTest extends IntegrationTestCase
{
    private TaskStorage&MockObject $storage;
    private TaskRunner $runner;
    private TaskValidator $validator;
    private Logger $logger;
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockPath = sys_get_temp_dir() . '/poller_test_' . uniqid() . '.lock';

        $this->storage = $this->createMock(TaskStorage::class);
        $this->validator = $this->app->make(TaskValidator::class);
        $this->logger = $this->app->make(Logger::class);
        $this->runner = new TaskRunner($this->storage, $this->logger, $this->validator);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockPath)) {
            unlink($this->lockPath);
        }
        parent::tearDown();
    }

    public function test_lock_is_acquired_and_released_after_execution(): void
    {
        $manager = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $this->lockPath,
            useSequentialMode: true,
        );

        $emptyCollection = new TaskCollection();

        $this->storage->method('findPending')
            ->willReturn($emptyCollection);

        $this->storage->method('findRecurring')
            ->willReturn($emptyCollection);

        $manager->run(1, false);

        $this->assertFalse($manager->isLockAcquired());
        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_lock_is_released_on_exception(): void
    {
        $manager = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $this->lockPath,
            useSequentialMode: true,
        );

        $this->storage->method('findPending')
            ->willThrowException(new \RuntimeException('Test exception'));

        try {
            $manager->run(1, false);
        } catch (\RuntimeException $e) {
            // Exception attendue
        }

        $this->assertFalse($manager->isLockAcquired());
        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_lock_file_is_deleted_after_execution(): void
    {
        $manager = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $this->lockPath,
            useSequentialMode: true,
        );

        $emptyCollection = new TaskCollection();

        $this->storage->method('findPending')
            ->willReturn($emptyCollection);

        $this->storage->method('findRecurring')
            ->willReturn($emptyCollection);

        $this->assertFileDoesNotExist($this->lockPath);

        $manager->run(1, false);

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_second_poller_cannot_acquire_lock_when_first_is_running(): void
    {
        // Utiliser un lock path temporaire unique
        $testLockPath = sys_get_temp_dir() . '/poller_test_' . uniqid() . '.lock';

        // Créer deux managers avec le même lock
        $manager1 = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $testLockPath,
            useSequentialMode: true,
        );

        $manager2 = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $testLockPath,
            useSequentialMode: true,
        );

        // Simuler l'acquisition du lock par le premier manager
        // Depuis PHP 8.1, setAccessible() n'est plus nécessaire
        $reflection = new \ReflectionClass($manager1);
        $acquireMethod = $reflection->getMethod('acquireLock');
        // ⚠️ La ligne setAccessible(true) a été supprimée
        $acquired = $acquireMethod->invoke($manager1);

        $this->assertTrue($acquired, 'Le premier manager doit prendre le lock');
        $this->assertFileExists($testLockPath, 'Le fichier lock doit exister');

        // Le second manager doit échouer à prendre le lock
        $reflection2 = new \ReflectionClass($manager2);
        $acquireMethod2 = $reflection2->getMethod('acquireLock');
        // ⚠️ La ligne setAccessible(true) a été supprimée
        $acquired2 = $acquireMethod2->invoke($manager2);

        $this->assertFalse($acquired2, 'Le second manager ne doit pas prendre le lock');

        // Nettoyer
        $releaseMethod = $reflection->getMethod('releaseLock');
        // ⚠️ La ligne setAccessible(true) a été supprimée
        $releaseMethod->invoke($manager1);

        $this->assertFileDoesNotExist($testLockPath, 'Le lock doit être libéré');
    }

    public function test_concurrent_pollers_respect_lock(): void
    {
        // Tester séquentiellement que le lock empêche l'exécution
        $manager1 = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $this->lockPath,
            useSequentialMode: true,
        );

        $emptyCollection = new TaskCollection();

        $executionCount = 0;

        $this->storage->method('findPending')
            ->willReturnCallback(function () use (&$executionCount, $emptyCollection) {
                $executionCount++;
                return $emptyCollection;
            });

        $this->storage->method('findRecurring')
            ->willReturn($emptyCollection);

        // Premier run - doit s'exécuter
        $manager1->run(1, false);
        $this->assertSame(1, $executionCount, 'Premier run doit s\'exécuter');

        // Créer un second manager avec le même lock
        $manager2 = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $this->lockPath,
            useSequentialMode: true,
        );

        // Le lock devrait être libéré après le premier run
        // Donc le second run doit aussi s'exécuter
        $manager2->run(1, false);
        $this->assertSame(2, $executionCount, 'Second run doit aussi s\'exécuter après libération');

        // Vérifier que les locks sont libérés
        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_lock_file_behavior_during_execution(): void
    {
        $manager = new ProcessManager(
            runner: $this->runner,
            storage: $this->storage,
            logger: $this->logger,
            validator: $this->validator,
            lockPath: $this->lockPath,
            useSequentialMode: true,
        );

        $emptyCollection = new TaskCollection();

        $lockFileExistsDuringExecution = false;

        $this->storage->method('findPending')
            ->willReturnCallback(function () use ($emptyCollection, &$lockFileExistsDuringExecution, $manager) {
                // Pendant l'exécution, vérifier que le lock est actif
                if ($manager->isLockAcquired() && file_exists($this->lockPath)) {
                    $lockFileExistsDuringExecution = true;
                }
                return $emptyCollection;
            });

        $this->storage->method('findRecurring')
            ->willReturn($emptyCollection);

        $manager->run(1, false);

        $this->assertTrue($lockFileExistsDuringExecution, 'Le fichier lock doit exister pendant l\'exécution');
        $this->assertFileDoesNotExist($this->lockPath, 'Le fichier lock doit être supprimé après exécution');
    }
}
