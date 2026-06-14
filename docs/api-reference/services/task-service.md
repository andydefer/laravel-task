# TaskService - Référence Technique

## Description

Wrapper centralisé de toutes les fonctionnalités du package Laravel Task.

## Hiérarchie / Implémentations

```
TaskServiceInterface (étend)
    ├── TaskRegistryServiceInterface
    ├── TaskRunnerServiceInterface
    ├── TaskValidatorServiceInterface
    ├── TaskBatchServiceInterface
    ├── BatchResultServiceInterface
    └── TaskFinderServiceInterface
        └── TaskService (implémente)
```

La classe implémente l'interface unifiée `TaskServiceInterface` qui étend toutes les interfaces spécialisées du package.

## Rôle principal

Agir comme un **point d'entrée unique (Façade)** pour toutes les opérations du package. Cette classe ne contient aucune logique métier : elle délègue chaque appel au service spécialisé correspondant par composition.

Ce service est idéal pour les consommateurs qui souhaitent une API unique et centralisée, sans avoir à injecter plusieurs services distincts.

## API / Méthodes publiques

> **Note :** Les méthodes sont regroupées par interface parente. Consultez les références spécifiques de chaque service pour plus de détails.

### Gestion des tâches (TaskRegistryServiceInterface)

#### `register(string $taskClass, TaskPayloadRecord $payload, ?TaskConfigRecord $override_config = null): string`

