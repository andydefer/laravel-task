# UniqueTaskRepository - Référence Technique

## Description

Repository de gestion des tâches uniques. Il orchestre le stockage, la récupération et les transitions d'état des tâches uniques, avec un support du verrouillage de lignes pour prévenir les problèmes de concurrence.

## Hiérarchie / Implémentations

```
AbstractRepository<UniqueTask, UniqueTaskRecord>
    └── UniqueTaskRepository
            └── UniqueTaskRepositoryInterface
```

## Rôle principal

Gérer le cycle de vie des tâches uniques en :
- Récupérant les tâches par statut (PENDING, COMPLETED, FAILED, CANCELED)
- Verrouillant les tâches prêtes à être exécutées (`lockForUpdate()`)
- Gérant les transitions d'état (PENDING → COMPLETED/FAILED/CANCELED)
- Comptant les tâches par statut pour le monitoring
- Ajoutant des informations de débogage via `TaskExecutionDebugRepository`

## API / Méthodes publiques

### `findPending(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches en attente (statut PENDING).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, UniqueTask>` - Collection des tâches

**Exemple :**
```php
$tasks = $repository->findPending(new LimitVO(10));
foreach ($tasks as $task) {
    echo $task->getAlias()->getValue();
}
```

---

### `findCompleted(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches terminées avec succès (statut COMPLETED).

---

### `findFailed(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches en échec (statut FAILED).

---

### `findCanceled(LimitVO $limit = new LimitVO()): Collection`

Retourne les tâches annulées (statut CANCELED).

---

### `findReadyToRun(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection`

Retourne les tâches prêtes à être exécutées avec verrouillage de ligne.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO` | Date/heure actuelle |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, UniqueTask>` - Collection des tâches prêtes

**Comportement :**
- Statut = PENDING
- `scheduled_at` ≤ maintenant
- Verrouillage `lockForUpdate()` pour éviter les doublons
- Exécution dans une transaction DB

**Exemple :**
```php
$now = new Iso8601DateTimeVO();
$tasks = $repository->findReadyToRun($now, new LimitVO(50));
```

---

### `findExpired(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection`

Retourne les tâches expirées (scheduled_at + grace_period < maintenant).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `Iso8601DateTimeVO` | Date/heure actuelle |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, UniqueTask>` - Collection des tâches expirées

**Calcul :**
```
expiration = scheduled_at + grace_period_seconds
expirée si now > expiration
```

---

### `findById(UuidVO $id): ?UniqueTask`

Trouve une tâche par son UUID.

---

### `findByAlias(TaskAliasVO $alias): ?UniqueTask`

Trouve une tâche par son alias.

---

### `updateAttempts(UniqueTaskRecord $task, CounterVO $newAttempts): bool`

Met à jour le compteur de tentatives d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Record de la tâche |
| `$newAttempts` | `CounterVO` | Nouveau nombre de tentatives |

**Retourne :** `bool` - `true` si la mise à jour a réussi

---

### `addDebug(UniqueTaskRecord $task, ExecutionStatus $status, DescriptionVO $info): bool`

Ajoute des informations de débogage pour une tâche.

---

### `moveToCompleted(UniqueTaskRecord $task): bool`

Déplace une tâche vers le statut COMPLETED.

---

### `moveToFailed(UniqueTaskRecord $task): bool`

Déplace une tâche vers le statut FAILED.

---

### `moveToCanceled(UniqueTaskRecord $task): bool`

Déplace une tâche vers le statut CANCELED.

---

### `countPending(): CounterVO`, `countCompleted(): CounterVO`, etc.

Compteurs de tâches par statut.

## Cycle de vie des états

```
                    ┌──────────────────┐
                    │                  ▼
PENDING ──────────────────────────▶ COMPLETED
    │                                ▲
    │                                │
    ├─────────────▶ FAILED ──────────┘
    │
    └─────────────▶ CANCELED
```

### Transitions

| Transition | Méthode | Condition |
|------------|---------|-----------|
| PENDING → COMPLETED | `moveToCompleted()` | Exécution réussie |
| PENDING → FAILED | `moveToFailed()` | Échec après max_attempts ou expiration |
| PENDING → CANCELED | `moveToCanceled()` | Annulation manuelle |

## Verrouillage et concurrence

### `findReadyToRun()` - Verrouillage

```sql
SELECT * FROM unique_tasks
WHERE status = 'PENDING' AND scheduled_at <= NOW()
FOR UPDATE;
```

**Avantages :**
- Empêche deux workers de prendre la même tâche
- Garantit l'atomicité des opérations
- Transaction DB intégrée

**Exemple d'utilisation :**
```php
$now = new Iso8601DateTimeVO();
$tasks = $repository->findReadyToRun($now);

