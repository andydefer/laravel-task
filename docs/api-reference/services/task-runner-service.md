# TaskRunnerService - Référence Technique

## Description

Service d'exécution des tâches uniques et récurrentes. Gère la validation, l'instanciation, l'exécution, la journalisation et les mécanismes de reprise (retry, période de grâce).

## Hiérarchie

```
TaskRunnerService
```

La classe est `final` et n'étend aucune classe parente.

## Rôle principal

Exécuter les tâches en validant leur état, en gérant les tentatives d'échec, les mécanismes de reprise et la période de grâce pour les tâches expirées.

## API / Méthodes publiques

### `__construct(TaskStorageService $storage, Logger $logger, TaskValidatorService $validator, TaskConfig $config): void`

Injecte les dépendances nécessaires à l'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$storage` | `TaskStorageService` | Service de persistance des tâches |
| `$logger` | `Logger` | Service de journalisation |
| `$validator` | `TaskValidatorService` | Service de validation des tâches |
| `$config` | `TaskConfig` | Configuration du système (chemins, période de grâce) |

### `runTask(TaskRecord $task): bool`

Exécute une tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche unique à exécuter |

**Retourne :** `bool` - `true` si la tâche a réussi, `false` sinon

**Exemple :**
```php
$task = $storage->findPending()->first();
$success = $runner->runTask($task);
```

### `runRecurringTask(RecurringTaskRecord $task): bool`

Exécute une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche récurrente à exécuter |

**Retourne :** `bool` - `true` si la tâche a réussi, `false` sinon

**Exemple :**
```php
$task = $storage->getRecurring('cleanup-task');
$success = $runner->runRecurringTask($task);
```

## Cas d'utilisation

### Cas 1 : Exécution d'une tâche unique

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskRecord;

$task = new TaskRecord(
    id: '550e8400-e29b-41d4-a716-446655440000',
    signature: 'send-email',
    class: SendEmailTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    createdAt: date('c'),
    startAt: date('c'),
    endAt: date('c', strtotime('+1 hour')),
    delaySeconds: 0,
    attempts: 0,
    maxAttempts: 3,
);

$success = $runner->runTask($task);

if ($success) {
    echo "Task executed successfully\n";
} else {
    echo "Task failed\n";
}
```

### Cas 2 : Exécution d'une tâche récurrente

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Records\RecurringTaskRecord;

$task = new RecurringTaskRecord(
    signature: 'cleanup-logs',
    class: CleanupLogsTask::class,
    payload: $payload,
    startAt: date('c', strtotime('-1 hour')),
    endAt: null,
    delaySeconds: 3600,
    lastRunAt: null,
    nextRunAt: date('c'),
    successCount: 0,
    failureCount: 0,
);

$success = $runner->runRecurringTask($task);

// Après exécution, nextRunAt est automatiquement mis à jour
$updated = $storage->getRecurring('cleanup-logs');
echo $updated->nextRunAt; // now + 3600 seconds
```

### Cas 3 : Gestion des échecs et des tentatives

```php
<?php

declare(strict_types=1);

// Une tâche qui échoue
$task = new TaskRecord(
    // ...
    attempts: 0,
    maxAttempts: 3,
);

$success = $runner->runTask($task); // false

// La tâche est réenregistrée avec attempts = 1
$pending = $storage->findPending();
$updatedTask = $pending->first();
echo $updatedTask->attempts; // 1
```

## Flux d'exécution

```
runTask()
    │
    ├─→ validator->canRunTask()
    │   └─→ false → return false
    │
    ├─→ logGracePeriodIfNeeded()
    │
    ├─→ validator->validateTaskClass()
    │   └─→ false → markTaskFailed() → return false
    │
    ├─→ instantiateTask()
    │   ├─→ new $className()
    │   ├─→ setLogger()
    │   ├─→ setTaskId()
    │   └─→ setSignature()
    │
    ├─→ taskInstance->execute()
    │   ├─→ success → markTaskSuccess()
    │   └─→ exception → markTaskFailed()
    │
    └─→ return true/false
```

## Gestion des erreurs

| Situation | Comportement | Retour |
|-----------|--------------|--------|
| Tâche non exécutable | Aucune exécution | `false` |
| Classe de tâche invalide | Archivage immédiat | `false` |
| Exception pendant l'exécution | Marqué comme échec | `false` |
| Dernière tentative échouée | Archivage (plus de retry) | `false` |
| Tâche expirée | Archivage immédiat | `false` |

## Mécanisme de retry

| Tentative | Comportement |
|-----------|--------------|
| 1ère (attempts = 0) | Exécution → échec → attempts = 1, réenregistrée |
| 2ème (attempts = 1) | Exécution → échec → attempts = 2, réenregistrée |
| 3ème (attempts = 2) | Exécution → échec → attempts = 3, archivée |
| maxAttempts atteint | Plus de tentative, archivée |

## Intégration

### Dépendances

```
TaskRunnerService
    ├── TaskStorageService (persistance)
    ├── TaskValidatorService (validation)
    ├── TaskConfig (configuration)
    └── Logger (journalisation)
```

### Avec TaskBatchService

```php
class TaskBatchService
{
    private function executeUniqueTask(BatchResultRecord $result, TaskRecord $task): BatchResultRecord
    {
        $success = $this->runner->runTask($task);
        // ...
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `runTask()` | O(1) + exécution tâche | L'exécution dépend de la tâche |
| `runRecurringTask()` | O(1) + exécution tâche | Idem |
| `markTaskFailed()` avec retry | O(1) | Suppression + réenregistrement |
| `markTaskFailed()` sans retry | O(1) | Archivage uniquement |

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Requis (readonly properties) |
| PHP 8.1 | ✅ Complet |
| PHP 8.0 | ❌ |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\AbstractTask;
use Ramsey\Uuid\Uuid;

// 1. Définir une tâche
final class BackupDatabaseTask extends AbstractTask
{
    protected function process(): void
    {
        $this->info("Starting database backup...");
        // Logique de sauvegarde
        $this->info("Database backup completed");
    }
}

// 2. Créer la configuration
$config = new TaskConfig();

// 3. Créer le payload
$payload = new TaskPayloadRecord(
    type: 'backup',
    payload: StrictDataObjectCollection::from([
        'database' => 'mysql',
        'destination' => '/backups',
    ])
);

// 4. Initialiser les services
$storage = new TaskStorageService($config);
$validator = new TaskValidatorService($config);
$logger = app(Logger::class);
$runner = new TaskRunnerService($storage, $logger, $validator, $config);

// 5. Créer et enregistrer la tâche
$task = new TaskRecord(
    id: Uuid::uuid4()->toString(),
    signature: 'backup-database',
    class: BackupDatabaseTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    createdAt: date('c'),
    startAt: date('c'),
    endAt: date('c', strtotime('+1 hour')),
    delaySeconds: 0,
    attempts: 0,
    maxAttempts: 3,
);

$storage->savePending($task);

// 6. Exécuter la tâche
$success = $runner->runTask($task);

if ($success) {
    echo "Backup completed successfully\n";
} else {
    echo "Backup failed, check logs\n";
}
```

## Voir aussi

- `TaskStorageService` - Service de persistance
- `TaskValidatorService` - Service de validation
- `TaskConfig` - Configuration du système
- `TaskRecord` - Record pour les tâches uniques
- `RecurringTaskRecord` - Record pour les tâches récurrentes
- `AbstractTask` - Classe de base des tâches
- `GracePeriodRecord` - Enregistrement des exécutions en période de grâce

---