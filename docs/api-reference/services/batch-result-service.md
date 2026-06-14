# BatchResultService - Référence Technique

## Description

Service immuable pour construire des résultats de traitement par lots en ajoutant des résultats de tâches uniques ou récurrentes. Il utilise les Value Objects `CounterVO` et sépare les erreurs uniques des erreurs récurrentes.

## Hiérarchie

```
BatchResultService
```

La classe n'étend aucune classe parente et n'implémente aucune interface.

## Rôle principal

Fournir des opérations immutables pour enrichir un `BatchResultRecord` avec des résultats de tâches. Chaque méthode prend un enregistrement existant, le clone, y ajoute un résultat, et retourne une nouvelle instance. Aucune méthode ne modifie l'état interne.

## API / Méthodes publiques

### `withUniqueTask(BatchResultRecord $record, UniqueTaskResultRecord $result): BatchResultRecord`

Ajoute le résultat d'une tâche unique (non récurrente) au lot.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `BatchResultRecord` | Enregistrement de résultat actuel |
| `$result` | `UniqueTaskResultRecord` | Résultat de la tâche unique avec son ID, succès et erreur |

**Retourne :** `BatchResultRecord` - Nouvelle instance avec la tâche ajoutée

**Exemple :**
```php
$service = new BatchResultService($hydration);
$result = new UniqueTaskResultRecord(
    task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    success: true,
);
$record = $service->withUniqueTask($emptyRecord, $result);
```

### `withRecurringTask(BatchResultRecord $record, RecurringTaskResultRecord $result): BatchResultRecord`

Ajoute le résultat d'une tâche récurrente au lot.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `BatchResultRecord` | Enregistrement de résultat actuel |
| `$result` | `RecurringTaskResultRecord` | Résultat de la tâche récurrente avec sa signature, succès et erreur |

**Retourne :** `BatchResultRecord` - Nouvelle instance avec la tâche ajoutée

**Exemple :**
```php
$service = new BatchResultService($hydration);
$result = new RecurringTaskResultRecord(
    signature: new TaskSignatureVO('recurring-1'),
    success: false,
    error: 'Timeout',
);
$record = $service->withRecurringTask($emptyRecord, $result);
```

## Cas d'utilisation

### Cas 1 : Construction séquentielle d'un résultat de lot

```php
<?php

declare(strict_types=1);

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\Records\RecurringTaskResultRecord;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\RecurringTaskErrorCollection;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$hydration = new HydrationService();
$service = new BatchResultService($hydration);

// Créer un enregistrement vide
$emptyRecord = new BatchResultRecord(
    started_at: new Iso8601DateTimeVO(),
    unique_success: new CounterVO(0),
    unique_failed: new CounterVO(0),
    recurring_success: new CounterVO(0),
    recurring_failed: new CounterVO(0),
    unique_results: new UniqueResultCollection(),
    recurring_results: new RecurringResultCollection(),
    unique_errors: new TaskErrorCollection(),
    recurring_errors: new RecurringTaskErrorCollection(),
);

// Ajouter des résultats
$record = $service->withUniqueTask($emptyRecord, new UniqueTaskResultRecord(
    task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    success: true,
));

$record = $service->withUniqueTask($record, new UniqueTaskResultRecord(
    task_id: new TaskIdVO('660e8400-e29b-41d4-a716-446655440001'),
    success: false,
    error: 'Connection failed',
));

$record = $service->withRecurringTask($record, new RecurringTaskResultRecord(
    signature: new TaskSignatureVO('recurring-1'),
    success: true,
));

echo $record->unique_success->value;    // 1
echo $record->unique_failed->value;     // 1
echo $record->recurring_success->value; // 1
```

### Cas 2 : Traitement par lots avec agrégation

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;

function processBatch(array $tasks, BatchResultService $service): BatchResultRecord
{
    $record = createEmptyBatchResult();

    foreach ($tasks as $task) {
        try {
            $task->execute();
            $record = $service->withUniqueTask($record, new UniqueTaskResultRecord(
                task_id: new TaskIdVO($task->getId()),
                success: true,
            ));
        } catch (\Exception $e) {
            $record = $service->withUniqueTask($record, new UniqueTaskResultRecord(
                task_id: new TaskIdVO($task->getId()),
                success: false,
                error: $e->getMessage(),
            ));
        }
    }

    return $record;
}
```

### Cas 3 : Immutabilité garantie

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;

$service = new BatchResultService($hydration);
$original = createEmptyBatchResult();

$modified = $service->withUniqueTask($original, new UniqueTaskResultRecord(
    task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    success: true,
));

// L'original reste inchangé
echo $original->unique_success->value;  // 0
echo $modified->unique_success->value;  // 1
```

## Flux d'exécution

```
withUniqueTask() / withRecurringTask()
    │
    ├── Cloner les collections existantes
    │   ├── unique_results / recurring_results
    │   └── unique_errors / recurring_errors
    │
    ├── Ajouter le nouveau résultat
    │   ├── UniqueResultRecord / RecurringResultRecord
    │   └── TaskErrorRecord / RecurringTaskErrorRecord (si échec avec erreur)
    │
    ├── Incrémenter les compteurs CounterVO
    │   ├── unique_success / unique_failed
    │   └── recurring_success / recurring_failed
    │
    └── Retourner un nouveau BatchResultRecord via HydrationService
```

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| Succès d'une tâche | `unique_success` ou `recurring_success` incrémenté |
| Échec sans message d'erreur | `unique_failed` ou `recurring_failed` incrémenté, aucune erreur stockée |
| Échec avec message d'erreur | Compteur d'échec incrémenté, `TaskErrorRecord` ou `RecurringTaskErrorRecord` ajouté avec `ErrorType::TASK_EXECUTION_FAILED` |
| `$result->error === null` | Aucune erreur n'est stockée dans la collection |

