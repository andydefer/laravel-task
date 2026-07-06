# RecurringTaskRepository - Référence Technique

## Description

Repository de gestion des tâches récurrentes. Il orchestre le stockage, la récupération et les transitions d'état des tâches récurrentes, avec un support des transitions automatiques basées sur le temps.

## Hiérarchie / Implémentations

```
AbstractRepository<RecurringTask, RecurringTaskRecord>
    └── RecurringTaskRepository
            └── RecurringTaskRepositoryInterface
```

## Rôle principal

Gérer le cycle de vie des tâches récurrentes en :
- Récupérant les tâches par statut (WAITING, PLAYING, PAUSED, FINISHED, CANCELED)
- Effectuant des transitions d'état automatiques basées sur le temps
- Gérant les transitions manuelles (moveToPlaying, moveToPaused, etc.)
- Mettant à jour les tâches après exécution (last_run_at, failed_attempts)
- Comptant les tâches par statut pour le monitoring

## API / Méthodes publiques

### `findWaiting(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches en attente (statut WAITING).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, RecurringTask>` - Collection des tâches

---

### `findPlaying(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches en cours d'exécution (statut PLAYING).

---

### `findPaused(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches en pause (statut PAUSED).

---

### `findFinished(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches terminées (statut FINISHED).

---

### `findCanceled(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches annulées (statut CANCELED).

---

### `findReadyToRun(?Iso8601DateTimeVO $now = null, ?LimitVO $limit = null): RecurringTaskReadyToRunResultRecord`

Retourne les tâches prêtes à être exécutées avec les transitions d'état automatiques.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO|null` | Date/heure actuelle (utilise now si null) |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats (optionnel) |

**Retourne :** `RecurringTaskReadyToRunResultRecord` - Résultat avec tâches et état frais

**Transitions automatiques effectuées :**
- WAITING → PLAYING (start_at atteint)
- PLAYING → FINISHED (end_at atteint)
- PLAYING → CANCELED (failed_attempts ≥ max_failed_attempts)

**Exemple :**
```php
$result = $repository->findReadyToRun(null, new LimitVO(50));

echo "Tâches en transition :\n";
echo "WAITING → PLAYING : {$result->fresh_state->waiting_to_playing->getValue()}\n";
echo "PLAYING → FINISHED : {$result->fresh_state->playing_to_finished->getValue()}\n";
echo "PLAYING → CANCELED : {$result->fresh_state->playing_to_canceled->getValue()}\n";

foreach ($result->tasks as $task) {
    echo $task->getAlias()->getValue();
}
```

---

### `findByAlias(TaskAliasVO $alias): ?RecurringTask`

Trouve une tâche par son alias.

---

### `moveToPlaying(RecurringTaskRecord $task): bool`

Déplace une tâche vers le statut PLAYING.

---

### `moveToPaused(RecurringTaskRecord $task): bool`

Déplace une tâche vers le statut PAUSED.

---

### `moveToWaiting(RecurringTaskRecord $task): bool`

Déplace une tâche vers le statut WAITING.

---

### `moveToFinished(RecurringTaskRecord $task): bool`

Déplace une tâche vers le statut FINISHED.

---

### `moveToCanceled(RecurringTaskRecord $task): bool`

Déplace une tâche vers le statut CANCELED.

---

### `updateAfterRun(RecurringTaskRecord $task, bool $success, ?DescriptionVO $error = null): bool`

Met à jour une tâche après exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Record de la tâche |
| `$success` | `bool` | Indique si l'exécution a réussi |
| `$error` | `DescriptionVO|null` | Message d'erreur en cas d'échec |

**Actions :**
- Met à jour `last_run_at` avec la date actuelle
- Réinitialise `failed_attempts` à 0 en cas de succès
- Incrémente `failed_attempts` en cas d'échec
- Ajoute une entrée de débogage

**Retourne :** `bool` - `true` si la mise à jour a réussi

---

### `countWaiting(): CounterVO`, `countPlaying(): CounterVO`, etc.

Compteurs de tâches par statut.

## Transitions d'état automatiques

### `freshState()` - Transitions basées sur le temps

```
WAITING ──(start_at ≤ now)──▶ PLAYING
PLAYING ──(end_at ≤ now)────▶ FINISHED
PLAYING ──(failed_attempts ≥ max) ──▶ CANCELED
```

### Comportement

1. **WAITING → PLAYING** : Lorsque `start_at` est atteint
2. **PLAYING → FINISHED** : Lorsque `end_at` est atteint
3. **PLAYING → CANCELED** : Lorsque les échecs dépassent le maximum

### Résultat

```php
FreshStateResultRecord {
    waiting_to_playing: CounterVO,   // Nombre de WAITING → PLAYING
    playing_to_finished: CounterVO,  // Nombre de PLAYING → FINISHED
    playing_to_canceled: CounterVO,  // Nombre de PLAYING → CANCELED
}
```

## Cycle de vie des états

```
WAITING ──(start_at)──▶ PLAYING
                           │
               ┌───────────┼───────────┐
               │           │           │
               ▼           ▼           ▼
            PAUSED     FINISHED    CANCELED
               │           │           │
               └───────────┘           │
                           │           │
                           ▼           ▼
                       (terminal)  (terminal)
```