Enregistre une nouvelle tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskClass` | `string` | Classe de la tâche (doit étendre `AbstractTask`) |
| `$payload` | `TaskPayloadRecord` | Données de la tâche |
| `$override_config` | `TaskConfigRecord|null` | Configuration surchargeante |

**Retourne :** `string` - UUID pour une tâche unique, signature pour une tâche récurrente

#### `unregisterTask(TaskIdVO $taskId): void`

Supprime une tâche unique.

#### `unregisterRecurring(TaskSignatureVO $signature): void`

Supprime une tâche récurrente.

#### `unregister(string $identifier): void`

Supprime une tâche par détection automatique du type.

### Exécution des tâches (TaskRunnerServiceInterface)

#### `runTask(TaskRecord $task): bool`

Exécute une tâche unique.

#### `runRecurringTask(RecurringTaskRecord $task): bool`

Exécute une tâche récurrente.

### Validation (TaskValidatorServiceInterface)

#### `validateTaskClass(string $className): bool`

Valide qu'une classe est une tâche valide.

#### `canRunTask(TaskRecord $task): bool`

Vérifie si une tâche peut être exécutée.

#### `isTaskExpired(TaskRecord $task): bool`

Vérifie si une tâche a expiré.

#### `shouldRunRecurringNow(RecurringTaskRecord $task): bool`

Vérifie si une tâche récurrente doit être exécutée.

#### `getGracePeriodDelay(TaskRecord $task): int`

Calcule le retard d'une tâche par rapport à la période de grâce.

#### `isUniqueTaskWithGracePeriod(TaskRecord $task): bool`

Vérifie si une tâche est éligible à la période de grâce.

### Traitement par lots (TaskBatchServiceInterface)

#### `process(?int $limit = null): BatchResultRecord`

Traite toutes les tâches en attente.

#### `processUniqueOnly(?int $limit = null): BatchResultRecord`

Traite uniquement les tâches uniques.

#### `processRecurringOnly(?int $limit = null): BatchResultRecord`

Traite uniquement les tâches récurrentes.

### Construction de résultats (BatchResultServiceInterface)

#### `withUniqueTask(BatchResultRecord $record, UniqueTaskResultRecord $result): BatchResultRecord`

Ajoute un résultat de tâche unique à un lot.

#### `withRecurringTask(BatchResultRecord $record, RecurringTaskResultRecord $result): BatchResultRecord`

Ajoute un résultat de tâche récurrente à un lot.

### Recherche (TaskFinderServiceInterface)

#### `findTask(TaskIdVO $taskId): ?TaskRecord`

Recherche une tâche unique par son ID.

#### `findRecurringTask(TaskSignatureVO $signature): ?RecurringTaskRecord`

Recherche une tâche récurrente par sa signature.

#### `getPendingTasks(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection`

Récupère les tâches en attente.

#### `getRecurringTasks(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection`

Récupère toutes les tâches récurrentes.

#### `taskExists(TaskIdVO $taskId): bool`

Vérifie l'existence d'une tâche unique.

#### `recurringTaskExists(TaskSignatureVO $signature): bool`

Vérifie l'existence d'une tâche récurrente.

#### `countPendingTasks(): int`

Compte les tâches en attente.

#### `countRecurringTasks(): int`

Compte les tâches récurrentes.

## Cas d'utilisation

### Cas 1 : API unifiée dans un contrôleur Laravel

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use App\Tasks\BackupDatabaseTask;

final class TaskController extends Controller
{
    public function __construct(
        private readonly TaskServiceInterface $task,
    ) {}

    public function scheduleBackup(): void
    {
        // 1. Enregistrer une tâche
        $payload = new TaskPayloadRecord(
            type: 'backup',
            data: StrictDataObject::from(['database' => 'mysql']),
        );

        $taskId = $this->task->register(BackupDatabaseTask::class, $payload);

        // 2. Vérifier son existence
        $exists = $this->task->taskExists(new TaskIdVO($taskId));

        // 3. Compter les tâches en attente
        $pendingCount = $this->task->countPendingTasks();

        // 4. Exécuter le batch
        $result = $this->task->process(50);

        return response()->json([
            'task_id' => $taskId,
            'exists' => $exists,
            'pending_count' => $pendingCount,
            'batch_result' => [
                'unique_success' => $result->unique_success->value,
                'unique_failed' => $result->unique_failed->value,
            ],
        ]);
    }
}
```

### Cas 2 : Commande artisan personnalisée

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Console\Command;

final class TaskStatusCommand extends Command
{
    protected $signature = 'task:status {signature?}';
    protected $description = 'Display task status';

    public function handle(TaskServiceInterface $task): int
    {
        $pendingCount = $task->countPendingTasks();
        $recurringCount = $task->countRecurringTasks();

        $this->info("Pending tasks: {$pendingCount}");
        $this->info("Recurring tasks: {$recurringCount}");

        $signature = $this->argument('signature');
        if ($signature) {
            $exists = $task->recurringTaskExists(new TaskSignatureVO($signature));
            $this->line("Task '{$signature}' exists: " . ($exists ? 'Yes' : 'No'));
        }

        return 0;
    }
}
```

### Cas 3 : Service métier avec opérations composées

```php
<?php

declare(strict_types=1);

namespace App\Services;

use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use App\Tasks\NightlyReportTask;

final class ReportScheduler
{
    public function __construct(
        private readonly TaskServiceInterface $task,
    ) {}

    public function scheduleNightlyReport(): string
    {
        // Configuration surchargée
        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('nightly-report'),
            description: 'Génère le rapport nocturne',
            delay_seconds: new CounterVO(86400), // Une fois par jour
            max_attempts: new CounterVO(3),
            start_at: new Iso8601DateTimeVO('02:00:00'),
            end_at: null,
        );

        $payload = new TaskPayloadRecord(
            type: 'report',
            data: StrictDataObject::from(['format' => 'pdf', 'send_email' => true]),
        );

        return $this->task->register(NightlyReportTask::class, $payload, $config);
    }

    public function getReportTaskStatus(): array
    {
        $task = $this->task->findRecurringTask(new TaskSignatureVO('nightly-report'));

        if (!$task) {
            return ['status' => 'not_scheduled'];
        }

        return [
            'status' => 'scheduled',
            'success_count' => $task->success_count->value,
            'failure_count' => $task->failure_count->value,
            'last_run' => $task->last_run_at?->value,
            'next_run' => $task->next_run_at->value,
        ];
    }
}
```

## Flux d'exécution

```
TaskService (wrapper)
    │
    ├── registry → TaskRegistryServiceInterface
    ├── runner → TaskRunnerServiceInterface
    ├── validator → TaskValidatorServiceInterface
    ├── batch → TaskBatchServiceInterface
    ├── batchResult → BatchResultServiceInterface
    └── finder → TaskFinderServiceInterface
```

Chaque appel est délégué sans traitement intermédiaire :

```
$this->task->register(...)
    │
    └── $this->registry->register(...)

$this->task->process(...)
    │
    └── $this->batch->process(...)

$this->task->findTask(...)
    │
    └── $this->finder->findTask(...)
```

## Gestion des erreurs

Les exceptions sont propagées telles quelles depuis les services sous-jacents :

| Situation | Exception propagée |
|-----------|-------------------|
| Classe de tâche invalide | `InvalidArgumentException` |
| Tâche récurrente déjà existante | `RuntimeException` |
| Tâche non trouvée lors de la suppression | `RuntimeException` |
| Format d'identifiant invalide | `InvalidArgumentException` |

## Intégration

### Dépendances injectées

```php
public function __construct(
    private readonly TaskRegistryServiceInterface $registry,
    private readonly TaskRunnerServiceInterface $runner,
    private readonly TaskValidatorServiceInterface $validator,
    private readonly TaskBatchServiceInterface $batch,
    private readonly BatchResultServiceInterface $batchResult,
    private readonly TaskFinderServiceInterface $finder,
) {}
```

### Enregistrement dans TaskServiceProvider

```php
$this->app->singleton(TaskServiceInterface::class, function (Application $app) {
    return new TaskService(
        registry: $app->make(TaskRegistryServiceInterface::class),
        runner: $app->make(TaskRunnerServiceInterface::class),
        validator: $app->make(TaskValidatorServiceInterface::class),
        batch: $app->make(TaskBatchServiceInterface::class),
        batchResult: $app->make(BatchResultServiceInterface::class),
        finder: $app->make(TaskFinderServiceInterface::class),
    );
});
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| Toutes les méthodes | O(1) + délégation | Le wrapper lui-même n'ajoute aucune surcharge |

Le coût est strictement celui du service sous-jacent appelé. Aucune logique supplémentaire n'est exécutée.

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

use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use App\Tasks\SendEmailTask;
use App\Tasks\CleanupTask;

final class TaskOrchestrator
{
    public function __construct(
        private readonly TaskServiceInterface $task,
    ) {}

    public function orchestrate(): array
    {
        $results = [];

        // 1. ENREGISTRER une tâche unique
        $emailPayload = new TaskPayloadRecord(
            type: 'email',
            data: StrictDataObject::from([
                'to' => 'admin@example.com',
                'subject' => 'Welcome',
            ]),
        );

        $taskId = $this->task->register(SendEmailTask::class, $emailPayload);
        $results['unique_task_id'] = $taskId;

        // 2. ENREGISTRER une tâche récurrente
        $cleanupConfig = new TaskConfigRecord(
            signature: new TaskSignatureVO('cleanup-temp'),
            description: 'Nettoyage des fichiers temporaires',
            delay_seconds: new CounterVO(3600),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $cleanupPayload = new TaskPayloadRecord(
            type: 'cleanup',
            data: StrictDataObject::from(['path' => '/tmp', 'days' => 7]),
        );

        $signature = $this->task->register(CleanupTask::class, $cleanupPayload, $cleanupConfig);
        $results['recurring_signature'] = $signature;

        // 3. RECHERCHER
        $found = $this->task->findTask(new TaskIdVO($taskId));
        $results['task_found'] = $found !== null;

        // 4. VALIDER
        if ($found) {
            $results['can_run'] = $this->task->canRunTask($found);
        }

        // 5. COMPTER
        $results['pending_count'] = $this->task->countPendingTasks();
        $results['recurring_count'] = $this->task->countRecurringTasks();

        // 6. LISTER
        $pendingTasks = $this->task->getPendingTasks(5, TaskOrder::OLDEST);
        $results['pending_signatures'] = $pendingTasks->map(fn($t) => $t->signature->value);

        // 7. TRAITER
        $batchResult = $this->task->process(10);
        $results['batch_processed'] = $batchResult->unique_success->value + $batchResult->recurring_success->value;

        // 8. NETTOYAGE (optionnel)
        // $this->task->unregisterTask(new TaskIdVO($taskId));
        // $this->task->unregisterRecurring(new TaskSignatureVO($signature));

        return $results;
    }
}

// Utilisation
$orchestrator = new TaskOrchestrator(app(TaskServiceInterface::class));
$report = $orchestrator->orchestrate();

print_r($report);
```

## Voir aussi

- `TaskServiceInterface` - Interface unifiée
- `TaskRegistryServiceInterface` - Gestion des enregistrements
- `TaskRunnerServiceInterface` - Exécution des tâches
- `TaskValidatorServiceInterface` - Validation
- `TaskBatchServiceInterface` - Traitement par lots
- `BatchResultServiceInterface` - Résultats de batch
- `TaskFinderServiceInterface` - Recherche de tâches
```
---