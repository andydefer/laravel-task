# RecurringTaskRepository - Référence Technique

## Description

Le `RecurringTaskRepository` gère le cycle de vie complet des tâches récurrentes, de leur création à leur terminaison, en passant par les transitions d'état automatiques basées sur le temps et le nombre d'échecs.

## Hiérarchie / Implémentations

```
AbstractRepository<RecurringTask, RecurringTaskRecord>
    └── RecurringTaskRepository
            └── RecurringTaskRepositoryInterface
```

**Interfaces implémentées :**
- `RecurringTaskRepositoryInterface` - Contrat principal du dépôt
- Hérite des méthodes de `AbstractRepository`

## Rôle principal

Ce dépôt agit comme l'interface unique entre la couche métier et la base de données pour toutes les opérations sur les tâches récurrentes. Il assure :

1. **Transitions d'état automatiques** (WAITING → PLAYING → FINISHED/CANCELED)
2. **Verrouillage des tâches** pour éviter les exécutions concurrentes
3. **Gestion du taux d'échec** et annulation automatique
4. **Journalisation des exécutions** via le `TaskExecutionDebugRepository`

## API / Méthodes publiques

### `findReadyToRun(?Iso8601DateTimeVO $now = null, ?LimitVO $limit = null): RecurringTaskReadyToRunResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO|null` | Instant de référence (utilise `now()` si null) |
| `$limit` | `LimitVO|null` | Nombre maximum de tâches à retourner |

**Retourne :** `RecurringTaskReadyToRunResultRecord` - Contient les tâches prêtes et le résultat des transitions d'état

**Exceptions :** Aucune exception propagée (les erreurs sont journalisées et un résultat vide est retourné)

**Comportement :**
1. Applique les transitions d'état via `freshState()`
2. Sélectionne les tâches en statut `PLAYING`
3. Filtre les tâches dont `last_run_at` est null ou dont l'intervalle est écoulé
4. Verrouille les lignes sélectionnées avec `lockForUpdate()` dans une transaction

**Exemple :**
```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\ValueObjects\LimitVO;

$repository = app(RecurringTaskRepository::class);
$result = $repository->findReadyToRun(null, new LimitVO(5));

foreach ($result->tasks as $task) {
    // Exécuter la tâche récurrente
    echo $task->alias->getValue() . "\n";
}

echo "Transitions: WAITING→PLAYING: {$result->fresh_state->waiting_to_playing->getValue()}\n";
```

---

### `findWaiting(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner (défaut: infini) |

**Retourne :** `Collection<int, RecurringTask>` - Collection de modèles Eloquent

**Comportement :**
1. Applique `freshState()` pour mettre à jour les états
2. Filtre les tâches avec le statut `WAITING`

---

### `findPlaying(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, RecurringTask>` - Tâches actuellement en cours d'exécution

---

### `findPaused(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, RecurringTask>` - Tâches mises en pause

---

### `findFinished(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, RecurringTask>` - Tâches terminées (fin naturelle)

---

### `findCanceled(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, RecurringTask>` - Tâches annulées (trop d'échecs)

---

### `findByAlias(TaskAliasVO $alias): ?RecurringTask`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias unique de la tâche |

**Retourne :** `RecurringTask|null` - Le modèle ou null si non trouvé

**Comportement :** Applique `freshState()` avant la recherche

---

### `moveToPlaying(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Enregistrement contenant les données à mettre à jour |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** `WAITING` → `PLAYING`

---

### `moveToPaused(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Enregistrement contenant les données à mettre à jour |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** `PLAYING` → `PAUSED`

---

### `moveToWaiting(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Enregistrement contenant les données à mettre à jour |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** Retour à `WAITING` (ex: reprise après pause)

---

### `moveToFinished(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Enregistrement contenant les données à mettre à jour |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** `PLAYING` → `FINISHED`

**Détails :** Définit automatiquement `finished_at` à `now()`

---

### `moveToCanceled(RecurringTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Enregistrement contenant les données à mettre à jour |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** `PLAYING` → `CANCELED`

**Détails :** Définit `cancelled_at` et `finished_at` à `now()`

---

### `updateAfterRun(RecurringTaskRecord $task, bool $success, ?DescriptionVO $error = null): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Enregistrement contenant les données à mettre à jour |
| `$success` | `bool` | Indique si l'exécution a réussi |
| `$error` | `DescriptionVO|null` | Message d'erreur en cas d'échec |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Comportement :**
1. Met à jour `last_run_at` à `now()`
2. Réinitialise `failed_attempts` à 0 si succès
3. Incrémente `failed_attempts` si échec
4. Enregistre un debug via `TaskExecutionDebugRepository`

