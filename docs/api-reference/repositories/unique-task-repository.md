# UniqueTaskRepository - Référence Technique

## Description

Le `UniqueTaskRepository` gère le cycle de vie des tâches uniques (exécutées une seule fois), de leur enregistrement à leur complétion, en passant par le verrouillage pour les exécutions parallèles et la gestion des tentatives.

## Hiérarchie / Implémentations

```
AbstractRepository<UniqueTask, UniqueTaskRecord>
    └── UniqueTaskRepository
            └── UniqueTaskRepositoryInterface
```

**Interfaces implémentées :**
- `UniqueTaskRepositoryInterface` - Contrat principal du dépôt
- Hérite des méthodes de `AbstractRepository`

## Rôle principal

Ce dépôt agit comme l'interface unique entre la couche métier et la base de données pour toutes les opérations sur les tâches uniques. Il assure :

1. **Verrouillage des tâches** avec `lockForUpdate()` pour éviter les exécutions concurrentes
2. **Transitions d'état** : PENDING → IN_PROGRESS → COMPLETED/FAILED/CANCELED
3. **Gestion des tentatives** et limitation des tentatives
4. **Détection des tâches expirées** via le délai de grâce (`grace_period`)
5. **Journalisation des exécutions** via le `TaskExecutionDebugRepository`

## API / Méthodes publiques

### `findReadyToRun(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO` | Instant de référence pour la sélection |
| `$limit` | `LimitVO|null` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, UniqueTask>` - Collection de modèles Eloquent

**Comportement :**
1. Sélectionne les tâches en statut `PENDING` dont `scheduled_at <= now`
2. Verrouille les lignes avec `lockForUpdate()` dans une transaction
3. Met à jour **en lot** le statut des tâches sélectionnées vers `IN_PROGRESS`
4. Retourne les tâches pour traitement

**Exemple :**
```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

$repository = app(UniqueTaskRepository::class);
$now = new Iso8601DateTimeVO;
$limit = new LimitVO(10);

// Récupère et verrouille jusqu'à 10 tâches prêtes
$tasks = $repository->findReadyToRun($now, $limit);

