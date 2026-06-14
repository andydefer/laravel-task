# TaskFinderService - Référence Technique

## Description

Service dédié à la recherche et à l'interrogation des tâches (uniques et récurrentes).

## Hiérarchie / Implémentations

```
TaskFinderServiceInterface
    └── TaskFinderService
```


## Rôle principal

Centraliser toutes les opérations de lecture et d'interrogation du système de tâches, sans modification. Ce service agit comme une **couche de requêtes** (Query Layer) séparée des opérations d'écriture (Command Layer).

Il offre des méthodes pour :
- Rechercher une tâche par son identifiant
- Lister les tâches en attente ou récurrentes
- Vérifier l'existence d'une tâche
- Compter les tâches

## API / Méthodes publiques

### `findTask(TaskIdVO $taskId): ?TaskRecord`

Recherche une tâche unique par son identifiant UUID.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | Identifiant UUID de la tâche (ex: `550e8400-e29b-41d4-a716-446655440000`) |

**Retourne :** `TaskRecord|null` - La tâche si elle existe et est en attente (`PENDING`), `null` sinon

**Exemple :**
```php
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');
$task = $finder->findTask($taskId);

if ($task) {
    echo $task->signature->value; // 'backup-database'
}
```

### `findRecurringTask(TaskSignatureVO $signature): ?RecurringTaskRecord`

Recherche une tâche récurrente par sa signature.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signature` | `TaskSignatureVO` | Signature lisible de la tâche (ex: `clear-unconfirmed-orders`) |

**Retourne :** `RecurringTaskRecord|null` - La dernière version de la tâche récurrente, `null` si inexistante

**Exemple :**
```php
$signature = new TaskSignatureVO('clean-logs');
$task = $finder->findRecurringTask($signature);

if ($task) {
    echo $task->success_count->value; // Nombre d'exécutions réussies
}
```

### `getPendingTasks(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection`

Récupère toutes les tâches uniques en attente d'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à retourner (0 = aucun résultat, `null` = toutes) |
| `$order` | `TaskOrder` | Ordre de tri : `OLDEST` (FIFO) ou `NEWEST` (LIFO) |

**Retourne :** `TaskRecordCollection` - Collection typée de `TaskRecord`

**Exemple :**
```php
// Récupérer les 10 tâches les plus anciennes
$pendingTasks = $finder->getPendingTasks(10, TaskOrder::OLDEST);

foreach ($pendingTasks as $task) {
    echo $task->id->value . "\n";
}
```

### `getRecurringTasks(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection`

Récupère toutes les tâches récurrentes enregistrées.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à retourner (0 = aucun résultat, `null` = toutes) |
| `$order` | `TaskOrder|null` | Ordre de tri : `OLDEST` ou `NEWEST` |

**Retourne :** `RecurringTaskRecordCollection` - Collection typée de `RecurringTaskRecord`

**Exemple :**
```php
$recurringTasks = $finder->getRecurringTasks();

foreach ($recurringTasks as $task) {
    echo "{$task->signature->value} - {$task->delay_seconds->value}s\n";
}
```

### `taskExists(TaskIdVO $taskId): bool`

Vérifie si une tâche unique existe et est en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | Identifiant UUID de la tâche |

**Retourne :** `bool` - `true` si la tâche existe, `false` sinon

**Exemple :**
```php
if (!$finder->taskExists($taskId)) {
    throw new \RuntimeException("Task not found");
}
```

### `recurringTaskExists(TaskSignatureVO $signature): bool`

Vérifie si une tâche récurrente existe.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signature` | `TaskSignatureVO` | Signature de la tâche |

**Retourne :** `bool` - `true` si la tâche existe, `false` sinon

**Exemple :**
```php
$signature = new TaskSignatureVO('clean-logs');
if ($finder->recurringTaskExists($signature)) {
    echo "Recurring task exists";
}
```

### `countPendingTasks(): int`

Compte le nombre de tâches uniques en attente.

**Retourne :** `int` - Nombre de tâches en attente

**Exemple :**
```php
$count = $finder->countPendingTasks();
echo "Pending tasks: {$count}";
```

### `countRecurringTasks(): int`

Compte le nombre de tâches récurrentes enregistrées.

**Retourne :** `int` - Nombre de tâches récurrentes

**Exemple :**
```php
$count = $finder->countRecurringTasks();
echo "Recurring tasks: {$count}";
```

## Cas d'utilisation

### Cas 1 : Tableau de bord d'administration

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;

final class DashboardController
{
    public function __construct(
        private readonly TaskFinderServiceInterface $finder,
    ) {}

    public function index(): array
    {
        return [
            'pending_count' => $this->finder->countPendingTasks(),
            'recurring_count' => $this->finder->countRecurringTasks(),
            'latest_pending_tasks' => $this->finder->getPendingTasks(5, TaskOrder::NEWEST),
            'all_recurring_tasks' => $this->finder->getRecurringTasks(),
        ];
    }
}
```

### Cas 2 : Vérification avant suppression

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\ValueObjects\TaskIdVO;

final class TaskDeleter
{
    public function __construct(
        private readonly TaskFinderServiceInterface $finder,
        private readonly TaskRegistryServiceInterface $registry,
    ) {}

    public function deleteTask(string $taskId): void
    {
        $taskIdVO = new TaskIdVO($taskId);

        if (!$this->finder->taskExists($taskIdVO)) {
            throw new \RuntimeException("Task {$taskId} does not exist");
        }

        $this->registry->unregisterTask($taskIdVO);
    }
}
```

### Cas 3 : Traitement par lots personnalisé

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\Enums\TaskOrder;

final class CustomBatchProcessor
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly TaskFinderServiceInterface $finder,
        private readonly TaskRunnerServiceInterface $runner,
    ) {}

    public function processOldestTasks(): void
    {
        // Traiter les 50 tâches les plus anciennes d'abord
        $tasks = $this->finder->getPendingTasks(self::BATCH_SIZE, TaskOrder::OLDEST);

        foreach ($tasks as $task) {
            $this->runner->runTask($task);
        }
    }
}
```

## Flux d'exécution

```
findTask(TaskIdVO)
    │
    ├── TaskRepository::find($taskId)
    │       ├── Lecture du fichier pending/{taskId}.jsonl
    │       └── Hydratation en TaskRecord
    │
    └── Retourne TaskRecord|null

