# RecurringTaskRepository - Référence Technique

## Description

Repository pour la gestion des tâches récurrentes. Fournit les opérations de persistance, de recherche par statut, de transitions d'état et de gestion des cycles d'exécution pour les tâches qui s'exécutent à intervalles réguliers.

## Hiérarchie

```
AbstractRepository<RecurringTask, RecurringTaskRecord>
    └── RecurringTaskRepository
        └── RecurringTaskRepositoryInterface
```

## Rôle principal

Gère le cycle de vie des tâches récurrentes : enregistrement, transitions d'état (WAITING → PLAYING → FINISHED/CANCELED), calcul des prochaines exécutions basé sur l'intervalle, et gestion des échecs avec tentatives.

---

## États possibles

| État | Description |
|------|-------------|
| `WAITING` | En attente de démarrage (`start_at` dans le futur) |
| `PLAYING` | Active, prête à être exécutée à intervalles réguliers |
| `PAUSED` | Suspendue temporairement (peut être reprise) |
| `FINISHED` | Terminée (fin de la période `end_at` atteinte) |
| `CANCELED` | Annulée définitivement |

---

## API / Méthodes publiques

### `findWaiting(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, RecurringTask>` - Collection des tâches en attente

---

### `findPlaying(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, RecurringTask>` - Collection des tâches actives

---

### `findPaused(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, RecurringTask>` - Collection des tâches en pause

---

### `findFinished(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, RecurringTask>` - Collection des tâches terminées

---

### `findCanceled(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `Collection<int, RecurringTask>` - Collection des tâches annulées

---

### `findReadyToRun(?Iso8601DateTimeVO $now = null, ?LimitVO $limit = null, ?TaskFqcnVOCollection $fqcns = null): RecurringTaskReadyToRunResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO|null` | Date/heure de référence (défaut: maintenant) |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats |
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `RecurringTaskReadyToRunResultRecord` - Résultat contenant les tâches prêtes et l'état frais

**Comportement :**
1. Met à jour l'état des tâches (`freshState()`)
2. Sélectionne les tâches en statut `PLAYING`
3. Filtre par FQCN si fourni
4. Filtre les tâches dont `last_run_at + interval_seconds <= now`
5. Applique la limite si fournie

**Exemple :**
```php
$now = new Iso8601DateTimeVO;
$result = $repository->findReadyToRun($now, new LimitVO(10));

$tasks = $result->tasks;
$freshState = $result->fresh_state;

echo "WAITING → PLAYING: " . $freshState->waiting_to_playing->getValue() . "\n";
```

---

### `findByAlias(TaskAliasVO $alias): ?RecurringTask`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche (format `recurring@{uuid}`) |

**Retourne :** `RecurringTask|null` - La tâche trouvée ou `null`

---

### `moveToPlaying(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `PLAYING`

---

### `moveToPaused(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `PAUSED`

---

### `moveToWaiting(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `WAITING`

---

### `moveToFinished(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `FINISHED`, `finished_at` = maintenant

---

### `moveToCanceled(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Record de la tâche |

**Retourne :** `bool` - `true` si le changement d'état a réussi

**Effet :** Statut → `CANCELED`, `finished_at` = maintenant, `cancelled_at` = maintenant

---

### `updateAfterRun(RecurringTaskRecord $task, bool $success, ?DescriptionVO $error = null): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Record de la tâche |
| `$success` | `bool` | Succès de l'exécution |
| `$error` | `DescriptionVO|null` | Message d'erreur si échec |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Comportement :**
- Met à jour `last_run_at` = maintenant
- Si succès : réinitialise `failed_attempts` à 0
- Si échec : incrémente `failed_attempts` de 1
- Ajoute des informations de débogage

---

### Méthodes de comptage

Chaque méthode de comptage accepte un filtre FQCN optionnel :

```php
public function countWaiting(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countPlaying(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countPaused(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countFinished(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countCanceled(?TaskFqcnVOCollection $fqcns = null): CounterVO
```

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `CounterVO` - Le nombre de tâches