### Transitions manuelles

| Transition | Méthode |
|------------|---------|
| WAITING → PLAYING | `moveToPlaying()` |
| PLAYING → PAUSED | `moveToPaused()` |
| PAUSED → PLAYING | `moveToPlaying()` (déjà PLAYING) |
| PLAYING → FINISHED | `moveToFinished()` |
| PLAYING → CANCELED | `moveToCanceled()` |

## Cas d'utilisation

### Cas 1 : Récupération des tâches prêtes

**Problème :** Récupérer les tâches à exécuter avec transitions automatiques.

```php
$result = $repository->findReadyToRun(null, new LimitVO(50));

// Les transitions automatiques ont été effectuées
echo "Transitions effectuées :\n";
echo "  WAITING→PLAYING : {$result->fresh_state->waiting_to_playing->getValue()}\n";
echo "  PLAYING→FINISHED : {$result->fresh_state->playing_to_finished->getValue()}\n";

// Traitement des tâches PLAYING
foreach ($result->tasks as $task) {
    $record = $repository->modelToRecord($task);
    $runResult = $runner->run($record);
    $repository->updateAfterRun($record, $runResult->success, $runResult->error);
}
```

---

### Cas 2 : Mise en pause et reprise

**Problème :** Mettre en pause une tâche pendant la maintenance.

```php
$task = $repository->findByAlias($alias);
$record = $repository->modelToRecord($task);

// Pause
$repository->moveToPaused($record);

// ... maintenance ...

// Reprise
$repository->moveToPlaying($record);
```

---

### Cas 3 : Mise à jour après exécution

**Problème :** Mettre à jour les statistiques d'une tâche après exécution.

```php
$task = $repository->findByAlias($alias);
$record = $repository->modelToRecord($task);

$success = $this->executeTask($record);

$repository->updateAfterRun(
    $record,
    $success,
    $success ? null : new DescriptionVO('Execution failed')
);

// failed_attempts est incrémenté en cas d'échec
// Si failed_attempts >= max, la tâche sera annulée
```

---

### Cas 4 : Annulation d'une tâche

**Problème :** Annuler une tâche récurrente.

```php
$task = $repository->findByAlias($alias);
$record = $repository->modelToRecord($task);

if ($repository->moveToCanceled($record)) {
    echo "Tâche annulée\n";
}
```

## Gestion des erreurs

| Situation | Comportement | Log |
|-----------|--------------|-----|
| `freshState()` - erreur | Retourne des compteurs à 0 | `recurring_task_fresh_state_error` |
| `moveToPlaying()` - tâche non trouvée | Retourne `false` | `recurring_task_move_to_playing_error` |
| `updateAfterRun()` - tâche non trouvée | Retourne `false` | `recurring_task_update_after_run_error` |

## Intégration

### Dépendances

- `TaskExecutionDebugRepositoryInterface` : Stockage des débogages
- `LoggerInterface` : Logging des erreurs

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `RecurringTaskService` | API de haut niveau |
| `RecurringTaskProcessor` | Traitement par lots |
| `RecurringTaskRunner` | Exécution individuelle |

## Performance

- **Transitions** : Mises à jour groupées en une seule requête
- **Indexation** : Index sur `status`, `start_at`, `end_at`, `alias`
- **Recommandation** : Utiliser `LimitVO` pour les gros volumes

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\DescriptionVO;

$repository = new RecurringTaskRepository($debugRepository, $logger);

// 1. Récupération des tâches prêtes avec transitions automatiques
$result = $repository->findReadyToRun(null, new LimitVO(10));

echo "=== Transitions d'état ===\n";
echo "WAITING → PLAYING : {$result->fresh_state->waiting_to_playing->getValue()}\n";
echo "PLAYING → FINISHED : {$result->fresh_state->playing_to_finished->getValue()}\n";
echo "PLAYING → CANCELED : {$result->fresh_state->playing_to_canceled->getValue()}\n\n";

// 2. Traitement des tâches
foreach ($result->tasks as $task) {
    $record = $repository->modelToRecord($task);
    
    echo "Traitement : {$record->alias->getValue()}\n";
    
    // Simuler l'exécution
    $success = random_int(0, 1) === 1;
    
    // Mise à jour après exécution
    $repository->updateAfterRun(
        $record,
        $success,
        $success ? null : new DescriptionVO('Random failure')
    );
}

// 3. Monitoring
echo "\n=== Statistiques ===\n";
echo "WAITING : {$repository->countWaiting()->getValue()}\n";
echo "PLAYING : {$repository->countPlaying()->getValue()}\n";
echo "PAUSED : {$repository->countPaused()->getValue()}\n";
echo "FINISHED : {$repository->countFinished()->getValue()}\n";
echo "CANCELED : {$repository->countCanceled()->getValue()}\n";
```

## Voir aussi

- `UniqueTaskRepository` - Repository de tâches uniques
- `TaskExecutionDebugRepository` - Repository de débogage
- `RecurringTaskService` - Service de tâches récurrentes
- `RecurringTaskProcessor` - Processeur de lots
- `RecurringTaskRunner` - Exécuteur individuel
---