# TaskRunnerService - Référence Technique

## Description

Service d'exécution des tâches uniques et récurrentes. Gère la validation, l'instanciation via le container Laravel, l'exécution, la journalisation, les mécanismes de reprise (retry, période de grâce) et l'utilisation des repositories pour la persistance.

## Hiérarchie

```
TaskRunnerService
```

La classe est `final` et n'étend aucune classe parente.

## Rôle principal

Exécuter les tâches en validant leur état, en gérant les tentatives d'échec avec `CounterVO`, les mécanismes de reprise, la période de grâce pour les tâches expirées et la persistance via les repositories.

## API / Méthodes publiques

### `__construct(TaskRepositoryInterface $taskRepository, RecurringTaskRepositoryInterface $recurringTaskRepository, LoggerInterface $logger, TaskValidatorService $validator, TaskConfig $config, HydrationService $hydration, FileSystemInterface $fs, Application $app): void`

Injecte les dépendances nécessaires à l'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskRepository` | `TaskRepositoryInterface` | Repository pour les tâches uniques |
| `$recurringTaskRepository` | `RecurringTaskRepositoryInterface` | Repository pour les tâches récurrentes |
| `$logger` | `LoggerInterface` | Service de journalisation |
| `$validator` | `TaskValidatorService` | Service de validation des tâches |
| `$config` | `TaskConfig` | Configuration du système (chemins, période de grâce) |
| `$hydration` | `HydrationService` | Service d'hydratation des objets |
| `$fs` | `FileSystemInterface` | Service de système de fichiers |
| `$app` | `Application` | Container Laravel pour l'instanciation |

### `runTask(TaskRecord $task): bool`

Exécute une tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche unique à exécuter (avec Value Objects) |

**Retourne :** `bool` - `true` si la tâche a réussi, `false` sinon

**Exemple :**
```php
$tasks = $taskRepository->findAll();
$task = $tasks->first();
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
$task = $recurringTaskRepository->find(new TaskSignatureVO('cleanup-task'));
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
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('send-email'),
    class: SendEmailTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    created_at: new Iso8601DateTimeVO(),
    start_at: new Iso8601DateTimeVO(),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
    delay_seconds: new CounterVO(0),
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
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
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$task = new RecurringTaskRecord(
    signature: new TaskSignatureVO('cleanup-logs'),
    class: CleanupLogsTask::class,
    payload: $payload,
    start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
    end_at: null,
    delay_seconds: new CounterVO(3600),
    last_run_at: null,
    next_run_at: new Iso8601DateTimeVO(),
    success_count: new CounterVO(0),
    failure_count: new CounterVO(0),
);

$success = $runner->runRecurringTask($task);

// Après exécution, next_run_at est automatiquement mis à jour
$updated = $recurringTaskRepository->find($task->signature);
echo $updated->next_run_at->value; // now + 3600 seconds
```

### Cas 3 : Gestion des échecs avec ErrorType

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Enums\ErrorType;