**Exemple :**
```php
$fqcns = TaskFqcnVOCollection::from([TestRecurringTask::class]);
$count = $repository->countPlaying($fqcns);
echo "Tâches actives: " . $count->getValue();
```

---

### `applyFilters(Builder $query, AbstractRecord $filters): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `Builder` | La requête Eloquent |
| `$filters` | `AbstractRecord` | Les filtres à appliquer |

**Retourne :** `void`

**Filtres supportés :**
- `alias` : Filtre par alias exact
- `fqcn` : Filtre par classe complète
- `status` : Filtre par statut
- `start_at_from` / `start_at_to` : Plage de début
- `end_at_from` / `end_at_to` : Plage de fin
- `last_run_at_from` / `last_run_at_to` : Plage de dernière exécution
- `cancelled_at_from` / `cancelled_at_to` : Plage d'annulation
- `failed_attempts` : Nombre exact d'échecs
- `max_failed_attempts` : Nombre maximal d'échecs

---

## Méthodes privées

### `freshState(?Iso8601DateTimeVO $now = null): FreshStateResultRecord`

**Rôle :** Met à jour les états des tâches en fonction du temps actuel.

**Transitions effectuées :**
1. `WAITING` avec `start_at <= now` → `PLAYING`
2. `PLAYING` avec `end_at <= now` → `FINISHED`
3. `PLAYING` avec `failed_attempts >= max_failed_attempts` → `CANCELED`

**Retourne :** `FreshStateResultRecord` - Compteurs des transitions effectuées

**Exemple :**
```php
$now = new Iso8601DateTimeVO;
$result = $repository->freshState($now);
// $result->waiting_to_playing : nombre de tâches WAITING devenues PLAYING
// $result->playing_to_finished : nombre de tâches PLAYING devenues FINISHED
// $result->playing_to_canceled : nombre de tâches PLAYING devenues CANCELED
```

---

## Cas d'utilisation

### Cas 1 : Récupération des tâches prêtes avec filtrage FQCN

```php
$now = new Iso8601DateTimeVO;
$limit = new LimitVO(50);
$fqcns = TaskFqcnVOCollection::from([TestRecurringTask::class]);

$result = $repository->findReadyToRun($now, $limit, $fqcns);

foreach ($result->tasks as $task) {
    // Traiter la tâche...
    $this->processTask($task);
}
```

### Cas 2 : Cycle de vie d'une tâche récurrente

```php
// 1. Enregistrer une tâche
$config = RecurringTaskConfigRecord::from([
    'type' => TaskType::RECURRING->value,
    'description' => 'Sync users',
    'interval_seconds' => 3600,
    'start_at' => Carbon::now()->addHours(2),
    'end_at' => Carbon::now()->addDays(7),
    'max_attempts' => 3,
]);

$alias = $service->register($fqcn, $payload, $config);
$task = $repository->findByAlias($alias);

// 2. Exécuter la tâche
$record = $this->modelToRecord($task);
$result = $repository->updateAfterRun($record, true);

// 3. Terminer prématurément
$repository->moveToFinished($record);
```

### Cas 3 : Gestion des échecs

```php
// Exécuter une tâche qui peut échouer
$record = $this->modelToRecord($task);

try {
    $this->executeTask($task);
    $repository->updateAfterRun($record, true);
} catch (Throwable $e) {
    $repository->updateAfterRun($record, false, new DescriptionVO($e->getMessage()));
    
    // Vérifier si la tâche a été annulée
    $updatedTask = $repository->findByAlias($record->alias);
    if ($updatedTask->getStatus() === RecurringTaskStatus::CANCELED) {
        echo "Tâche annulée après trop d'échecs\n";
    }
}
```

### Cas 4 : Mise à jour de l'intervalle

```php
$record = $this->modelToRecord($task);
$existingTask = $repository->findByAlias($record->alias);

$repository->update(
    $existingTask->getId()->getValue(),
    RecurringTaskRecord::from([
        'alias' => $record->alias,
        'fqcn' => $record->fqcn,
        'payload' => $record->payload,
        'interval_seconds' => 7200, // Nouvel intervalle
        'start_at' => $record->start_at,
        'end_at' => $record->end_at,
        'status' => $record->status,
        'last_run_at' => $record->last_run_at,
        'failed_attempts' => $record->failed_attempts,
        'max_failed_attempts' => $record->max_failed_attempts,
    ])
);
```