getPendingTasks($limit, $order)
    │
    ├── TaskRepository::findAll($limit, $order)
    │       ├── Lecture de tous les fichiers pending/*.jsonl
    │       ├── Tri selon TaskOrder::compare()
    │       ├── Application de la limite
    │       └── Hydratation des tâches
    │
    └── Retourne TaskRecordCollection

countPendingTasks()
    │
    ├── TaskRepository::findAll()
    └── Retourne $collection->count()
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Aucune exception levée par ce service | - | - |

Ce service est **read-only** : il ne modifie aucune donnée et ne lève pas d'exception directe. Les exceptions peuvent provenir des Value Objects (constructeurs) ou des repositories sous-jacents.

## Intégration

### Dépendances injectées

```
TaskFinderService
    ├── TaskRepositoryInterface (recherche tâches uniques)
    └── RecurringTaskRepositoryInterface (recherche tâches récurrentes)
```

### Utilisation dans TaskService (wrapper)

```php
final class TaskService implements TaskServiceInterface
{
    public function __construct(
        // ...
        private readonly TaskFinderServiceInterface $finder,
    ) {}

    public function findTask(TaskIdVO $taskId): ?TaskRecord
    {
        return $this->finder->findTask($taskId);
    }

    public function countPendingTasks(): int
    {
        return $this->finder->countPendingTasks();
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `findTask()` | O(1) | Lecture d'un seul fichier JSONL |
| `findRecurringTask()` | O(1) | Lecture du fichier + dernière ligne |
| `getPendingTasks()` | O(n log n) | n = nombre de fichiers, tri inclus |
| `getRecurringTasks()` | O(m log m) | m = nombre de fichiers récurrents |
| `taskExists()` | O(1) | Délégation à `findTask()` |
| `countPendingTasks()` | O(n) | Parcours complet de tous les fichiers |

**Optimisation :** Les méthodes `countPendingTasks()` et `countRecurringTasks()` parcourent tous les fichiers. À utiliser avec parcimonie sur de gros volumes.

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

use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class TaskReportingService
{
    public function __construct(
        private readonly TaskFinderServiceInterface $finder,
    ) {}

    public function generateReport(): array
    {
        // Compteurs
        $pendingCount = $this->finder->countPendingTasks();
        $recurringCount = $this->finder->countRecurringTasks();

        // Tâches récentes
        $recentTasks = $this->finder->getPendingTasks(10, TaskOrder::NEWEST);

        // Vérifications d'existence
        $exists = $this->finder->taskExists(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));

        return [
            'summary' => [
                'pending' => $pendingCount,
                'recurring' => $recurringCount,
                'total' => $pendingCount + $recurringCount,
            ],
            'recent_tasks' => $recentTasks->toArray(),
            'specific_task_exists' => $exists,
        ];
    }
}

// Utilisation
$reporter = new TaskReportingService($finder);
$report = $reporter->generateReport();

echo "Pending tasks: {$report['summary']['pending']}\n";
echo "Recurring tasks: {$report['summary']['recurring']}\n";
```

## Voir aussi

- `TaskFinderServiceInterface` - Interface implémentée
- `TaskRepositoryInterface` - Repository pour les tâches uniques
- `RecurringTaskRepositoryInterface` - Repository pour les tâches récurrentes
- `TaskService` - Wrapper unifié utilisant ce service
- `TaskOrder` - Enum pour l'ordre de tri
- `TaskIdVO` / `TaskSignatureVO` - Value Objects d'identifiants
---