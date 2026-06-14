# TaskBatchService - Référence Technique

## Description

Service qui orchestre l'exécution par lots de tâches planifiées, avec prise en charge des tâches uniques et récurrentes, des limites configurables, du filtrage et des repositories pour la persistance.

## Hiérarchie

```
TaskProcessorInterface
    └── TaskBatchService
```

## Rôle principal

Orchestrer le traitement des tâches en attente en utilisant les repositories (`TaskRepositoryInterface`, `RecurringTaskRepositoryInterface`) pour récupérer les tâches, en respectant les limites configurées, en filtrant par type (unique/récurrent) et en agrégeant les résultats dans un `BatchResultRecord` immuable avec des `CounterVO`.

## API / Méthodes publiques

### `__construct(TaskRepositoryInterface $taskRepository, RecurringTaskRepositoryInterface $recurringTaskRepository, TaskRunnerService $runner, TaskValidatorService $validator, LoggerInterface $logger, BatchResultService $batchResultService, TaskConfig $config, HydrationService $hydration): void`

Injecte les dépendances nécessaires au traitement des tâches.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskRepository` | `TaskRepositoryInterface` | Repository pour les tâches uniques |
| `$recurringTaskRepository` | `RecurringTaskRepositoryInterface` | Repository pour les tâches récurrentes |
| `$runner` | `TaskRunnerService` | Service d'exécution des tâches |
| `$validator` | `TaskValidatorService` | Service de validation des tâches |
| `$logger` | `LoggerInterface` | Service de journalisation |
| `$batchResultService` | `BatchResultService` | Service de construction des résultats |
| `$config` | `TaskConfig` | Configuration du traitement par lots |
| `$hydration` | `HydrationService` | Service d'hydratation des objets |

### `process(?int $limit = null): BatchResultRecord`

Traite toutes les tâches en attente (uniques et récurrentes) en respectant la limite spécifiée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à traiter (0 = aucune, null = config) |

**Retourne :** `BatchResultRecord` - Résultat du traitement avec compteurs `CounterVO`

**Exemple :**
```php
$result = $batchService->process(50);
echo $result->unique_success->value;    // 35
echo $result->recurring_success->value; // 15
```

### `processUniqueOnly(?int $limit = null): BatchResultRecord`

Traite uniquement les tâches uniques (non récurrentes).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à traiter |

**Retourne :** `BatchResultRecord` - Résultat du traitement

**Exemple :**
```php
$result = $batchService->processUniqueOnly(25);
```

### `processRecurringOnly(?int $limit = null): BatchResultRecord`

Traite uniquement les tâches récurrentes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à traiter |

**Retourne :** `BatchResultRecord` - Résultat du traitement

**Exemple :**
```php
$result = $batchService->processRecurringOnly(10);
```

## Flux d'exécution

```
process() / processUniqueOnly() / processRecurringOnly()
    │
    ├── logBatchStart()
    │
    ├── createEmptyRecord()
    │   └── HydrationService::hydrate(BatchResultRecord::class)
    │
    ├── processUniqueTasks() [si nécessaire]
    │   ├── TaskRepository::findAll($limit, $order)
    │   └── Pour chaque tâche : executeUniqueTask()
    │       ├── TaskValidatorService::canRunTask()
    │       ├── TaskRunnerService::runTask()
    │       ├── logTaskResult()
    │       └── BatchResultService::withUniqueTask()
    │
    ├── processRecurringTasks() [si nécessaire]
    │   ├── RecurringTaskRepository::findAll($limit, $order)
    │   └── Pour chaque tâche : executeRecurringTask()
    │       ├── TaskValidatorService::shouldRunRecurringNow()
    │       ├── TaskRunnerService::runRecurringTask()
    │       ├── logTaskResult()
    │       └── BatchResultService::withRecurringTask()
    │
    └── logBatchComplete()
```

## Cas d'utilisation

### Cas 1 : Traitement standard avec limite

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskBatchService;

$batchService = app(TaskBatchService::class);

// Traite jusqu'à 100 tâches
$result = $batchService->process(100);

echo "Tâches uniques réussies : {$result->unique_success->value}\n";
echo "Tâches uniques échouées : {$result->unique_failed->value}\n";
echo "Tâches récurrentes réussies : {$result->recurring_success->value}\n";
echo "Tâches récurrentes échouées : {$result->recurring_failed->value}\n";

// Afficher les erreurs uniques
foreach ($result->unique_errors as $error) {
    echo "Erreur unique: {$error->task_id->value} - {$error->details}\n";
}

// Afficher les erreurs récurrentes
foreach ($result->recurring_errors as $error) {
    echo "Erreur récurrente: {$error->signature->value} - {$error->details}\n";
}
```

### Cas 2 : Traitement sans limite (config par défaut)

```php
<?php

declare(strict_types=1);

// Traite toutes les tâches disponibles (limite configurée dans task.batch.limit)
$result = $batchService->process();
```

### Cas 3 : Traitement séparé par type

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskBatchService;

final class PriorityBatchProcessor
{
    public function __construct(
        private readonly TaskBatchService $batch
    ) {}

    public function processWithPriority(int $totalLimit): array
    {
        // Traitement prioritaire des tâches uniques
        $uniqueResult = $this->batch->processUniqueOnly($totalLimit);
        
        $uniqueProcessed = $uniqueResult->unique_success->value + $uniqueResult->unique_failed->value;
        $remaining = $totalLimit - $uniqueProcessed;
        
        // Traitement des tâches récurrentes avec le reste du quota
        $recurringResult = $remaining > 0 
            ? $this->batch->processRecurringOnly($remaining)
            : null;
        
        return [
            'unique' => $uniqueResult,
            'recurring' => $recurringResult,
        ];
    }
}
```

### Cas 4 : Ordre de traitement personnalisé (FIFO vs LIFO)

```php
<?php