### Cas 5 : Filtrage avancé avec `applyFilters`

```php
$filters = RecurringTaskFiltersRecord::from([
    'status' => RecurringTaskStatus::PLAYING,
    'failed_attempts' => new CounterVO(2),
    'start_at_from' => new Iso8601DateTimeVO('2026-01-01T00:00:00+00:00'),
]);

$results = $repository->findBy(
    FindByRecord::from(['filters' => $filters])
);
```

---

## Flux d'exécution

### Diagramme des transitions d'état

```
┌─────────┐
│ WAITING │
└────┬────┘
     │ start_at <= now
     ▼
┌─────────┐      ┌───────────┐
│ PLAYING │──────│ FINISHED  │ (end_at <= now)
└────┬────┘      └───────────┘
     │
     │ failed_attempts >= max_failed_attempts
     ▼
┌───────────┐
│ CANCELED  │
└───────────┘

┌─────────┐      ┌─────────┐
│ PLAYING │──────│ PAUSED  │ (pause manuelle)
└─────────┘      └─────────┘
     │
     │ resume
     ▼
┌─────────┐
│ PLAYING │
└─────────┘
```

### Détermination des tâches prêtes

```
1. freshState() est appelée
   ↓
2. Mise à jour des états selon le temps
   ↓
3. Sélection des tâches PLAYING
   ↓
4. Filtrage FQCN si fourni
   ↓
5. Pour chaque tâche PLAYING :
   ↓
   last_run_at = null ?
   │   ├── Oui → Éligible (première exécution)
   │   └── Non → last_run_at + interval_seconds <= now ?
   │       ├── Oui → Éligible
   │       └── Non → Ignorée
   ↓
6. Retour des tâches éligibles
```

---

## Gestion des erreurs

| Situation | Log | Message |
|-----------|-----|---------|
| `freshState` exception | `error` | `recurring_task_fresh_state_error` |
| `findWaiting` exception | `error` | `recurring_task_find_waiting_error` |
| `findPlaying` exception | `error` | `recurring_task_find_playing_error` |
| `findPaused` exception | `error` | `recurring_task_find_paused_error` |
| `findFinished` exception | `error` | `recurring_task_find_finished_error` |
| `findCanceled` exception | `error` | `recurring_task_find_canceled_error` |
| `findReadyToRun` exception | `error` | `recurring_task_find_ready_to_run_error` |
| `moveToPlaying` exception | `error` | `recurring_task_move_to_playing_error` |
| `moveToPaused` exception | `error` | `recurring_task_move_to_paused_error` |
| `moveToWaiting` exception | `error` | `recurring_task_move_to_waiting_error` |
| `moveToFinished` exception | `error` | `recurring_task_move_to_finished_error` |
| `moveToCanceled` exception | `error` | `recurring_task_move_to_canceled_error` |
| `updateAfterRun` exception | `error` | `recurring_task_update_after_run_error` |

---

## Intégration

### Avec `RecurringTaskService`

Le repository est utilisé par `RecurringTaskService` pour :

1. **Enregistrement** : `create()` pour persister une nouvelle tâche récurrente
2. **Exécution** : `findReadyToRun()` pour récupérer les tâches à exécuter
3. **Mise à jour** : `updateAfterRun()` après chaque exécution
4. **Transitions** : `moveToPlaying()`, `moveToPaused()`, `moveToFinished()`, `moveToCanceled()`
5. **Comptage** : `countWaiting()`, `countPlaying()`, etc.

### Avec `TaskExecutionDebugRepository`

Le repository délègue l'ajout des informations de débogage au `TaskExecutionDebugRepository`.

---

## Performance

### Points d'attention

| Opération | Complexité | Risque |
|-----------|------------|--------|
| `freshState` | O(n) | ⚠️ Itère sur toutes les tâches |
| `findReadyToRun` | O(n) | ⚠️ Filtrage en mémoire |
| `countPlaying` | O(1) avec index | ✅ Indexé sur `status` |

