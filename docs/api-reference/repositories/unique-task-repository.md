# UniqueTaskRepository - Référence Technique

## Description

Repository pour la gestion des tâches uniques. Fournit les opérations de persistance, de recherche par statut, de transitions d'état et de verrouillage pour l'exécution concurrente.

## Hiérarchie

```
AbstractRepository<UniqueTask, UniqueTaskRecord>
    └── UniqueTaskRepository
        └── UniqueTaskRepositoryInterface
```

## Rôle principal

Gère le cycle de vie des tâches uniques : création, récupération, mise à jour des états (PENDING → IN_PROGRESS → COMPLETED/FAILED/CANCELED), et verrouillage pour éviter les exécutions concurrentes.

---

## API / Méthodes publiques

### `findPending(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, UniqueTask>` - Collection des tâches en attente

**Exemple :**
```php
$pending = $repository->findPending(new LimitVO(10));
foreach ($pending as $task) {
    echo $task->getAlias()->getValue();
}
```

---

### `findCompleted(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, UniqueTask>` - Collection des tâches terminées avec succès

**Exemple :**
```php
$completed = $repository->findCompleted(new LimitVO(50));
```

---

### `findFailed(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, UniqueTask>` - Collection des tâches en échec

---

### `findCanceled(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, UniqueTask>` - Collection des tâches annulées

---

### `findReadyToRun(Iso8601DateTimeVO $now, ?LimitVO $limit = null, ?TaskFqcnVOCollection $fqcns = null): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO` | Date/heure de référence |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats |
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `Collection<int, UniqueTask>` - Tâches prêtes à être exécutées

**Comportement :**
1. Sélectionne les tâches en statut `PENDING` avec `scheduled_at <= now`
2. Applique le filtre FQCN si fourni
3. Verrouille les lignes avec `lockForUpdate()`
4. Passe les tâches en statut `IN_PROGRESS`
5. Retourne les tâches verrouillées

**Exemple :**
```php
$now = new Iso8601DateTimeVO;
$fqcns = TaskFqcnVOCollection::from([TestUniqueTask::class]);
$tasks = $repository->findReadyToRun($now, new LimitVO(10), $fqcns);
```

---

### `findExpired(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO` | Date/heure de référence |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats |

**Retourne :** `Collection<int, UniqueTask>` - Tâches expirées

**Critère d'expiration :** `scheduled_at + grace_period_seconds < now`

**Exemple :**
```php
$expired = $repository->findExpired(new Iso8601DateTimeVO);
```

---

### `findById(UuidVO $id): ?UniqueTask`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `UuidVO` | Identifiant de la tâche |

**Retourne :** `UniqueTask|null` - La tâche trouvée ou `null`

---

### `findByAlias(TaskAliasVO $alias): ?UniqueTask`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche (format `unique@{uuid}`) |

**Retourne :** `UniqueTask|null` - La tâche trouvée ou `null`

**Exemple :**
```php
$alias = new TaskAliasVO('unique@550e8400-e29b-41d4-a716-446655440000');
$task = $repository->findByAlias($alias);
```

---

### `updateAttempts(UniqueTaskRecord $task, CounterVO $newAttempts): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Record de la tâche |
| `$newAttempts` | `CounterVO` | Nouveau nombre de tentatives |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Comportement :**
- Met à jour `attempts` et passe le statut à `PENDING`
- La tâche doit être en statut `IN_PROGRESS`

---

### `addDebug(UniqueTaskRecord $task, ExecutionStatus $status, DescriptionVO $info): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Record de la tâche |
| `$status` | `ExecutionStatus` | Statut d'exécution |
| `$info` | `DescriptionVO` | Informations de débogage |

**Retourne :** `bool` - `true` si l'ajout a réussi

---

### `moveToCompleted(UniqueTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `COMPLETED`, `finished_at` = maintenant

---

### `moveToFailed(UniqueTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `FAILED`, `finished_at` = maintenant

---

### `moveToCanceled(UniqueTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `CANCELED`, `finished_at` = maintenant

---

### Méthodes de comptage

Chaque méthode de comptage accepte un filtre FQCN optionnel :

```php
public function countPending(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countCompleted(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countFailed(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countCanceled(?TaskFqcnVOCollection $fqcns = null): CounterVO
```

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `CounterVO` - Le nombre de tâches

**Exemple :**
```php
$fqcns = TaskFqcnVOCollection::from([TestUniqueTask::class]);
$count = $repository->countPending($fqcns);
echo "Tâches en attente: " . $count->getValue();
```

---

### `applyFilters(Builder $query, AbstractRecord $filters): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `Builder` | La requête Eloquent |
| `$filters` | `AbstractRecord` | Les filtres à appliquer |

**Retourne :** `void`