declare(strict_types=1);

// Configuration dans config/task.php
return [
    'batch' => [
        'order' => 'newest',  // LIFO : les tâches les plus récentes d'abord
        // ou 'oldest'        // FIFO : les plus anciennes d'abord (défaut)
    ],
];
```

## Gestion des erreurs

| Situation | Comportement | Code retour |
|-----------|--------------|-------------|
| Tâche non exécutable (état invalide) | Log comme échec, `ErrorType::TASK_VALIDATION_FAILED` | `false` dans le résultat |
| Exception pendant l'exécution | Capture, log, `ErrorType::TASK_EXECUTION_FAILED` | `false` dans le résultat |
| Limite atteinte | Arrête le traitement après avoir atteint `$limit` | Résultat partiel |
| Pas de tâches en attente | Retourne un résultat vide | Compteurs à zéro |
| Limite = 0 | Ne traite aucune tâche | Retourne un résultat vide |

## Intégration

### Dépendances

```
TaskBatchService
    ├── TaskRepositoryInterface (persistance des tâches uniques)
    ├── RecurringTaskRepositoryInterface (persistance des tâches récurrentes)
    ├── TaskRunnerService (exécution)
    ├── TaskValidatorService (validation)
    ├── BatchResultService (agrégation)
    ├── TaskConfig (configuration)
    ├── LoggerInterface (journalisation)
    └── HydrationService (création d'objets)
```

### Avec Laravel

```php
<?php

namespace App\Http\Controllers\Api;

use AndyDefer\Task\Services\TaskBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BatchController extends Controller
{
    public function __construct(
        private readonly TaskBatchService $batch
    ) {}

    public function process(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 50);
        $type = $request->string('type', 'all');
        
        $result = match ($type) {
            'unique' => $this->batch->processUniqueOnly($limit),
            'recurring' => $this->batch->processRecurringOnly($limit),
            default => $this->batch->process($limit),
        };
        
        return response()->json([
            'success' => true,
            'data' => [
                'unique_success' => $result->unique_success->value,
                'unique_failed' => $result->unique_failed->value,
                'recurring_success' => $result->recurring_success->value,
                'recurring_failed' => $result->recurring_failed->value,
                'unique_errors' => $result->unique_errors->toArray(),
                'recurring_errors' => $result->recurring_errors->toArray(),
            ],
        ]);
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `process()` | O(n) | n = nombre de tâches traitées |
| `processUniqueOnly()` | O(n) | n = nombre de tâches uniques |
| `processRecurringOnly()` | O(n) | n = nombre de tâches récurrentes |
| Récupération des tâches | O(k log k) | Tri par timestamp via `TaskOrder::compare()` |
| Agrégation des résultats | O(1) par tâche | Via `BatchResultService` immuable |

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

use AndyDefer\Task\Services\TaskBatchService;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class DailyBatchProcessor
{
    public function __construct(
        private readonly TaskBatchService $batch
    ) {}

    public function processDailyBatch(int $uniqueLimit = 100, int $recurringLimit = 200): array
    {
        $startedAt = new Iso8601DateTimeVO();
        
        // Traitement des tâches uniques (prioritaires)
        $uniqueResult = $this->batch->processUniqueOnly($uniqueLimit);
        
        // Traitement des tâches récurrentes
        $recurringResult = $this->batch->processRecurringOnly($recurringLimit);
        
        $endedAt = new Iso8601DateTimeVO();
        
        $totalUnique = $uniqueResult->unique_success->value + $uniqueResult->unique_failed->value;
        $totalRecurring = $recurringResult->recurring_success->value + $recurringResult->recurring_failed->value;
        
        return [
            'started_at' => $startedAt->value,
            'ended_at' => $endedAt->value,
            'duration_ms' => $this->calculateDuration($startedAt, $endedAt),
            'unique' => [
                'success' => $uniqueResult->unique_success->value,
                'failed' => $uniqueResult->unique_failed->value,
                'total' => $totalUnique,
                'errors' => $uniqueResult->unique_errors->toArray(),
            ],
            'recurring' => [
                'success' => $recurringResult->recurring_success->value,
                'failed' => $recurringResult->recurring_failed->value,
                'total' => $totalRecurring,
                'errors' => $recurringResult->recurring_errors->toArray(),
            ],
            'grand_total' => $totalUnique + $totalRecurring,
        ];
    }
    
    private function calculateDuration(Iso8601DateTimeVO $start, Iso8601DateTimeVO $end): int
    {
        return ($end->toDateTime()->getTimestamp() - $start->toDateTime()->getTimestamp()) * 1000;
    }
}

// Utilisation
$processor = new DailyBatchProcessor(app(TaskBatchService::class));
$result = $processor->processDailyBatch(50, 100);
```

## Voir aussi

- `TaskProcessorInterface` - Interface implémentée
- `BatchResultRecord` - Record de résultat (avec CounterVO)
- `TaskRepositoryInterface` - Repository pour les tâches uniques
- `RecurringTaskRepositoryInterface` - Repository pour les tâches récurrentes
- `TaskRunnerService` - Service d'exécution
- `TaskValidatorService` - Service de validation
- `BatchResultService` - Service de construction des résultats
- `TaskConfig` - Configuration du système
- `CounterVO` - Value Object pour les compteurs
- `TaskOrder` - Enum pour l'ordre de traitement (OLDEST/NEWEST)
- `BatchMode` - Enum pour le mode de traitement (FULL/UNIQUE_ONLY/RECURRING_ONLY)