### Optimisations

- Les requêtes de comptage utilisent les index sur `status`
- Le filtrage FQCN utilise `whereIn` (indexé sur `fqcn`)
- `freshState` utilise `update()` en lot pour `playing_to_canceled`

### Recommandations

```php
// ✅ Utiliser un limit pour réduire le nombre de tâches traitées
$result = $repository->findReadyToRun($now, new LimitVO(50));

// ✅ Utiliser l'option FQCN pour réduire le jeu de résultats
$fqcns = TaskFqcnVOCollection::from([$specificTaskClass]);
$result = $repository->findReadyToRun($now, $limit, $fqcns);
```

### Index recommandés

```sql
-- Pour findReadyToRun
CREATE INDEX idx_recurring_tasks_ready ON recurring_tasks (status, interval_seconds);

-- Pour countPlaying
CREATE INDEX idx_recurring_tasks_status ON recurring_tasks (status);

-- Pour le filtrage FQCN
CREATE INDEX idx_recurring_tasks_fqcn ON recurring_tasks (fqcn);

-- Pour freshState
CREATE INDEX idx_recurring_tasks_start_at ON recurring_tasks (status, start_at);
CREATE INDEX idx_recurring_tasks_end_at ON recurring_tasks (status, end_at);
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

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\DescriptionVO;

// 1. Instanciation du repository
$repository = app(RecurringTaskRepository::class);

// 2. Compter les tâches actives
$playingCount = $repository->countPlaying();
echo "Tâches actives : " . $playingCount->getValue() . "\n";

// 3. Compter avec filtre FQCN
$fqcns = TaskFqcnVOCollection::from([TestRecurringTask::class]);
$filteredCount = $repository->countPlaying($fqcns);
echo "Tâches actives (TestRecurringTask) : " . $filteredCount->getValue() . "\n";

// 4. Mettre à jour l'état des tâches
$freshState = $repository->freshState();
echo "WAITING → PLAYING : " . $freshState->waiting_to_playing->getValue() . "\n";
echo "PLAYING → FINISHED : " . $freshState->playing_to_finished->getValue() . "\n";
echo "PLAYING → CANCELED : " . $freshState->playing_to_canceled->getValue() . "\n";

// 5. Récupérer les tâches prêtes
$now = new Iso8601DateTimeVO;
$limit = new LimitVO(10);
$result = $repository->findReadyToRun($now, $limit, $fqcns);

foreach ($result->tasks as $task) {
    echo "Exécution de : " . $task->getAlias()->getValue() . "\n";
    
    $record = $this->modelToRecord($task);
    
    try {
        // 6. Traiter la tâche
        $task->process();
        
        // 7. Mettre à jour après succès
        $repository->updateAfterRun($record, true);
        echo "✅ Tâche exécutée avec succès\n";
        
    } catch (Throwable $e) {
        // 8. Mettre à jour après échec
        $repository->updateAfterRun($record, false, new DescriptionVO($e->getMessage()));
        echo "❌ Tâche échouée : " . $e->getMessage() . "\n";
    }
}

// 9. Statistiques finales
echo "📊 Statistiques finales :\n";
echo "  WAITING : " . $repository->countWaiting()->getValue() . "\n";
echo "  PLAYING : " . $repository->countPlaying()->getValue() . "\n";
echo "  PAUSED : " . $repository->countPaused()->getValue() . "\n";
echo "  FINISHED : " . $repository->countFinished()->getValue() . "\n";
echo "  CANCELED : " . $repository->countCanceled()->getValue() . "\n";
```

---

## Voir aussi

- `UniqueTaskRepository` - Repository pour les tâches uniques
- `TaskExecutionDebugRepository` - Gestion des logs de débogage
- `AbstractRepository` - Classe de base des repositories
- `RecurringTaskService` - Service de gestion des tâches récurrentes
- `RecurringTaskStatus` - Énumération des statuts possibles
- `RecurringTaskFiltersRecord` - Record de filtrage