// Une tâche qui échoue
$task = new TaskRecord(
    // ...
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

$success = $runner->runTask($task); // false

// La tâche est réenregistrée avec attempts = 1
$pending = $taskRepository->findAll();
$updatedTask = $pending->first();
echo $updatedTask->attempts->value; // 1

// L'erreur est catégorisée avec ErrorType::TASK_EXECUTION_FAILED
```

## Flux d'exécution

```
runTask(TaskRecord $task)
    │
    ├── validator->canRunTask()
    │   ├── Vérifie status PENDING
    │   ├── Vérifie attempts < max_attempts
    │   ├── Vérifie start_at <= now
    │   ├── Vérifie end_at avec/sans période de grâce
    │   └── false → markTaskFailed(ErrorType::TASK_VALIDATION_FAILED)
    │
    ├── logGracePeriodIfNeeded()
    │   └── Si période de grâce active et tâche expirée
    │       ├── logger->warning()
    │       └── storeGracePeriodRecord()
    │
    ├── validator->validateTaskClass()
    │   └── false → markTaskFailed(ErrorType::INVALID_TASK_CLASS)
    │
    ├── instantiateTask()
    │   ├── Crée TaskContext
    │   ├── Set taskId, signature, app
    │   └── new $className($context, $logger, $hydration)
    │
    ├── taskInstance->execute($task->payload)
    │   ├── before() hook
    │   ├── process() hook
    │   └── after() hook
    │
    ├── Succès → markTaskSuccess()
    │   └── taskRepository->moveToCompleted($task, true)
    │
    └── Exception → markTaskFailed(ErrorType::TASK_EXECUTION_FAILED)
        ├── Terminal ? moveToCompleted()
        ├── attempts+1 >= max_attempts ? moveToCompleted()
        ├── Expiré ? moveToCompleted()
        └── Sinon : delete() + save() avec attempts+1
```

## Gestion des erreurs avec ErrorType

| Situation | ErrorType | Terminal | Comportement |
|-----------|-----------|----------|--------------|
| Tâche non exécutable | `TASK_VALIDATION_FAILED` | ❌ | Retry possible |
| Classe de tâche invalide | `INVALID_TASK_CLASS` | ✅ | Archivage immédiat |
| Exception pendant l'exécution | `TASK_EXECUTION_FAILED` | ❌ | Retry possible |
| Dernière tentative échouée | `MAX_ATTEMPTS_REACHED` | ✅ | Archivage |
| Tâche expirée | `TASK_EXPIRED` | ✅ | Archivage |
| Période de grâce expirée | `GRACE_PERIOD_EXPIRED` | ✅ | Archivage |

## Mécanisme de retry avec CounterVO

| Tentative | attempts->value | Comportement |
|-----------|-----------------|--------------|
| 1ère | 0 → 1 | Exécution → échec → attempts = 1, réenregistrée |
| 2ème | 1 → 2 | Exécution → échec → attempts = 2, réenregistrée |
| 3ème | 2 → 3 | Exécution → échec → attempts = 3, archivée |
| maxAttempts atteint | - | Plus de tentative, archivée |

## Intégration

### Dépendances

```
TaskRunnerService
    ├── TaskRepositoryInterface (persistance tâches uniques)
    ├── RecurringTaskRepositoryInterface (persistance tâches récurrentes)
    ├── TaskValidatorService (validation)
    ├── TaskConfig (configuration)
    ├── LoggerInterface (journalisation)
    ├── HydrationService (hydratation)
    ├── FileSystemInterface (stockage grace period)
    └── Application (container Laravel)
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
    
    private function executeRecurringTask(BatchResultRecord $result, RecurringTaskRecord $task): BatchResultRecord
    {
        $success = $this->runner->runRecurringTask($task);
        // ...
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `runTask()` | O(1) + exécution tâche | L'exécution dépend de la tâche |
| `runRecurringTask()` | O(1) + exécution tâche | Idem |
| `markTaskFailed()` avec retry | O(1) | Delete + Save via repository |
| `markTaskFailed()` sans retry | O(1) | moveToCompleted() uniquement |
| `logGracePeriodIfNeeded()` | O(1) | Vérification + écriture fichier grace period |

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

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\AbstractTask;

// 1. Définir une tâche
final class BackupDatabaseTask extends AbstractTask
{
    protected function process(): void
    {
        $data = $this->context->getPayload()->data;
        $this->info("Starting database backup: {$data->database}");
        // Logique de sauvegarde
        $this->info("Database backup completed to {$data->destination}");
    }
}

// 2. Créer la configuration
$config = new TaskConfig(app('config'));

// 3. Créer le payload
$payload = new TaskPayloadRecord(
    type: 'backup',
    data: new StrictDataObject([
        'database' => 'mysql',
        'destination' => '/backups',
    ]),
);

// 4. Initialiser les services
$taskRepository = app(TaskRepositoryInterface::class);
$recurringTaskRepository = app(RecurringTaskRepositoryInterface::class);
$validator = new TaskValidatorService($config, new HydrationService(), app(LoggerInterface::class), app());
$logger = app(LoggerInterface::class);
$hydration = new HydrationService();
$fs = app(FileSystemInterface::class);
$app = app();

$runner = new TaskRunnerService(
    $taskRepository,
    $recurringTaskRepository,
    $logger,
    $validator,
    $config,
    $hydration,
    $fs,
    $app,
);

// 5. Créer et enregistrer la tâche
$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('backup-database'),
    class: BackupDatabaseTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    created_at: new Iso8601DateTimeVO(),
    start_at: new Iso8601DateTimeVO(),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
    delay_seconds: new CounterVO(0),
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

$taskRepository->save($task);

// 6. Exécuter la tâche
$success = $runner->runTask($task);

if ($success) {
    echo "Backup completed successfully\n";
} else {
    echo "Backup failed, check logs\n";
}

// 7. Vérifier l'état
$pending = $taskRepository->findAll();
echo "Pending tasks: " . $pending->count() . "\n";
```

## Gestion de la période de grâce

### Déclenchement

Une tâche unique exécutée après son `end_at` génère :

1. Un log `warning` avec l'événement `task_executed_during_grace_period`
2. Un fichier JSON dans `storage/grace_period/{task_id}.json`

### Structure du fichier grace period

```json
{
    "task_id": "550e8400-e29b-41d4-a716-446655440000",
    "signature": "backup-database",
    "original_end_at": 1748179200,
    "executed_at": 1748265600,
    "delay_seconds": 86400
}
```

## Voir aussi

- `TaskRepositoryInterface` - Repository pour les tâches uniques
- `RecurringTaskRepositoryInterface` - Repository pour les tâches récurrentes
- `TaskValidatorService` - Service de validation
- `TaskConfig` - Configuration du système
- `TaskRecord` - Record pour les tâches uniques (avec Value Objects)
- `RecurringTaskRecord` - Record pour les tâches récurrentes
- `AbstractTask` - Classe de base des tâches
- `ErrorType` - Enum des types d'erreur
- `GracePeriodRecord` - Enregistrement des exécutions en période de grâce
- `CounterVO` - Value Object pour les compteurs
- `UnixTimestampVO` - Value Object pour les timestamps
- `TaskContext` - Contexte d'exécution des tâches