// Les tâches sont automatiquement passées en IN_PROGRESS
foreach ($tasks as $task) {
    echo "Tâche verrouillée : " . $task->getAlias()->getValue() . "\n";
    echo "Statut : " . $task->getStatus()->value . "\n"; // IN_PROGRESS
}
```

---

### `findPending(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner (défaut: infini) |

**Retourne :** `Collection<int, UniqueTask>` - Tâches en attente d'exécution

---

### `findCompleted(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, UniqueTask>` - Tâches terminées avec succès

---

### `findFailed(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, UniqueTask>` - Tâches ayant échoué

---

### `findCanceled(LimitVO $limit = new LimitVO): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, UniqueTask>` - Tâches annulées

---

### `findExpired(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO` | Instant de référence |
| `$limit` | `LimitVO|null` | Nombre maximum de tâches à retourner |

**Retourne :** `Collection<int, UniqueTask>` - Tâches PENDING ayant expiré (scheduled_at + grace_period < now)

**Comportement :** Calcule la date d'expiration pour chaque tâche en fonction de sa date de planification et de son délai de grâce

**Exemple :**
```php
<?php

$now = new Iso8601DateTimeVO;
$expiredTasks = $repository->findExpired($now, new LimitVO(100));

foreach ($expiredTasks as $task) {
    echo "Tâche expirée : " . $task->getAlias()->getValue() . "\n";
    echo "Planifiée le : " . $task->getScheduledAt()->getValue() . "\n";
    echo "Délai de grâce : " . $task->getGracePeriodSeconds() . "s\n";
}
```

---

### `findById(UuidVO $id): ?UniqueTask`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `UuidVO` | Identifiant unique de la tâche |

**Retourne :** `UniqueTask|null` - Le modèle ou null si non trouvé

---

### `findByAlias(TaskAliasVO $alias): ?UniqueTask`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias unique de la tâche |

**Retourne :** `UniqueTask|null` - Le modèle ou null si non trouvé

---

### `updateAttempts(UniqueTaskRecord $task, CounterVO $newAttempts): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Enregistrement contenant les données de la tâche |
| `$newAttempts` | `CounterVO` | Nouveau nombre de tentatives |

**Retourne :** `bool` - `true` si la mise à jour a réussi

---

### `addDebug(UniqueTaskRecord $task, ExecutionStatus $status, DescriptionVO $info): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Enregistrement contenant les données de la tâche |
| `$status` | `ExecutionStatus` | Statut de l'exécution (SUCCEEDED/FAILED) |
| `$info` | `DescriptionVO` | Information descriptive sur l'exécution |

**Retourne :** `bool` - `true` si l'ajout a réussi

**Comportement :** Délègue l'opération au `TaskExecutionDebugRepository`

---

### `moveToCompleted(UniqueTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Enregistrement contenant les données de la tâche |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** `PENDING`/`IN_PROGRESS` → `COMPLETED`

**Détails :** Définit automatiquement `finished_at` à `now()`

---

### `moveToFailed(UniqueTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Enregistrement contenant les données de la tâche |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** `PENDING`/`IN_PROGRESS` → `FAILED`

**Détails :** Définit automatiquement `finished_at` à `now()`

---

### `moveToCanceled(UniqueTaskRecord $task): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Enregistrement contenant les données de la tâche |

**Retourne :** `bool` - `true` si la mise à jour a réussi

**Transitions :** `PENDING`/`IN_PROGRESS` → `CANCELED`

**Détails :** Définit automatiquement `finished_at` à `now()`

---

### `countPending(): CounterVO`
### `countCompleted(): CounterVO`
### `countFailed(): CounterVO`
### `countCanceled(): CounterVO`

| Retourne | Description |
|----------|-------------|
| `CounterVO` | Nombre de tâches dans le statut correspondant |

---

## Cas d'utilisation

### Cas 1 : Exécution parallèle de tâches uniques avec verrouillage

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

$repository = app(UniqueTaskRepository::class);
$now = new Iso8601DateTimeVO;

// Chaque worker récupère et verrouille ses propres tâches
$limit = new LimitVO(5);
$tasks = $repository->findReadyToRun($now, $limit);

// Les tâches sont automatiquement passées en IN_PROGRESS
// Aucun autre worker ne pourra les récupérer

foreach ($tasks as $task) {
    try {
        // Exécuter la logique métier
        executeTask($task);
        
        // Marquer comme terminée
        $repository->moveToCompleted($task->toRecord());
        
        // Journaliser le succès
        $repository->addDebug(
            $task->toRecord(),
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task executed successfully')
        );
    } catch (Throwable $e) {
        // Marquer comme échouée
        $repository->moveToFailed($task->toRecord());
        
        // Journaliser l'erreur
        $repository->addDebug(
            $task->toRecord(),
            ExecutionStatus::FAILED,
            new DescriptionVO($e->getMessage())
        );
    }
}
```

### Cas 2 : Détection et nettoyage des tâches expirées

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\Enums\UniqueTaskStatus;

$repository = app(UniqueTaskRepository::class);
$now = new Iso8601DateTimeVO;

// Récupérer les tâches expirées
$expiredTasks = $repository->findExpired($now, new LimitVO(100));

foreach ($expiredTasks as $task) {
    $scheduledAt = $task->getScheduledAt();
    $gracePeriod = $task->getGracePeriodSeconds();
    $expirationTime = $scheduledAt->addSeconds($gracePeriod);
    
    echo "🗑️ Tâche expirée : " . $task->getAlias()->getValue() . "\n";
    echo "   Planifiée : " . $scheduledAt->getValue() . "\n";
    echo "   Expiration : " . $expirationTime->getValue() . "\n";
    
    // Annuler la tâche expirée
    $repository->moveToCanceled($task->toRecord());
}
```

### Cas 3 : Gestion des tentatives d'une tâche unique

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;

$repository = app(UniqueTaskRepository::class);
$alias = new TaskAliasVO('critical-email-task');

// Récupérer la tâche
$task = $repository->findByAlias($alias);

if ($task === null) {
    echo "❌ Tâche non trouvée\n";
    return;
}

$currentAttempts = $task->getAttempts()->getValue();
$maxAttempts = $task->getMaxAttempts()->getValue();

echo "📊 Tentatives : {$currentAttempts}/{$maxAttempts}\n";

// Incrémenter les tentatives
$newAttempts = new CounterVO($currentAttempts + 1);
$repository->updateAttempts($task->toRecord(), $newAttempts);

echo "✅ Tentatives mises à jour : " . $newAttempts->getValue() . "\n";

// Si max atteint, annuler la tâche
if ($newAttempts->getValue() >= $maxAttempts) {
    $repository->moveToCanceled($task->toRecord());
    echo "❌ Tâche annulée (max tentatives atteint)\n";
}
```

### Cas 4 : Surveillance du statut des tâches

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;

$repository = app(UniqueTaskRepository::class);

echo "📊 Statistiques des tâches uniques :\n";
echo "   ⏳ En attente : " . $repository->countPending()->getValue() . "\n";
echo "   ✅ Terminées : " . $repository->countCompleted()->getValue() . "\n";
echo "   ❌ Échouées : " . $repository->countFailed()->getValue() . "\n";
echo "   🚫 Annulées : " . $repository->countCanceled()->getValue() . "\n";

// Liste des tâches en échec pour investigation
$failedTasks = $repository->findFailed(new LimitVO(10));
foreach ($failedTasks as $task) {
    echo "   - " . $task->getAlias()->getValue() 
         . " (tentatives: " . $task->getAttempts()->getValue() . ")\n";
}
```

## Gestion des erreurs

| Situation | Exception interne | Message journalisé |
|-----------|-------------------|-------------------|
| Échec de `updateAttempts()` | `Throwable` | `unique_task_update_attempts_error` |
| Tâche non trouvée dans `updateAttempts()` | Aucune | `unique_task_update_attempts_not_found` |
| Échec de `addDebug()` | `Throwable` | `unique_task_add_debug_error` |
| Échec de `moveToCompleted()` | `Throwable` | `unique_task_move_to_completed_error` |
| Tâche déjà terminée | Aucune | `unique_task_move_to_completed_not_found_or_already_completed` |
| Échec de `moveToFailed()` | `Throwable` | `unique_task_move_to_failed_error` |
| Tâche déjà en échec | Aucune | `unique_task_move_to_failed_not_found_or_already_failed` |
| Échec de `moveToCanceled()` | `Throwable` | `unique_task_move_to_canceled_error` |
| Tâche déjà annulée | Aucune | `unique_task_move_to_canceled_not_found_or_already_canceled` |

**Note :** Aucune exception n'est propagée à l'appelant pour les opérations d'écriture. Toutes les erreurs sont :
1. Journalisées via le logger avec le type d'erreur
2. Traitées avec une valeur de retour `false`

## Intégration

```
┌─────────────────────────────┐
│   UniqueTaskService         │
│   (Service métier)          │
└────────────┬────────────────┘
             │ utilise
             ▼
┌─────────────────────────────┐
│   UniqueTaskRepository      │ ← implémente → UniqueTaskRepositoryInterface
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

## Flux d'exécution d'une tâche unique

```
┌─────────────────────────────────────────────────────────────┐
│                      ÉTAT INITIAL                          │
│                   PENDING (scheduled_at)                    │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  1. findReadyToRun() - Sélection et verrouillage          │
│     - lockForUpdate() dans une transaction                │
│     - PENDING → IN_PROGRESS (en lot)                      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  2. Exécution de la tâche (par le service)                │
│     - Appel de la méthode execute()                       │
│     - Gestion des tentatives                              │
└──────────────────────┬──────────────────────────────────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            ▼                     ▼
┌───────────────────┐  ┌─────────────────────────────┐
│  ✅ SUCCÈS        │  │  ❌ ÉCHEC                   │
│  moveToCompleted()│  │  moveToFailed()             │
│  → COMPLETED      │  │  → FAILED                  │
│  finished_at = now│  │  finished_at = now          │
└───────────────────┘  └─────────────────────────────┘
            │                     │
            └──────────┬──────────┘
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  Journalisation avec addDebug()                           │
│  ExecutionStatus::SUCCEEDED ou ExecutionStatus::FAILED   │
└─────────────────────────────────────────────────────────────┘
```

## Performance

| Opération | Complexité | Cache | Verrou |
|-----------|-----------|-------|--------|
| `findReadyToRun()` | O(n) avec n = limit | ❌ | `lockForUpdate()` |
| `findExpired()` | O(n) avec n = limit | ❌ | ❌ |
| `findBy*()` | O(n) avec n = limit | ❌ | ❌ |
| `count*()` | O(1) (COUNT query) | ❌ | ❌ |
| `moveTo*()` | O(1) (UPDATE by ID) | ❌ | ❌ |
| `updateAttempts()` | O(1) (UPDATE by ID) | ❌ | ❌ |

**Recommandations :**
- Utiliser un `limit` approprié pour `findReadyToRun()` pour éviter les surcharges mémoire
- La transaction dans `findReadyToRun()` est courte pour minimiser le temps de verrouillage
- `findExpired()` est moins efficace car il charge toutes les tâches PENDING puis filtre en mémoire

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet (types union, mixed) |
| PHP 8.0 | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 9 | ✅ Complet |
| MySQL | ✅ |
| PostgreSQL | ✅ |
| SQLite | ✅ |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\CounterVO;

$repository = app(UniqueTaskRepository::class);

// 1. Récupération des tâches prêtes avec verrouillage
$now = new Iso8601DateTimeVO;
$limit = new LimitVO(5);
$tasks = $repository->findReadyToRun($now, $limit);

echo "📥 " . $tasks->count() . " tâches verrouillées\n\n";

// 2. Traitement de chaque tâche
foreach ($tasks as $task) {
    $alias = $task->getAlias()->getValue();
    $record = $task->toRecord();
    
    echo "🔵 Traitement : {$alias}\n";
    echo "   - Statut initial : " . $task->getStatus()->value . "\n"; // IN_PROGRESS
    echo "   - Tentatives : " . $task->getAttempts()->getValue() . "/" . $task->getMaxAttempts()->getValue() . "\n";
    
    try {
        // Simuler l'exécution (remplacer par la logique réelle)
        $success = (rand(0, 10) > 2); // 80% de chance de succès
        
        if ($success) {
            $repository->moveToCompleted($record);
            $repository->addDebug(
                $record,
                ExecutionStatus::SUCCEEDED,
                new DescriptionVO('Task executed successfully')
            );
            echo "   ✅ Tâche terminée avec succès\n";
        } else {
            // Incrémenter les tentatives
            $newAttempts = new CounterVO($task->getAttempts()->getValue() + 1);
            $repository->updateAttempts($record, $newAttempts);
            
            // Vérifier si max tentatives atteint
            if ($newAttempts->getValue() >= $task->getMaxAttempts()->getValue()) {
                $repository->moveToCanceled($record);
                $repository->addDebug(
                    $record,
                    ExecutionStatus::FAILED,
                    new DescriptionVO('Max attempts reached, task canceled')
                );
                echo "   ❌ Tâche annulée (max tentatives)\n";
            } else {
                $repository->moveToFailed($record);
                $repository->addDebug(
                    $record,
                    ExecutionStatus::FAILED,
                    new DescriptionVO('Task execution failed')
                );
                echo "   ❌ Tâche en échec (tentative " . $newAttempts->getValue() . "/" . $task->getMaxAttempts()->getValue() . ")\n";
            }
        }
    } catch (Throwable $e) {
        $repository->moveToFailed($record);
        $repository->addDebug(
            $record,
            ExecutionStatus::FAILED,
            new DescriptionVO($e->getMessage())
        );
        echo "   💥 Erreur critique : " . $e->getMessage() . "\n";
    }
}

// 3. Rapport final
echo "\n📊 Rapport final :\n";
echo "   ⏳ En attente : " . $repository->countPending()->getValue() . "\n";
echo "   ✅ Terminées : " . $repository->countCompleted()->getValue() . "\n";
echo "   ❌ Échouées : " . $repository->countFailed()->getValue() . "\n";
echo "   🚫 Annulées : " . $repository->countCanceled()->getValue() . "\n";

// 4. Vérifier les tâches expirées
$expired = $repository->findExpired(new Iso8601DateTimeVO, new LimitVO(10));
if ($expired->isNotEmpty()) {
    echo "\n⚠️ Tâches expirées détectées : " . $expired->count() . "\n";
    foreach ($expired as $task) {
        echo "   - " . $task->getAlias()->getValue() . "\n";
        // Annuler automatiquement les tâches expirées
        $repository->moveToCanceled($task->toRecord());
    }
}
```

## Voir aussi
- `AbstractRepository` - Classe parente pour les opérations CRUD
- `UniqueTask` - Modèle Eloquent associé
- `UniqueTaskRecord` - Data Transfer Object associé
- `UniqueTaskStatus` - Énumération des états possibles (PENDING, IN_PROGRESS, COMPLETED, FAILED, CANCELED)
- `TaskExecutionDebugRepository` - Journalisation des exécutions
- `UniqueTaskService` - Service métier utilisant ce dépôt
- `RecurringTaskRepository` - Dépôt similaire pour les tâches récurrentes