foreach ($tasks as $task) {
    // Traitement de la tâche
    // Le verrou est maintenu pendant la transaction
}
```

## Cas d'utilisation

### Cas 1 : Récupération des tâches prêtes

**Problème :** Récupérer les tâches à exécuter avec verrouillage.

```php
$now = new Iso8601DateTimeVO();
$tasks = $repository->findReadyToRun($now, new LimitVO(50));

foreach ($tasks as $task) {
    $record = $repository->modelToRecord($task);
    $result = $runner->run($record);
    
    if ($result->success) {
        $repository->moveToCompleted($record);
    } else {
        $repository->moveToFailed($record);
    }
}
```

---

### Cas 2 : Nettoyage des tâches expirées

**Problème :** Marquer les tâches expirées comme FAILED.

```php
$now = new Iso8601DateTimeVO();
$expiredTasks = $repository->findExpired($now);

foreach ($expiredTasks as $task) {
    $record = $repository->modelToRecord($task);
    $repository->moveToFailed($record);
    
    $repository->addDebug(
        $record,
        ExecutionStatus::FAILED,
        new DescriptionVO('Task expired')
    );
}
```

---

### Cas 3 : Monitoring des compteurs

**Problème :** Afficher les statistiques des tâches.

```php
echo "Pending : {$repository->countPending()->getValue()}\n";
echo "Completed : {$repository->countCompleted()->getValue()}\n";
echo "Failed : {$repository->countFailed()->getValue()}\n";
echo "Canceled : {$repository->countCanceled()->getValue()}\n";
```

---

### Cas 4 : Recherche par alias

**Problème :** Trouver une tâche spécifique pour mise à jour.

```php
$task = $repository->findByAlias($alias);
if ($task !== null) {
    $record = $repository->modelToRecord($task);
    // Mise à jour de la tâche
}
```

## Gestion des erreurs

| Situation | Comportement | Log |
|-----------|--------------|-----|
| `updateAttempts()` - tâche non trouvée | Retourne `false` | `unique_task_update_attempts_not_found` |
| `updateAttempts()` - exception | Retourne `false` | `unique_task_update_attempts_error` |
| `moveToCompleted()` - déjà complétée | Retourne `false` | `unique_task_move_to_completed_not_found_or_already_completed` |
| `moveToFailed()` - déjà en échec | Retourne `false` | `unique_task_move_to_failed_not_found_or_already_failed` |

## Intégration

### Dépendances

- `TaskExecutionDebugRepositoryInterface` : Stockage des débogages
- `LoggerInterface` : Logging des erreurs

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `UniqueTaskService` | API de haut niveau |
| `UniqueTaskProcessor` | Traitement par lots |
| `UniqueTaskRunner` | Exécution individuelle |

## Performance

- **Verrouillage** : `lockForUpdate()` avec transaction DB
- **Indexation** : Index sur `status`, `scheduled_at`, `alias`
- **Expiration** : Calcul en mémoire après requête
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

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\DescriptionVO;

$repository = new UniqueTaskRepository($debugRepository, $logger);

// 1. Récupération des tâches prêtes
$now = new Iso8601DateTimeVO();
$tasks = $repository->findReadyToRun($now, new LimitVO(10));

foreach ($tasks as $task) {
    $record = $repository->modelToRecord($task);
    
    // Simuler l'exécution
    $success = true;
    
    if ($success) {
        $repository->moveToCompleted($record);
        $repository->addDebug(
            $record,
            ExecutionStatus::SUCCEEDED,
            new DescriptionVO('Task executed successfully')
        );
    } else {
        // Incrémenter les tentatives
        $newAttempts = $record->attempts->increment();
        $repository->updateAttempts($record, $newAttempts);
        
        if ($newAttempts->getValue() >= $record->max_attempts->getValue()) {
            $repository->moveToFailed($record);
        }
        
        $repository->addDebug(
            $record,
            ExecutionStatus::FAILED,
            new DescriptionVO('Task execution failed')
        );
    }
}

// 2. Nettoyage des tâches expirées
$expiredTasks = $repository->findExpired($now);
foreach ($expiredTasks as $task) {
    $record = $repository->modelToRecord($task);
    $repository->moveToFailed($record);
}

// 3. Monitoring
echo "Pending : {$repository->countPending()->getValue()}\n";
echo "Completed : {$repository->countCompleted()->getValue()}\n";
echo "Failed : {$repository->countFailed()->getValue()}\n";
```

## Voir aussi

- `RecurringTaskRepository` - Repository de tâches récurrentes
- `TaskExecutionDebugRepository` - Repository de débogage
- `UniqueTaskService` - Service de tâches uniques
- `UniqueTaskProcessor` - Processeur de lots
- `UniqueTaskRunner` - Exécuteur individuel
---