---

### `countWaiting(): CounterVO`
### `countPlaying(): CounterVO`
### `countPaused(): CounterVO`
### `countFinished(): CounterVO`
### `countCanceled(): CounterVO`

| Retourne | Description |
|----------|-------------|
| `CounterVO` | Nombre de tâches dans le statut correspondant |

**Comportement :** Applique `freshState()` avant de compter pour garantir des données à jour

---

## Cas d'utilisation

### Cas 1 : Exécution d'un lot de tâches récurrentes avec verrouillage

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\ValueObjects\LimitVO;

$repository = app(RecurringTaskRepository::class);

// Récupère jusqu'à 10 tâches prêtes à être exécutées
$result = $repository->findReadyToRun(null, new LimitVO(10));

// Les tâches sont automatiquement verrouillées (lockForUpdate)
// Aucun autre processus ne peut les récupérer simultanément

foreach ($result->tasks as $taskRecord) {
    try {
        // Exécuter la logique métier
        $success = executeTask($taskRecord);
        
        // Mettre à jour après exécution
        $repository->updateAfterRun($taskRecord, $success);
    } catch (Throwable $e) {
        $repository->updateAfterRun(
            $taskRecord, 
            false, 
            new DescriptionVO($e->getMessage())
        );
    }
}
```

### Cas 2 : Surveillance des transitions d'état

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;

$repository = app(RecurringTaskRepository::class);

// Le freshState est appelé automatiquement par les finders
$playingTasks = $repository->findPlaying(new LimitVO(100));

// Les transitions suivantes ont été appliquées automatiquement :
// - WAITING → PLAYING (start_at <= now)
// - PLAYING → FINISHED (end_at <= now)
// - PLAYING → CANCELED (failed_attempts >= max_failed_attempts)

foreach ($playingTasks as $task) {
    echo "Tâche en cours : {$task->getAlias()->getValue()}\n";
}

// Compter les tâches dans chaque état
echo "En attente : " . $repository->countWaiting()->getValue() . "\n";
echo "En cours : " . $repository->countPlaying()->getValue() . "\n";
echo "Terminées : " . $repository->countFinished()->getValue() . "\n";
echo "Annulées : " . $repository->countCanceled()->getValue() . "\n";
```

### Cas 3 : Gestion d'une tâche unique par alias

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\ValueObjects\TaskAliasVO;

$repository = app(RecurringTaskRepository::class);

$alias = new TaskAliasVO('monitoring-task');

// Récupérer une tâche spécifique
$task = $repository->findByAlias($alias);

if ($task === null) {
    echo "Tâche non trouvée\n";
    return;
}

// Vérifier le statut actuel
echo "Statut : " . $task->getStatus()->value . "\n";
echo "Tentatives échouées : " . $task->getFailedAttempts()->getValue() . "\n";

// Si la tâche est en erreur, la repasser en attente
if ($task->getStatus() === RecurringTaskStatus::CANCELED) {
    $record = $task->toRecord();
    $repository->moveToWaiting($record);
    echo "Tâche remise en attente\n";
}
```

## Gestion des erreurs

| Situation | Exception interne | Message journalisé |
|-----------|-------------------|-------------------|
| Échec de `freshState()` | `Throwable` | `recurring_task_fresh_state_error` |
| Échec de `findWaiting()` | `Throwable` | `recurring_task_find_waiting_error` |
| Échec de `findPlaying()` | `Throwable` | `recurring_task_find_playing_error` |
| Échec de `findPaused()` | `Throwable` | `recurring_task_find_paused_error` |
| Échec de `findFinished()` | `Throwable` | `recurring_task_find_finished_error` |
| Échec de `findCanceled()` | `Throwable` | `recurring_task_find_canceled_error` |
| Échec de `findReadyToRun()` | `Throwable` | `recurring_task_find_ready_to_run_error` |
| Échec de `findByAlias()` | `Throwable` | `recurring_task_find_by_alias_error` |
| Échec de `moveToPlaying()` | `Throwable` | `recurring_task_move_to_playing_error` |
| Échec de `moveToPaused()` | `Throwable` | `recurring_task_move_to_paused_error` |
| Échec de `moveToWaiting()` | `Throwable` | `recurring_task_move_to_waiting_error` |
| Échec de `moveToFinished()` | `Throwable` | `recurring_task_move_to_finished_error` |
| Échec de `moveToCanceled()` | `Throwable` | `recurring_task_move_to_canceled_error` |
| Échec de `updateAfterRun()` | `Throwable` | `recurring_task_update_after_run_error` |
| Échec de `countWaiting()` | `Throwable` | `recurring_task_count_waiting_error` |
| Échec de `countPlaying()` | `Throwable` | `recurring_task_count_playing_error` |

**Note :** Aucune exception n'est propagée à l'appelant. Toutes les erreurs sont :
1. Journalisées via le logger avec le type d'erreur
2. Traitées avec une valeur de retour par défaut (Collection vide, false, CounterVO(0))

## Intégration

```
┌─────────────────────────────┐
│   RecurringTaskService      │
│   (Service métier)          │
└────────────┬────────────────┘
             │ utilise
             ▼