**Filtres supportés :**
- `id` : Filtre par UUID
- `alias` : Filtre par alias exact
- `fqcn` : Filtre par classe complète
- `status` : Filtre par statut
- `scheduled_at_from` / `scheduled_at_to` : Plage de planification
- `finished_at_from` / `finished_at_to` : Plage de fin
- `attempts` : Nombre exact de tentatives
- `max_attempts` : Nombre maximal de tentatives

---

## Cas d'utilisation

### Cas 1 : Récupération des tâches prêtes avec filtrage FQCN

```php
$now = new Iso8601DateTimeVO;
$limit = new LimitVO(50);

// Filtrer uniquement les tâches TestUniqueTask
$fqcns = TaskFqcnVOCollection::from([TestUniqueTask::class]);

$tasks = $repository->findReadyToRun($now, $limit, $fqcns);

foreach ($tasks as $task) {
    // Traiter la tâche...
    $this->processTask($task);
}
```

### Cas 2 : Cycle de vie d'une tâche

```php
// 1. Trouver une tâche à exécuter
$now = new Iso8601DateTimeVO;
$tasks = $repository->findReadyToRun($now, new LimitVO(1));

if ($tasks->isEmpty()) {
    return;
}

$task = $tasks->first();
$record = $this->modelToRecord($task);

try {
    // 2. Exécuter la tâche
    $this->executeTask($task);
    
    // 3. Marquer comme terminée
    $repository->moveToCompleted($record);
} catch (Throwable $e) {
    // 4. Gérer l'erreur
    $repository->moveToFailed($record);
    $repository->addDebug($record, ExecutionStatus::FAILED, new DescriptionVO($e->getMessage()));
}
```

### Cas 3 : Réessayer une tâche échouée

```php
// Récupérer une tâche en IN_PROGRESS qui a échoué
$failedTasks = $repository->findFailed(new LimitVO(10));

foreach ($failedTasks as $task) {
    $record = $this->modelToRecord($task);
    $newAttempts = $record->attempts->increment();
    
    // Remettre en file d'attente si max_attempts pas atteint
    if ($newAttempts->getValue() < $record->max_attempts->getValue()) {
        $repository->updateAttempts($record, $newAttempts);
    }
}
```

### Cas 4 : Nettoyage des tâches expirées

```php
$now = new Iso8601DateTimeVO;
$expired = $repository->findExpired($now);

foreach ($expired as $task) {
    $record = $this->modelToRecord($task);
    $repository->moveToFailed($record);
    $repository->addDebug($record, ExecutionStatus::FAILED, new DescriptionVO('Task expired'));
}
```

### Cas 5 : Filtrage avancé avec `applyFilters`

```php
$filters = UniqueTaskFiltersRecord::from([
    'status' => UniqueTaskStatus::PENDING,
    'attempts' => new CounterVO(2),
    'scheduled_at_from' => new Iso8601DateTimeVO('2026-01-01T00:00:00+00:00'),
    'scheduled_at_to' => new Iso8601DateTimeVO('2026-12-31T23:59:59+00:00'),
]);

$results = $repository->findBy(
    FindByRecord::from(['filters' => $filters])
);
```

---

## Flux d'exécution

```
1. findReadyToRun() est appelée
   ↓
2. Transaction DB avec lockForUpdate()
   ↓
3. Sélection des tâches PENDING avec scheduled_at <= now
   ↓
4. Filtrage FQCN si fourni
   ↓
5. Verrouillage des lignes sélectionnées
   ↓
6. Mise à jour en lot : PENDING → IN_PROGRESS
   ↓
7. Retour des tâches verrouillées
```

### Diagramme des transitions d'état

```
┌─────────┐
│ PENDING │
└────┬────┘
     │ findReadyToRun()
     ▼
┌─────────────┐      ┌───────────┐      ┌───────────┐
│ IN_PROGRESS │──────│ COMPLETED │      │  FAILED   │
└─────────────┘      └───────────┘      └───────────┘
     │ updateAttempts()
     ▼
┌─────────┐      ┌───────────┐
│ PENDING │──────│ CANCELED  │
└─────────┘      └───────────┘
```

---

## Gestion des erreurs

| Situation | Log | Message |
|-----------|-----|---------|
| `updateAttempts` échoue (tâche non trouvée) | `error` | `unique_task_update_attempts_not_found` |
| `updateAttempts` exception | `error` | `unique_task_update_attempts_error` |
| `addDebug` échoue | `error` | `unique_task_add_debug_error` |
| `moveToCompleted` échoue | `error` | `unique_task_move_to_completed_not_found_or_already_completed` |
| `moveToFailed` échoue | `error` | `unique_task_move_to_failed_not_found_or_already_failed` |
| `moveToCanceled` échoue | `error` | `unique_task_move_to_canceled_not_found_or_already_canceled` |
| `findReadyToRun` exception | `error` | `unique_task_find_ready_to_run_error` |
| `findExpired` exception | `error` | `unique_task_find_expired_error` |

---

## Intégration

### Avec `UniqueTaskService`

Le repository est utilisé par `UniqueTaskService` pour :