## Intégration

### Dépendances

```
BatchResultService
    ├── HydrationService (création d'instances)
    ├── BatchResultRecord (paramètre et retour)
    ├── UniqueTaskResultRecord (paramètre d'entrée)
    ├── RecurringTaskResultRecord (paramètre d'entrée)
    ├── UniqueResultRecord (création interne)
    ├── RecurringResultRecord (création interne)
    ├── TaskErrorRecord (création conditionnelle)
    └── RecurringTaskErrorRecord (création conditionnelle)
```

### Avec TaskBatchService

```php
class TaskBatchService
{
    public function __construct(
        private readonly BatchResultService $batchResultService,
        // ...
    ) {}

    private function executeUniqueTask(BatchResultRecord $result, TaskRecord $task): BatchResultRecord
    {
        // ... exécution ...
        
        return $this->batchResultService->withUniqueTask($result, new UniqueTaskResultRecord(
            $task->id,
            $success,
            $error,
        ));
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `withUniqueTask()` | O(1) + clonage des collections | Clonage superficiel (shallow copy) |
| `withRecurringTask()` | O(1) + clonage des collections | Clonage superficiel (shallow copy) |
| Clonage de `TypedCollection` | O(n) pour n éléments | Les collections sont clonées intégralement |

L'immutabilité garantit l'absence d'effets de bord, mais chaque ajout crée une nouvelle instance et clone les collections.

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Requis (readonly properties) |
| PHP 8.1 | ✅ Complet |
| PHP 8.0 | ❌ (readonly properties non supportées) |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\Records\RecurringTaskResultRecord;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\RecurringTaskErrorCollection;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

// 1. Créer un enregistrement vide
$emptyRecord = new BatchResultRecord(
    started_at: new Iso8601DateTimeVO(),
    unique_success: new CounterVO(0),
    unique_failed: new CounterVO(0),
    recurring_success: new CounterVO(0),
    recurring_failed: new CounterVO(0),
    unique_results: new UniqueResultCollection(),
    recurring_results: new RecurringResultCollection(),
    unique_errors: new TaskErrorCollection(),
    recurring_errors: new RecurringTaskErrorCollection(),
);

// 2. Initialiser le service
$hydration = new HydrationService();
$service = new BatchResultService($hydration);

// 3. Ajouter des résultats de tâches
$record = $service->withUniqueTask($emptyRecord, new UniqueTaskResultRecord(
    task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    success: true,
));

$record = $service->withUniqueTask($record, new UniqueTaskResultRecord(
    task_id: new TaskIdVO('660e8400-e29b-41d4-a716-446655440001'),
    success: false,
    error: 'Task failed',
));

$record = $service->withRecurringTask($record, new RecurringTaskResultRecord(
    signature: new TaskSignatureVO('recurring-1'),
    success: true,
));

$record = $service->withRecurringTask($record, new RecurringTaskResultRecord(
    signature: new TaskSignatureVO('recurring-2'),
    success: false,
    error: 'Recurring task failed',
));

// 4. Lire les résultats
echo "Unique tasks:\n";
echo "  Success: {$record->unique_success->value}\n";
echo "  Failed:  {$record->unique_failed->value}\n";

echo "\nRecurring tasks:\n";
echo "  Success: {$record->recurring_success->value}\n";
echo "  Failed:  {$record->recurring_failed->value}\n";

$total = $record->unique_success->value + $record->unique_failed->value + 
         $record->recurring_success->value + $record->recurring_failed->value;
echo "\nTotal: {$total} tasks\n";

// 5. Afficher les erreurs uniques
if ($record->unique_errors->isNotEmpty()) {
    echo "\nUnique task errors:\n";
    foreach ($record->unique_errors as $error) {
        echo "  - {$error->task_id->value}: {$error->details}\n";
    }
}

// 6. Afficher les erreurs récurrentes
if ($record->recurring_errors->isNotEmpty()) {
    echo "\nRecurring task errors:\n";
    foreach ($record->recurring_errors as $error) {
        echo "  - {$error->signature->value}: {$error->details}\n";
    }
}
```

**Sortie :**
```
Unique tasks:
  Success: 1
  Failed:  1

Recurring tasks:
  Success: 1
  Failed:  1

Total: 4 tasks

Unique task errors:
  - 660e8400-e29b-41d4-a716-446655440001: Task failed

Recurring task errors:
  - recurring-2: Recurring task failed
```

## Voir aussi

- `BatchResultRecord` - Record contenant les résultats (avec CounterVO)
- `UniqueTaskResultRecord` - Résultat d'une tâche unique (input)
- `RecurringTaskResultRecord` - Résultat d'une tâche récurrente (input)
- `UniqueResultRecord` - Résultat stocké pour une tâche unique
- `RecurringResultRecord` - Résultat stocké pour une tâche récurrente
- `TaskErrorRecord` - Enregistrement d'erreur pour tâche unique
- `RecurringTaskErrorRecord` - Enregistrement d'erreur pour tâche récurrente
- `CounterVO` - Value Object pour les compteurs
- `TaskBatchService` - Service de traitement par lots qui utilise `BatchResultService`
- `HydrationService` - Service d'hydratation des objets
---