┌─────────────────────────────┐
│   RecurringTaskRepository   │ ← implémente → RecurringTaskRepositoryInterface
│   (Dépôt courant)           │
└────────────┬────────────────┘
             │ utilise
             ▼
┌─────────────────────────────┐
│   TaskExecutionDebugRepo    │
│   (Journal des exécutions)  │
└─────────────────────────────┘
```

**Dépendances injectées :**
- `TaskExecutionDebugRepositoryInterface` - Pour la journalisation des exécutions
- `LoggerInterface` - Pour la journalisation des erreurs

**Classes parentes utilisées :**
- `AbstractRepository` - Gère les opérations CRUD de base

## Performance

| Opération | Complexité | Cache | Verrou |
|-----------|-----------|-------|--------|
| `findReadyToRun()` | O(n) avec n = limit | ❌ | `lockForUpdate()` |
| `findBy*()` | O(n) avec n = limit | ❌ | ❌ |
| `count*()` | O(1) (COUNT query) | ❌ | ❌ |
| `moveTo*()` | O(1) (UPDATE by ID) | ❌ | ❌ |
| `updateAfterRun()` | O(1) + O(1) debug | ❌ | ❌ |

**Recommandations :**
- Utiliser un `limit` approprié pour `findReadyToRun()` pour éviter les surcharges
- Les transactions sont limitées à `findReadyToRun()` pour minimiser la durée des verrous
- Les count() effectuent un `freshState()` complet avant de compter, ce qui peut être coûteux sur de grands volumes

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet (types union, mixed) |
| PHP 8.0 | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 9 | ✅ Complet |
| SQLite | ✅ (utilise `strftime` pour les calculs) |
| MySQL | ✅ |
| PostgreSQL | ⚠️ (adaptation `strftime` nécessaire) |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;

// 1. Instanciation du dépôt
$repository = app(RecurringTaskRepository::class);

// 2. Récupération des tâches prêtes avec verrouillage
$limit = new LimitVO(5);
$result = $repository->findReadyToRun(null, $limit);

echo "Tâches récupérées : " . $result->tasks->count() . "\n";
echo "Transitions effectuées :\n";
echo "  WAITING → PLAYING : " . $result->fresh_state->waiting_to_playing->getValue() . "\n";
echo "  PLAYING → FINISHED : " . $result->fresh_state->playing_to_finished->getValue() . "\n";
echo "  PLAYING → CANCELED : " . $result->fresh_state->playing_to_canceled->getValue() . "\n";

// 3. Traitement des tâches
foreach ($result->tasks as $task) {
    try {
        // Simuler l'exécution
        $success = true;
        
        // Mise à jour du statut après exécution
        $updated = $repository->updateAfterRun($task, $success);
        
        if ($updated) {
            echo "✅ Tâche " . $task->alias->getValue() . " exécutée\n";
        }
    } catch (Throwable $e) {
        // Gestion d'erreur avec journalisation automatique
        $repository->updateAfterRun(
            $task, 
            false, 
            new DescriptionVO($e->getMessage())
        );
        echo "❌ Échec : " . $e->getMessage() . "\n";
    }
}

// 4. Vérification du statut final
echo "\n📊 Statut final :\n";
echo "  En attente : " . $repository->countWaiting()->getValue() . "\n";
echo "  En cours : " . $repository->countPlaying()->getValue() . "\n";
echo "  Terminées : " . $repository->countFinished()->getValue() . "\n";
echo "  Annulées : " . $repository->countCanceled()->getValue() . "\n";
```

## Voir aussi
- `AbstractRepository` - Classe parente pour les opérations CRUD
- `RecurringTask` - Modèle Eloquent associé
- `RecurringTaskRecord` - Data Transfer Object associé
- `RecurringTaskStatus` - Énumération des états possibles
- `TaskExecutionDebugRepository` - Journalisation des exécutions
- `RecurringTaskService` - Service métier utilisant ce dépôt
- `UniqueTaskRepository` - Dépôt similaire pour les tâches uniques