1. **Enregistrement** : `create()` pour persister une nouvelle tâche
2. **Exécution** : `findReadyToRun()` pour récupérer les tâches à exécuter
3. **Transitions** : `moveToCompleted()`, `moveToFailed()`, `moveToCanceled()`
4. **Comptage** : `countPending()`, `countCompleted()`, etc.

### Avec `UniqueTaskLogger`

Le repository utilise `LoggerInterface` pour journaliser les erreurs.

### Avec `TaskExecutionDebugRepository`

Le repository délègue l'ajout des informations de débogage au `TaskExecutionDebugRepository`.

---

## Performance

### Points d'attention

| Opération | Complexité | Risque |
|-----------|------------|--------|
| `findReadyToRun` | O(n) avec lock | ⚠️ Transactions longues |
| `findExpired` | O(n) | ⚠️ Requête sur `scheduled_at + grace_period` |
| `countPending` | O(1) avec index | ✅ Indexé sur `status` |

### Optimisations

- Le verrouillage `lockForUpdate()` est **essentiel** pour éviter les exécutions concurrentes
- Les requêtes de comptage utilisent les index sur `status`
- Le filtrage FQCN utilise `whereIn` (indexé sur `fqcn`)

### Recommandations

```php
// ✅ Utiliser un limit pour éviter de verrouiller trop de lignes
$tasks = $repository->findReadyToRun($now, new LimitVO(50));

// ✅ Utiliser l'option FQCN pour réduire le jeu de résultats
$fqcns = TaskFqcnVOCollection::from([$specificTaskClass]);
$tasks = $repository->findReadyToRun($now, $limit, $fqcns);
```

### Index recommandés

```sql
-- Pour findReadyToRun
CREATE INDEX idx_unique_tasks_ready ON unique_tasks (status, scheduled_at);

-- Pour countPending
CREATE INDEX idx_unique_tasks_status ON unique_tasks (status);

-- Pour le filtrage FQCN
CREATE INDEX idx_unique_tasks_fqcn ON unique_tasks (fqcn);

-- Pour findExpired
CREATE INDEX idx_unique_tasks_expired ON unique_tasks (status, scheduled_at, grace_period_seconds);
```

---

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 11+ | ✅ Complet |
| MySQL 5.7+ | ✅ Complet |
| PostgreSQL 12+ | ✅ Complet |
| SQLite 3.35+ | ✅ Complet |

---

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\DescriptionVO;

// 1. Instanciation du repository
$repository = app(UniqueTaskRepository::class);

// 2. Compter les tâches en attente
$pendingCount = $repository->countPending();
echo "Tâches en attente : " . $pendingCount->getValue() . "\n";

// 3. Compter avec filtre FQCN
$fqcns = TaskFqcnVOCollection::from([TestUniqueTask::class]);
$filteredCount = $repository->countPending($fqcns);
echo "Tâches en attente (TestUniqueTask) : " . $filteredCount->getValue() . "\n";

// 4. Récupérer les tâches prêtes
$now = new Iso8601DateTimeVO;
$limit = new LimitVO(10);

$tasks = $repository->findReadyToRun($now, $limit, $fqcns);

foreach ($tasks as $task) {
    echo "Traitement de : " . $task->getAlias()->getValue() . "\n";
    
    $record = $this->modelToRecord($task);
    
    try {
        // 5. Traiter la tâche
        $task->process();
        
        // 6. Marquer comme terminée
        $repository->moveToCompleted($record);
        echo "✅ Tâche terminée avec succès\n";
        
    } catch (Throwable $e) {
        // 7. Gérer l'échec
        $repository->moveToFailed($record);
        $repository->addDebug(
            $record,
            ExecutionStatus::FAILED,
            new DescriptionVO($e->getMessage())
        );
        echo "❌ Tâche échouée : " . $e->getMessage() . "\n";
    }
}

// 8. Nettoyer les tâches expirées
$expired = $repository->findExpired($now);
foreach ($expired as $task) {
    $record = $this->modelToRecord($task);
    $repository->moveToFailed($record);
    echo "🗑️ Tâche expirée : " . $task->getAlias()->getValue() . "\n";
}

// 9. Statistiques finales
echo "📊 Statistiques finales :\n";
echo "  PENDING : " . $repository->countPending()->getValue() . "\n";
echo "  COMPLETED : " . $repository->countCompleted()->getValue() . "\n";
echo "  FAILED : " . $repository->countFailed()->getValue() . "\n";
echo "  CANCELED : " . $repository->countCanceled()->getValue() . "\n";
```

---

## Voir aussi

- `RecurringTaskRepository` - Repository pour les tâches récurrentes
- `TaskExecutionDebugRepository` - Gestion des logs de débogage
- `AbstractRepository` - Classe de base des repositories
- `UniqueTaskService` - Service de gestion des tâches uniques
- `UniqueTaskStatus` - Énumération des statuts possibles
- `UniqueTaskFiltersRecord` - Record de filtrage
