
<!-- ==== ./docs/api-reference/repositories/recurring-task-repository.md ==== -->

# RecurringTaskRepository - Référence Technique

## Description

Repository pour les tâches récurrentes. Fournit un accès typé aux données des tâches récurrentes avec des méthodes spécifiques pour la gestion des statuts et des transitions d'état.

## Hiérarchie / Implémentations

```
AbstractRepository<RecurringTask, RecurringTaskRecord>
    └── RecurringTaskRepository
        └── RecurringTaskRepositoryInterface
```

## Rôle principal

Ce repository sert de couche d'accès aux données pour les tâches récurrentes. Il :

1. **Encapsule** les requêtes Eloquent spécifiques aux tâches récurrentes
2. **Fournit** des méthodes de recherche par statut (WAITING, PLAYING, PAUSED, FINISHED)
3. **Gère** les transitions d'état (`moveToPlaying`, `moveToPaused`, etc.)
4. **Maintient** l'historique des exécutions (`updateAfterRun`)
5. **Applique** les filtres via `RecurringTaskFiltersRecord`

## API

### `applyFilters(Builder $query, AbstractRecord $filters): void`

Applique les filtres à la requête Eloquent.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `Builder` | Requête Eloquent |
| `$filters` | `AbstractRecord` | Filtres à appliquer |

**Filtres supportés :**
- `alias` - Recherche par alias
- `fqcn` - Recherche par classe
- `status` - Recherche par statut
- `start_at_from/to` - Plage de dates de début
- `end_at_from/to` - Plage de dates de fin
- `last_run_at_from/to` - Plage de dates de dernière exécution
- `include_deleted` - Inclut les soft deleted

---

### `findWaiting(): Collection`

Récupère toutes les tâches en statut `WAITING`.

**Retourne :** `Collection<int, RecurringTask>`

**Exemple :**
```php
$waitingTasks = $repository->findWaiting();
foreach ($waitingTasks as $task) {
    echo $task->getAlias(); // 'task-name'
}
```

---

### `findPlaying(): Collection`

Récupère toutes les tâches en statut `PLAYING`.

**Retourne :** `Collection<int, RecurringTask>`

---

### `findPaused(): Collection`

Récupère toutes les tâches en statut `PAUSED`.

**Retourne :** `Collection<int, RecurringTask>`

---

### `findFinished(): Collection`

Récupère toutes les tâches en statut `FINISHED`.

**Retourne :** `Collection<int, RecurringTask>`

---

### `findReadyToRun(string $now): Collection`

Récupère les tâches prêtes à être exécutées (WAITING et start_at <= now).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `string` | Date au format ISO 8601 |

**Retourne :** `Collection<int, RecurringTask>`

**Exemple :**
```php
$ready = $repository->findReadyToRun(date('c'));
```

---

### `findExpired(string $now): Collection`

Récupère les tâches expirées (PLAYING et end_at <= now).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `string` | Date au format ISO 8601 |

**Retourne :** `Collection<int, RecurringTask>`

---

### `findByAlias(string $alias): ?RecurringTask`

Trouve une tâche par son alias.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `string` | Alias de la tâche |

**Retourne :** `?RecurringTask` - Tâche trouvée ou `null`

---

### `moveToPlaying(RecurringTaskRecord $task): void`

Déplace une tâche de `WAITING` vers `PLAYING`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche à déplacer |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `moveToPaused(RecurringTaskRecord $task): void`

Déplace une tâche de `PLAYING` vers `PAUSED`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche à déplacer |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `moveToWaiting(RecurringTaskRecord $task): void`

Déplace une tâche de `PAUSED` vers `WAITING`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche à déplacer |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `moveToFinished(RecurringTaskRecord $task): void`

Déplace une tâche vers `FINISHED` (depuis WAITING ou PLAYING).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche à déplacer |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void`

Met à jour une tâche après son exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche à mettre à jour |
| `$success` | `bool` | Succès ou échec de l'exécution |
| `$error` | `?string` | Message d'erreur si échec |

**Actions :**
1. Ajoute une entrée de debug
2. Met à jour `last_run_at`
3. La tâche reste en `PLAYING`

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `countWaiting(): int`

Compte le nombre de tâches en statut WAITING.

---

### `countPlaying(): int`

Compte le nombre de tâches en statut PLAYING.

---

### `countPaused(): int`

Compte le nombre de tâches en statut PAUSED.

---

### `countFinished(): int`

Compte le nombre de tâches en statut FINISHED.

## Cas d'utilisation

### Cas 1 : Récupérer les tâches à exécuter

```php
$repository = app(RecurringTaskRepository::class);

// Récupérer les tâches prêtes
$ready = $repository->findReadyToRun(date('c'));

foreach ($ready as $task) {
    $repository->moveToPlaying($task);
    // Exécuter la tâche...
}
```

### Cas 2 : Gérer la pause d'une tâche

```php
$repository = app(RecurringTaskRepository::class);

$task = $repository->findByAlias('email-sender');
$repository->moveToPaused($task);
// La tâche est maintenant en PAUSED
```

### Cas 3 : Mettre à jour après exécution

```php
$repository = app(RecurringTaskRepository::class);

$task = $repository->findByAlias('backup-task');
$success = runTask($task);

$repository->updateAfterRun($task, $success, $error);
// last_run_at mis à jour, debug ajouté, statut reste PLAYING
```

### Cas 4 : Recherche par statut

```php
$repository = app(RecurringTaskRepository::class);

$waiting = $repository->countWaiting();
$playing = $repository->countPlaying();
$paused = $repository->countPaused();

echo "En attente: $waiting\n";
echo "En cours: $playing\n";
echo "En pause: $paused\n";
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskRepository                         │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  FINDERS                                                           │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findWaiting()     → Collection<RecurringTask>             │   │
│  │  findPlaying()     → Collection<RecurringTask>             │   │
│  │  findPaused()      → Collection<RecurringTask>             │   │
│  │  findFinished()    → Collection<RecurringTask>             │   │
│  │  findReadyToRun()  → Collection<RecurringTask>             │   │
│  │  findExpired()     → Collection<RecurringTask>             │   │
│  │  findByAlias()     → ?RecurringTask                        │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  MOVES                                                             │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  WAITING ──moveToPlaying──► PLAYING                        │   │
│  │  PLAYING ──moveToPaused───► PAUSED                         │   │
│  │  PAUSED ──moveToWaiting──► WAITING                         │   │
│  │  WAITING/PLAYING ──moveToFinished──► FINISHED              │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  UPDATE                                                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  updateAfterRun() → last_run_at + debug                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  COUNTS                                                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  countWaiting() → int                                       │   │
│  │  countPlaying() → int                                       │   │
│  │  countPaused() → int                                        │   │
│  │  countFinished() → int                                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Tâche non trouvée dans `moveToPlaying` | `RuntimeException` | `Task not found: {alias}` |
| Tâche non trouvée dans `moveToPaused` | `RuntimeException` | `Task not found: {alias}` |
| Tâche non trouvée dans `moveToWaiting` | `RuntimeException` | `Task not found: {alias}` |
| Tâche non trouvée dans `moveToFinished` | `RuntimeException` | `Task not found: {alias}` |
| Tâche non trouvée dans `updateAfterRun` | `RuntimeException` | `Task not found: {alias}` |

## Filtres supportés

| Filtre | Type | Description |
|--------|------|-------------|
| `alias` | `TaskSignatureVO` | Recherche exacte par alias |
| `fqcn` | `string` | Recherche par classe |
| `status` | `RecurringTaskStatus` | Recherche par statut |
| `start_at_from` | `Iso8601DateTimeVO` | start_at >= valeur |
| `start_at_to` | `Iso8601DateTimeVO` | start_at <= valeur |
| `end_at_from` | `Iso8601DateTimeVO` | end_at >= valeur |
| `end_at_to` | `Iso8601DateTimeVO` | end_at <= valeur |
| `last_run_at_from` | `Iso8601DateTimeVO` | last_run_at >= valeur |
| `last_run_at_to` | `Iso8601DateTimeVO` | last_run_at <= valeur |
| `include_deleted` | `bool` | Inclut les soft deleted |

## Performance

- **Complexité** : O(n) pour les finders, O(1) pour les counts
- **Index** : La colonne `alias` est unique, `status` est indexé
- **Soft Delete** : Les soft deleted sont exclus par défaut
- **Mémoire** : Les collections sont chargées en mémoire

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

$repository = app(RecurringTaskRepository::class);

// 1. Récupérer les tâches en attente
$waiting = $repository->findWaiting();

// 2. Récupérer une tâche par son alias
$task = $repository->findByAlias('email-newsletter');

// 3. Déplacer une tâche en PLAYING
$repository->moveToPlaying($task);

// 4. Mettre à jour après exécution
$repository->updateAfterRun($task, true);

// 5. Compter les tâches par statut
echo "WAITING: " . $repository->countWaiting() . "\n";
echo "PLAYING: " . $repository->countPlaying() . "\n";

// 6. Recherche avec filtres
$filters = new RecurringTaskFiltersRecord(
    status: RecurringTaskStatus::PLAYING,
    start_at_from: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
);

$tasks = $repository->findBy(new FindByRecord(filters: $filters));
```

## Voir aussi

- `AbstractRepository` - Classe de base des repositories
- `RecurringTask` - Modèle Eloquent
- `RecurringTaskRecord` - DTO de tâche récurrente
- `UniqueTaskRepository` - Repository des tâches uniques
- `TaskExecutionDebugRepository` - Repository des logs de debug
<!-- ==== ./docs/api-reference/repositories/unique-task-repository.md ==== -->

# UniqueTaskRepository - Référence Technique

## Description

Repository pour les tâches uniques. Fournit un accès typé aux données des tâches uniques avec des méthodes spécifiques pour la gestion des UUID, des statuts et du cycle de vie (PENDING → COMPLETED/FAILED).

## Hiérarchie / Implémentations

```
AbstractRepository<UniqueTask, UniqueTaskRecord>
    └── UniqueTaskRepository
        └── UniqueTaskRepositoryInterface
```

## Rôle principal

Ce repository sert de couche d'accès aux données pour les tâches uniques. Il :

1. **Encapsule** les requêtes Eloquent spécifiques aux tâches uniques
2. **Gère** les UUID comme identifiants primaires (`incrementing = false`)
3. **Fournit** des méthodes de recherche par statut (PENDING, COMPLETED, FAILED)
4. **Gère** les tentatives et la période de grâce (`grace_period`)
5. **Applique** les filtres via `UniqueTaskFiltersRecord`

## API

### `applyFilters(Builder $query, AbstractRecord $filters): void`

Applique les filtres à la requête Eloquent.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `Builder` | Requête Eloquent |
| `$filters` | `AbstractRecord` | Filtres à appliquer |

**Filtres supportés :**
- `id` - Recherche par UUID
- `alias` - Recherche par alias
- `fqcn` - Recherche par classe
- `status` - Recherche par statut
- `scheduled_at_from/to` - Plage de dates planifiées
- `finished_at_from/to` - Plage de dates de fin
- `attempts` - Nombre de tentatives exact
- `max_attempts` - Nombre maximum de tentatives
- `include_deleted` - Inclut les soft deleted

---

### `findPending(): Collection`

Récupère toutes les tâches en statut `PENDING`.

**Retourne :** `Collection<int, UniqueTask>`

**Exemple :**
```php
$pending = $repository->findPending();
foreach ($pending as $task) {
    echo $task->getAlias(); // 'task-name'
}
```

---

### `findCompleted(): Collection`

Récupère toutes les tâches en statut `COMPLETED`.

**Retourne :** `Collection<int, UniqueTask>`

---

### `findFailed(): Collection`

Récupère toutes les tâches en statut `FAILED`.

**Retourne :** `Collection<int, UniqueTask>`

---

### `findReadyToRun(string $now): Collection`

Récupère les tâches prêtes à être exécutées (PENDING et scheduled_at <= now).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `string` | Date au format ISO 8601 |

**Retourne :** `Collection<int, UniqueTask>`

**Exemple :**
```php
$ready = $repository->findReadyToRun(date('c'));
```

---

### `findExpired(string $now): Collection`

Récupère les tâches expirées (PENDING et scheduled_at + grace_period < now).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `string` | Date au format ISO 8601 |

**Retourne :** `Collection<int, UniqueTask>`

**Exemple :**
```php
// Tâche avec scheduled_at = now - 48h, grace_period = 86400 (24h)
// → expirée si now > scheduled_at + 86400
$expired = $repository->findExpired(date('c'));
```

---

### `findById(string $id): ?UniqueTask`

Trouve une tâche par son UUID.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `string` | UUID de la tâche |

**Retourne :** `?UniqueTask` - Tâche trouvée ou `null`

**Validation :** L'UUID doit être valide (format `^[a-f0-9-]{36}$`)

**Exemple :**
```php
$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
```

---

### `updateAttempts(UniqueTaskRecord $task, int $newAttempts): void`

Met à jour le nombre de tentatives d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Tâche à mettre à jour |
| `$newAttempts` | `int` | Nouveau nombre de tentatives |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `addDebug(UniqueTaskRecord $task, string $status, string $info): void`

Ajoute une entrée de debug pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Tâche concernée |
| `$status` | `string` | Statut (succeeded/failed) |
| `$info` | `string` | Informations supplémentaires |

---

### `moveToCompleted(UniqueTaskRecord $task): void`

Déplace une tâche de `PENDING` vers `COMPLETED`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Tâche à déplacer |

**Actions :**
- Statut → `COMPLETED`
- `finished_at` → maintenant

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `moveToFailed(UniqueTaskRecord $task): void`

Déplace une tâche de `PENDING` vers `FAILED`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | Tâche à déplacer |

**Actions :**
- Statut → `FAILED`
- `finished_at` → maintenant

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `countPending(): int`

Compte le nombre de tâches en statut PENDING.

---

### `countCompleted(): int`

Compte le nombre de tâches en statut COMPLETED.

---

### `countFailed(): int`

Compte le nombre de tâches en statut FAILED.

## Cas d'utilisation

### Cas 1 : Récupérer les tâches à exécuter

```php
$repository = app(UniqueTaskRepository::class);

// Récupérer les tâches prêtes
$ready = $repository->findReadyToRun(date('c'));

foreach ($ready as $task) {
    $record = UniqueTaskRecord::from([...]);
    $result = $runner->run($record);
    
    if ($result->success) {
        $repository->moveToCompleted($record);
    } else {
        // Incrémenter les tentatives
        $newAttempts = $record->attempts->increment();
        $repository->updateAttempts($record, $newAttempts->value);
        
        if ($newAttempts->value >= $record->max_attempts->value) {
            $repository->moveToFailed($record);
        }
    }
}
```

### Cas 2 : Recherche par UUID

```php
$repository = app(UniqueTaskRepository::class);

$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
if ($task) {
    echo $task->getAlias();
    echo $task->getStatusVO()->value;
}
```

### Cas 3 : Gestion des tâches expirées

```php
$repository = app(UniqueTaskRepository::class);

$expired = $repository->findExpired(date('c'));
foreach ($expired as $task) {
    $record = UniqueTaskRecord::from([...]);
    $repository->moveToFailed($record);
    // La tâche est maintenant marquée comme FAILED
}
```

### Cas 4 : Statistiques par statut

```php
$repository = app(UniqueTaskRepository::class);

echo "En attente: " . $repository->countPending() . "\n";
echo "Terminées: " . $repository->countCompleted() . "\n";
echo "En échec: " . $repository->countFailed() . "\n";
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskRepository                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  FINDERS                                                           │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findPending()      → Collection<UniqueTask>                │   │
│  │  findCompleted()    → Collection<UniqueTask>                │   │
│  │  findFailed()       → Collection<UniqueTask>                │   │
│  │  findReadyToRun()   → Collection<UniqueTask>                │   │
│  │  findExpired()      → Collection<UniqueTask>                │   │
│  │  findById()         → ?UniqueTask                           │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  MOVES                                                             │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  PENDING ──moveToCompleted──► COMPLETED                    │   │
│  │  PENDING ──moveToFailed────► FAILED                        │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  UPDATE                                                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  updateAttempts() → mise à jour des tentatives              │   │
│  │  addDebug() → ajout d'une entrée de debug                   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  COUNTS                                                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  countPending() → int                                       │   │
│  │  countCompleted() → int                                     │   │
│  │  countFailed() → int                                        │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Tâche non trouvée dans `updateAttempts` | `RuntimeException` | `Task not found: {id}` |
| Tâche non trouvée dans `moveToCompleted` | `RuntimeException` | `Task not found: {id}` |
| Tâche non trouvée dans `moveToFailed` | `RuntimeException` | `Task not found: {id}` |
| ID invalide dans `findById` | ❌ Non bloquant | Retourne `null` |

## Filtres supportés

| Filtre | Type | Description |
|--------|------|-------------|
| `id` | `TaskIdVO` | Recherche par UUID |
| `alias` | `TaskSignatureVO` | Recherche exacte par alias |
| `fqcn` | `string` | Recherche par classe |
| `status` | `UniqueTaskStatus` | Recherche par statut |
| `scheduled_at_from` | `Iso8601DateTimeVO` | scheduled_at >= valeur |
| `scheduled_at_to` | `Iso8601DateTimeVO` | scheduled_at <= valeur |
| `finished_at_from` | `Iso8601DateTimeVO` | finished_at >= valeur |
| `finished_at_to` | `Iso8601DateTimeVO` | finished_at <= valeur |
| `attempts` | `int` | Nombre de tentatives exact |
| `max_attempts` | `int` | Nombre maximum de tentatives |
| `include_deleted` | `bool` | Inclut les soft deleted |

## Performance

- **Complexité** : O(n) pour les finders, O(1) pour les counts
- **Index** : La colonne `id` est la clé primaire (UUID), `alias` est indexé
- **Soft Delete** : Les soft deleted sont exclus par défaut
- **Mémoire** : Les collections sont chargées en mémoire

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Ramsey\Uuid\Uuid;

$repository = app(UniqueTaskRepository::class);

// 1. Récupérer les tâches en attente
$pending = $repository->findPending();

// 2. Récupérer une tâche par UUID
$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');

// 3. Récupérer les tâches prêtes
$ready = $repository->findReadyToRun(date('c'));

// 4. Récupérer les tâches expirées
$expired = $repository->findExpired(date('c'));

// 5. Mettre à jour les tentatives
$record = UniqueTaskRecord::from([...]);
$repository->updateAttempts($record, 2);

// 6. Ajouter un debug
$repository->addDebug($record, 'succeeded', 'Task executed successfully');

// 7. Marquer comme terminée ou en échec
if ($success) {
    $repository->moveToCompleted($record);
} else {
    $repository->moveToFailed($record);
}

// 8. Compter par statut
echo "PENDING: " . $repository->countPending() . "\n";
echo "COMPLETED: " . $repository->countCompleted() . "\n";
echo "FAILED: " . $repository->countFailed() . "\n";

// 9. Recherche avec filtres
$filters = new UniqueTaskFiltersRecord(
    status: UniqueTaskStatus::PENDING,
    scheduled_at_from: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    max_attempts: 3,
);

$tasks = $repository->findBy(new FindByRecord(filters: $filters));
```

## Voir aussi

- `AbstractRepository` - Classe de base des repositories
- `UniqueTask` - Modèle Eloquent avec UUID
- `UniqueTaskRecord` - DTO de tâche unique
- `RecurringTaskRepository` - Repository des tâches récurrentes
- `TaskExecutionDebugRepository` - Repository des logs de debug
<!-- ==== ./docs/api-reference/repositories/task-repository.md ==== -->

# TaskRepository - Référence Technique

## Description

Repository pour la persistance des tâches uniques (non récurrentes). Gère le stockage, la lecture, la suppression et l'archivage des tâches au format JSONL dans l'arborescence `pending/` et `completed/`.

## Hiérarchie

```
TaskRepositoryInterface
    └── TaskRepository (implements)
```

## Rôle principal

Fournir un accès unifié aux tâches uniques stockées dans des fichiers JSONL, avec prise en charge de l'ordre de tri (`TaskOrder`), des limites de requête, et de l'archivage vers le répertoire `completed/` avec structure par date.

## Interface : TaskRepositoryInterface

### Description

Définit le contrat pour la persistance des tâches uniques.

### Méthodes

#### `save(TaskRecord $task): void`

Sauvegarde une tâche unique dans le répertoire `pending/`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à sauvegarder (avec Value Objects) |

**Comportement :**
- Crée le dossier `pending/` s'il n'existe pas
- Supprime l'ancien fichier si une tâche avec le même ID existe déjà (écrasement)
- Écrit la tâche au format JSONL (une ligne)

#### `find(TaskIdVO $id): ?TaskRecord`

Recherche une tâche unique par son identifiant.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `TaskIdVO` | Identifiant UUID de la tâche |

**Retourne :** `TaskRecord|null` - La tâche si elle existe et est en statut `PENDING`, `null` sinon

#### `findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection`

Retourne une collection de tâches uniques en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à retourner (0 = aucun résultat) |
| `$order` | `TaskOrder` | Ordre de tri : `OLDEST` (FIFO) ou `NEWEST` (LIFO) |

**Retourne :** `TaskRecordCollection` - Collection typée de `TaskRecord`

**Comportement :**
- Ne retourne que les tâches avec `status === TaskStatus::PENDING`
- Trie les fichiers par date de modification selon `$order`
- Applique la limite si spécifiée

#### `delete(TaskIdVO $id): void`

Supprime une tâche unique du répertoire `pending/`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `TaskIdVO` | Identifiant UUID de la tâche à supprimer |

#### `moveToCompleted(TaskRecord $task, bool $success = true): void`

Archive une tâche dans le répertoire `completed/` après exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à archiver |
| `$success` | `bool` | `true` pour un succès, `false` pour un échec |

**Comportement :**
- Crée le dossier `completed/{Y-m-d}/` si nécessaire
- Met à jour le statut (`SUCCESS` ou `FAILED`)
- Ajoute un timestamp `completed_at`
- Supprime la tâche du répertoire `pending/`

## Implémentation : TaskRepository

### Dépendances

```php
public function __construct(
    private readonly TaskStorageContext $context,   // Gestion des chemins
    private readonly JsonlService $jsonl,           // Lecture/écriture JSONL
    private readonly HydrationService $hydration,   // Hydratation des objets
    private readonly FileSystemInterface $fs,       // Opérations fichiers
) {}
```

### Arborescence des fichiers

```
storage/tasks/
├── pending/                          # Tâches en attente
│   └── {task_id}.jsonl               # Une tâche par fichier
│
└── completed/                        # Tâches archivées
    └── Y-m-d/                        # Partition par date
        └── {task_id}.jsonl           # Une tâche par fichier
```

## Méthodes internes

### `save(TaskRecord $task): void`

```php
public function save(TaskRecord $task): void
{
    $pendingDir = $this->context->getPendingDir();
    $pendingDir->ensureExists($this->fs);
    
    // Écrasement sécurisé (delete + write)
    $path = $pendingDir->filePath($task->id);
    if ($this->fs->exists($path)) {
        $this->fs->delete($path);
    }
    
    $this->jsonl->write($task);
}
```

### `find(TaskIdVO $id): ?TaskRecord`

```php
public function find(TaskIdVO $id): ?TaskRecord
{
    $path = $this->context->getPendingDir()->filePath($id);
    
    if (!$this->fs->exists($path)) {
        return null;
    }
    
    $lines = $this->jsonl->readAll($path);
    
    if (empty($lines)) {
        return null;
    }
    
    $task = $this->hydration->hydrate(TaskRecord::class, $lines[0]);
    
    // Seules les tâches PENDING sont accessibles
    return $task->status === TaskStatus::PENDING ? $task : null;
}
```

### `findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection`

```php
public function findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection
{
    if ($limit === 0) {
        return new TaskRecordCollection();
    }
    
    $files = $pendingDir->allFiles($this->fs);
    
    // Tri par date de modification selon l'ordre
    usort($files, function ($a, $b) use ($order) {
        $timeA = $this->fs->lastModified($a);
        $timeB = $this->fs->lastModified($b);
        return $order->compare($timeA, $timeB);
    });
    
    // Application de la limite
    if ($limit !== null && $limit > 0) {
        $files = array_slice($files, 0, $limit);
    }
    
    // Hydratation et filtrage par statut PENDING
    foreach ($files as $file) {
        $lines = $this->jsonl->readAll($file);
        foreach ($lines as $line) {
            $task = $this->hydration->hydrate(TaskRecord::class, $line);
            if ($task->status === TaskStatus::PENDING) {
                $tasks->add($task);
            }
        }
    }
    
    return $tasks;
}
```

### `moveToCompleted(TaskRecord $task, bool $success = true): void`

```php
public function moveToCompleted(TaskRecord $task, bool $success = true): void
{
    $source = $this->context->getPendingDir()->filePath($task->id);
    
    if (!$this->fs->exists($source)) {
        return;
    }
    
    $lines = $this->jsonl->readAll($source);
    
    if (empty($lines)) {
        return;
    }
    
    $taskData = $lines[0];
    $taskData['status'] = $success ? TaskStatus::SUCCESS->value : TaskStatus::FAILED->value;
    $taskData['completed_at'] = (new Iso8601DateTimeVO())->value;
    
    $date = new TaskDateVO(date('Y-m-d'));
    $target = $this->context->getCompletedDir()->filePathWithDate($task->id, $date);
    
    $this->fs->put($target, json_encode($taskData) . "\n");
    $this->fs->delete($source);
}
```

## Cas d'utilisation

### Cas 1 : Sauvegarde d'une nouvelle tâche

```php
<?php

use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\Enums\TaskStatus;

$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('backup'),
    class: BackupTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
);

$repository->save($task);
// Fichier créé : storage/tasks/pending/550e8400-e29b-41d4-a716-446655440000.jsonl
```

### Cas 2 : Récupération des tâches en attente (FIFO)

```php
<?php

// Récupère les 10 tâches les plus anciennes
$tasks = $repository->findAll(10, TaskOrder::OLDEST);

foreach ($tasks as $task) {
    echo $task->id->value . "\n";
}
```

### Cas 3 : Récupération des tâches en attente (LIFO)

```php
<?php

// Récupère les 10 tâches les plus récentes
$tasks = $repository->findAll(10, TaskOrder::NEWEST);
```

### Cas 4 : Archivage d'une tâche réussie

```php
<?php

$task = $repository->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
$repository->moveToCompleted($task, true);
// Fichier déplacé vers : storage/tasks/completed/2026-06-14/550e8400-e29b-41d4-a716-446655440000.jsonl
// Statut : SUCCESS
```

## Flux d'exécution

```
save()
    │
    ├── ensureExists(pendingDir)
    │
    ├── delete(existing file if any)
    │
    └── jsonl->write()

find()
    │
    ├── filePath(id)
    ├── fs->exists()
    ├── jsonl->readAll()
    ├── hydration->hydrate(TaskRecord::class)
    └── filtrer par status === PENDING

findAll()
    │
    ├── allFiles()
    ├── usort() avec TaskOrder::compare()
    ├── array_slice() pour limit
    ├── Pour chaque fichier
    │   ├── jsonl->readAll()
    │   └── hydration->hydrate()
    └── Filtrer status === PENDING

moveToCompleted()
    │
    ├── readAll(source)
    ├── Modifier status (SUCCESS/FAILED)
    ├── Ajouter completed_at
    ├── ensureExists(completedDir)
    ├── target = filePathWithDate()
    ├── fs->put(target)
    └── fs->delete(source)
```

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| Fichier non trouvé dans `find()` | Retourne `null` |
| Dossier `pending/` inexistant dans `findAll()` | Retourne collection vide |
| Fichier source inexistant dans `moveToCompleted()` | Retour silencieux (pas d'erreur) |
| Fichier source vide dans `moveToCompleted()` | Retour silencieux |
| Fichier avec statut non `PENDING` dans `find()` | Retourne `null` |

## Intégration

### Dépendances

```
TaskRepository
    ├── TaskStorageContext (chemins)
    ├── JsonlService (lecture/écriture JSONL)
    ├── HydrationService (hydratation)
    └── FileSystemInterface (opérations fichiers)
```

### Avec TaskBatchService

```php
class TaskBatchService
{
    public function processUniqueOnly(?int $limit = null): BatchResultRecord
    {
        $order = $this->config->isOldestOrder() ? TaskOrder::OLDEST : TaskOrder::NEWEST;
        
        foreach ($this->taskRepository->findAll($limit, $order) as $task) {
            // Exécuter la tâche
        }
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `save()` | O(1) | Écriture d'un fichier JSONL |
| `find()` | O(1) | Lecture d'un fichier |
| `findAll()` | O(n log n) | Tri + lecture de n fichiers |
| `delete()` | O(1) | Suppression d'un fichier |
| `moveToCompleted()` | O(1) | Lecture + écriture + suppression |

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

use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

// 1. Initialiser le repository (via le container Laravel)
$repository = app(TaskRepositoryInterface::class);

// 2. Créer une nouvelle tâche
$payload = new TaskPayloadRecord(
    type: 'backup',
    data: new StrictDataObject(['database' => 'mysql']),
);

$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('backup-database'),
    class: BackupTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    created_at: new Iso8601DateTimeVO(),
    start_at: new Iso8601DateTimeVO(),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
    delay_seconds: new CounterVO(0),
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// 3. Sauvegarder
$repository->save($task);
echo "Tâche sauvegardée\n";

// 4. Récupérer par ID
$found = $repository->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
if ($found) {
    echo "Tâche trouvée : {$found->signature->value}\n";
}

// 5. Lister les tâches en attente (FIFO, sans limite)
$allPending = $repository->findAll();
echo "Nombre de tâches en attente : {$allPending->count()}\n";

// 6. Lister les 5 tâches les plus récentes
$recent = $repository->findAll(5, TaskOrder::NEWEST);
foreach ($recent as $t) {
    echo "- {$t->id->value} ({$t->signature->value})\n";
}

// 7. Archiver une tâche réussie
$repository->moveToCompleted($task, true);
echo "Tâche archivée\n";

// 8. Vérifier qu'elle n'est plus dans pending
$notFound = $repository->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
if ($notFound === null) {
    echo "Tâche déplacée vers completed/\n";
}
```

## Voir aussi

- `TaskRepositoryInterface` - Interface implémentée
- `RecurringTaskRepository` - Repository pour les tâches récurrentes
- `TaskStorageContext` - Contexte de stockage (chemins)
- `JsonlService` - Service de lecture/écriture JSONL
- `HydrationService` - Service d'hydratation
- `TaskRecord` - Record de tâche
- `TaskOrder` - Enum pour l'ordre de tri (OLDEST/NEWEST)
- `TaskDateVO` - Value Object pour la date d'archivage
- `TaskDirectoryVO` - Value Object pour les chemins de dossiers
---
<!-- ==== ./docs/api-reference/services/recurring-task-service.md ==== -->

# RecurringTaskService - Référence Technique

## Description

Service métier pour la gestion des tâches récurrentes. Fournit une API complète pour l'enregistrement, l'exécution, la gestion d'état et la recherche des tâches récurrentes.

## Hiérarchie / Implémentations

```
RecurringTaskServiceInterface
    └── RecurringTaskService
```

## Rôle principal

Ce service est le point d'entrée principal pour la gestion des tâches récurrentes. Il orchestre toutes les opérations métier :

1. **Enregistrement** des nouvelles tâches récurrentes
2. **Exécution** des tâches en `PLAYING`
3. **Gestion d'état** (pause, reprise, terminaison)
4. **Modification** des paramètres (intervalle, date de début)
5. **Recherche** et consultation des tâches
6. **Suppression** des tâches

## API

### `register(string $taskClass, StrictDataObject $payload, RecurringTaskConfigInterface $config): TaskSignatureVO`

Enregistre une nouvelle tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskClass` | `string` | Classe de la tâche (doit étendre `AbstractRecurringTask`) |
| `$payload` | `StrictDataObject` | Données de la tâche |
| `$config` | `RecurringTaskConfigInterface` | Configuration de la tâche |

**Retourne :** `TaskSignatureVO` - Alias de la tâche créée

**Exceptions :** 
- `InvalidArgumentException` - Si la classe est invalide
- `RuntimeException` - Si une tâche avec le même alias existe déjà

**Exemple :**
```php
$service = app(RecurringTaskService::class);

$config = new RecurringTaskConfig(
    alias: new TaskSignatureVO('email-newsletter'),
    description: 'Send newsletter emails',
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
    max_attempts: new CounterVO(3),
);

$alias = $service->register(
    NewsletterTask::class,
    StrictDataObject::from(['list' => 'subscribers']),
    $config
);
```

---

### `run(TaskSignatureVO $alias): bool`

Exécute une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si l'exécution est réussie

**Conditions d'exécution :**
- Statut = `PLAYING`
- `end_at` non dépassé

**Exemple :**
```php
$success = $service->run(new TaskSignatureVO('email-newsletter'));
```

---

### `process(?int $limit = null): array`

Exécute toutes les tâches récurrentes prêtes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de tâches à exécuter (`null` = illimité) |

**Retourne :** `array{success: int, failed: int, finished: int}` - Résultats de l'exécution

**Exemple :**
```php
$results = $service->process(10);
// ['success' => 8, 'failed' => 2, 'finished' => 0]
```

---

### `pause(TaskSignatureVO $alias): void`

Met une tâche en pause.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |

**Conditions :** La tâche doit être en `PLAYING`

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas ou n'est pas en `PLAYING`

**Exemple :**
```php
$service->pause(new TaskSignatureVO('email-newsletter'));
// Statut → PAUSED
```

---

### `resume(TaskSignatureVO $alias): void`

Reprend une tâche en pause.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |

**Conditions :** La tâche doit être en `PAUSED`

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas ou n'est pas en `PAUSED`

**Exemple :**
```php
$service->resume(new TaskSignatureVO('email-newsletter'));
// Statut → WAITING
```

---

### `finish(TaskSignatureVO $alias): void`

Termine une tâche prématurément.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->finish(new TaskSignatureVO('email-newsletter'));
// Statut → FINISHED
```

---

### `advanceStartAt(TaskSignatureVO $alias, \DateTimeInterface $newStartAt): void`

Avance la date de début d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |
| `$newStartAt` | `\DateTimeInterface` | Nouvelle date de début |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->advanceStartAt(
    new TaskSignatureVO('email-newsletter'),
    now()->addHours(2)
);
```

---

### `postponeStartAt(TaskSignatureVO $alias, \DateTimeInterface $newStartAt): void`

Repousse la date de début d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |
| `$newStartAt` | `\DateTimeInterface` | Nouvelle date de début |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->postponeStartAt(
    new TaskSignatureVO('email-newsletter'),
    now()->addDays(2)
);
```

---

### `changeInterval(TaskSignatureVO $alias, int $intervalSeconds): void`

Modifie l'intervalle d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |
| `$intervalSeconds` | `int` | Nouvel intervalle en secondes |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->changeInterval(
    new TaskSignatureVO('email-newsletter'),
    7200 // 2 heures
);
```

---

### `find(TaskSignatureVO $alias): ?RecurringTaskRecord`

Trouve une tâche par son alias.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |

**Retourne :** `?RecurringTaskRecord` - DTO de la tâche ou `null`

**Exemple :**
```php
$task = $service->find(new TaskSignatureVO('email-newsletter'));
if ($task) {
    echo $task->status->value; // 'playing'
}
```

---

### `findWaiting(?int $limit = null): array`

Récupère toutes les tâches en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de résultats |

**Retourne :** `array<RecurringTaskRecord>`

---

### `findPlaying(?int $limit = null): array`

Récupère toutes les tâches en cours d'exécution.

---

### `findPaused(?int $limit = null): array`

Récupère toutes les tâches en pause.

---

### `findFinished(?int $limit = null): array`

Récupère toutes les tâches terminées.

---

### `exists(TaskSignatureVO $alias): bool`

Vérifie si une tâche existe.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |

**Retourne :** `bool`

---

### `delete(TaskSignatureVO $alias): void`

Supprime une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `count(): int`

Compte le nombre total de tâches récurrentes.

### `countWaiting(): int`

Compte le nombre de tâches en attente.

### `countPlaying(): int`

Compte le nombre de tâches en cours d'exécution.

### `countPaused(): int`

Compte le nombre de tâches en pause.

### `countFinished(): int`

Compte le nombre de tâches terminées.

## Cas d'utilisation

### Cas 1 : Enregistrement et exécution d'une tâche récurrente

```php
$service = app(RecurringTaskService::class);

// 1. Enregistrer
$config = new RecurringTaskConfig(
    alias: new TaskSignatureVO('backup-database'),
    description: 'Backup database',
    interval_seconds: new CounterVO(86400), // 1 jour
    start_at: new Iso8601DateTimeVO('2026-01-01T00:00:00+00:00'),
    max_attempts: new CounterVO(3),
);

$alias = $service->register(
    DatabaseBackupTask::class,
    StrictDataObject::from(['database' => 'main']),
    $config
);

// 2. Passer en PLAYING (normalement fait par le processeur)
// Le processeur le fera automatiquement quand start_at sera atteint

// 3. Exécuter
$success = $service->run($alias);
```

### Cas 2 : Gestion d'une tâche en pause

```php
$service = app(RecurringTaskService::class);
$alias = new TaskSignatureVO('report-generator');

// Mettre en pause
$service->pause($alias);
// La tâche n'est plus exécutée

// Reprendre plus tard
$service->resume($alias);
// La tâche redevient exécutable
```

### Cas 3 : Modification des paramètres d'une tâche

```php
$service = app(RecurringTaskService::class);
$alias = new TaskSignatureVO('email-newsletter');

// Changer l'intervalle de 1h à 2h
$service->changeInterval($alias, 7200);

// Repousser le démarrage
$service->postponeStartAt($alias, now()->addDays(7));

// Avancer le démarrage
$service->advanceStartAt($alias, now()->addHours(2));
```

### Cas 4 : Consultation des tâches

```php
$service = app(RecurringTaskService::class);

// Récupérer une tâche spécifique
$task = $service->find(new TaskSignatureVO('email-newsletter'));

// Lister les tâches en attente
$waiting = $service->findWaiting(10);

// Compter les tâches par statut
echo "WAITING: " . $service->countWaiting() . "\n";
echo "PLAYING: " . $service->countPlaying() . "\n";
echo "PAUSED: " . $service->countPaused() . "\n";
echo "FINISHED: " . $service->countFinished() . "\n";
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskService                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ENREGISTREMENT                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  register()                                                │   │
│  │  ├─ validateTaskClass()                                    │   │
│  │  ├─ Vérifier l'unicité de l'alias                         │   │
│  │  ├─ Créer le Record                                        │   │
│  │  └─ repository->create()                                   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  EXÉCUTION                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  run()                                                     │   │
│  │  ├─ findByAlias() → modèle                                 │   │
│  │  ├─ modelToRecord() → Record                               │   │
│  │  ├─ Vérifier statut = PLAYING                              │   │
│  │  ├─ Vérifier end_at                                        │   │
│  │  ├─ instantiateTask()                                      │   │
│  │  ├─ $task->execute()                                       │   │
│  │  └─ updateAfterRun()                                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  GESTION D'ÉTAT                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  pause()   → PLAYING → PAUSED                              │   │
│  │  resume()  → PAUSED → WAITING                              │   │
│  │  finish()  → WAITING/PLAYING → FINISHED                   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  MODIFICATION                                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  advanceStartAt()   → updateRaw('start_at')                │   │
│  │  postponeStartAt()  → updateRaw('start_at')                │   │
│  │  changeInterval()   → updateRaw('interval_seconds')        │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  RECHERCHE                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  find()          → ?RecurringTaskRecord                    │   │
│  │  findWaiting()   → array<RecurringTaskRecord>              │   │
│  │  findPlaying()   → array<RecurringTaskRecord>              │   │
│  │  findPaused()    → array<RecurringTaskRecord>              │   │
│  │  findFinished()  → array<RecurringTaskRecord>              │   │
│  │  exists()        → bool                                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  SUPPRESSION                                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  delete() → repository->delete()                           │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  COMPTAGE                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  count()           → int                                    │   │
│  │  countWaiting()    → int                                    │   │
│  │  countPlaying()    → int                                    │   │
│  │  countPaused()     → int                                    │   │
│  │  countFinished()   → int                                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Classe invalide | `InvalidArgumentException` | `Task must extend AbstractRecurringTask` |
| Alias déjà existant | `RuntimeException` | `Recurring task '{alias}' already exists` |
| Tâche non trouvée | `RuntimeException` | `Task not found: {alias}` |
| Pause sur tâche non PLAYING | `RuntimeException` | `Task '{alias}' is not in PLAYING state` |
| Reprise sur tâche non PAUSED | `RuntimeException` | `Task '{alias}' is not in PAUSED state` |

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `RecurringTaskRepositoryInterface` | Accès aux données |
| `LoggerInterface` | Journalisation |
| `HydrationService` | Hydratation des objets |
| `Application` (Laravel) | Instanciation des classes |

## Performance

- **Complexité** : O(1) pour les opérations unitaires, O(n) pour `process()`
- **Mémoire** : Les collections sont chargées en mémoire pour les finders
- **Base de données** : Chaque opération génère des requêtes Eloquent
- **Cache** : Aucun cache implémenté

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$service = app(RecurringTaskService::class);

// 1. Enregistrer une tâche
$config = new RecurringTaskConfig(
    alias: new TaskSignatureVO('cleanup-temp'),
    description: 'Clean temporary files',
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
    max_attempts: new CounterVO(3),
);

$alias = $service->register(
    CleanupTask::class,
    StrictDataObject::from(['path' => '/tmp']),
    $config
);

echo "Tâche enregistrée: {$alias->value}\n";

// 2. Vérifier l'existence
if ($service->exists($alias)) {
    echo "La tâche existe\n";
}

// 3. Mettre en pause
$service->pause($alias);
echo "Tâche en pause\n";

// 4. Reprendre
$service->resume($alias);
echo "Tâche reprise\n";

// 5. Exécuter
$success = $service->run($alias);
echo $success ? "Exécution réussie\n" : "Exécution échouée\n";

// 6. Compter les tâches
echo "Total: " . $service->count() . "\n";
echo "En attente: " . $service->countWaiting() . "\n";
echo "En cours: " . $service->countPlaying() . "\n";

// 7. Supprimer
$service->delete($alias);
echo "Tâche supprimée\n";
```

## Voir aussi

- `RecurringTaskServiceInterface` - Interface du service
- `RecurringTaskRepository` - Repository des tâches récurrentes
- `RecurringTaskConfig` - Configuration des tâches
- `RecurringTaskRecord` - DTO des tâches récurrentes
- `UniqueTaskService` - Service des tâches uniques
<!-- ==== ./docs/api-reference/services/unique-task-service.md ==== -->

# UniqueTaskService - Référence Technique

## Description

Service métier pour la gestion des tâches uniques. Fournit une API complète pour l'enregistrement, l'exécution, la recherche et la gestion des tâches uniques.

## Hiérarchie / Implémentations

```
UniqueTaskServiceInterface
    └── UniqueTaskService
```

## Rôle principal

Ce service est le point d'entrée principal pour la gestion des tâches uniques. Il orchestre toutes les opérations métier :

1. **Enregistrement** des nouvelles tâches uniques avec génération d'UUID
2. **Exécution** des tâches en `PENDING` avec gestion des tentatives
3. **Recherche** et consultation des tâches
4. **Suppression** des tâches
5. **Comptage** des tâches par statut

## API

### `register(string $taskClass, StrictDataObject $payload, ?UniqueTaskConfigInterface $config = null): TaskIdVO`

Enregistre une nouvelle tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskClass` | `string` | Classe de la tâche (doit étendre `AbstractUniqueTask`) |
| `$payload` | `StrictDataObject` | Données de la tâche |
| `$config` | `?UniqueTaskConfigInterface` | Configuration personnalisée (optionnelle) |

**Retourne :** `TaskIdVO` - UUID de la tâche créée

**Exceptions :** `InvalidArgumentException` - Si la classe est invalide

**Exemple :**
```php
$service = app(UniqueTaskService::class);

$taskId = $service->register(
    SendWelcomeEmailTask::class,
    StrictDataObject::from(['email' => 'john@example.com']),
    new UniqueTaskConfig(
        alias: new TaskSignatureVO('welcome-email'),
        description: 'Send welcome email',
        scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
        max_attempts: new CounterVO(3),
    )
);
```

---

### `run(TaskIdVO $taskId): bool`

Exécute une tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | UUID de la tâche |

**Retourne :** `bool` - `true` si l'exécution est réussie

**Conditions d'exécution :**
- Statut = `PENDING`
- `attempts < max_attempts`

**Gestion des tentatives :**
- Succès → Statut `COMPLETED`
- Échec avec `attempts < max_attempts` → `attempts` incrémenté, statut `PENDING`
- Échec avec `attempts >= max_attempts` → Statut `FAILED`

**Exemple :**
```php
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');
$success = $service->run($taskId);
```

---

### `process(?int $limit = null): array`

Exécute toutes les tâches uniques prêtes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de tâches à exécuter (`null` = illimité) |

**Retourne :** `array{success: int, failed: int}` - Résultats de l'exécution

**Exemple :**
```php
$results = $service->process(10);
// ['success' => 7, 'failed' => 3]
```

---

### `find(TaskIdVO $taskId): ?UniqueTaskRecord`

Trouve une tâche par son UUID.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | UUID de la tâche |

**Retourne :** `?UniqueTaskRecord` - DTO de la tâche ou `null`

**Exemple :**
```php
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');
$task = $service->find($taskId);
if ($task) {
    echo $task->status->value; // 'pending'
}
```

---

### `findPending(?int $limit = null): array`

Récupère toutes les tâches en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de résultats |

**Retourne :** `array<UniqueTaskRecord>`

---

### `findCompleted(?int $limit = null): array`

Récupère toutes les tâches terminées avec succès.

---

### `findFailed(?int $limit = null): array`

Récupère toutes les tâches en échec.

---

### `exists(TaskIdVO $taskId): bool`

Vérifie si une tâche existe.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | UUID de la tâche |

**Retourne :** `bool`

---

### `delete(TaskIdVO $taskId): void`

Supprime une tâche (soft delete).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | UUID de la tâche |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `count(): int`

Compte le nombre total de tâches uniques.

### `countPending(): int`

Compte le nombre de tâches en attente.

### `countCompleted(): int`

Compte le nombre de tâches terminées avec succès.

### `countFailed(): int`

Compte le nombre de tâches en échec.

## Cas d'utilisation

### Cas 1 : Enregistrement et exécution d'une tâche unique

```php
$service = app(UniqueTaskService::class);

// 1. Enregistrer
$taskId = $service->register(
    SendWelcomeEmailTask::class,
    StrictDataObject::from(['email' => 'john@example.com'])
);

// 2. Modifier la date d'exécution (si nécessaire)
// Le service ne fournit pas directement cette méthode,
// mais on peut utiliser le repository directement

// 3. Exécuter
$success = $service->run($taskId);
```

### Cas 2 : Exécution avec gestion des tentatives

```php
$service = app(UniqueTaskService::class);
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');

// Première tentative
$success = $service->run($taskId);
if (!$success) {
    // La tâche a échoué, attempts incrémenté
    $task = $service->find($taskId);
    echo "Tentative {$task->attempts->value}/{$task->max_attempts->value}\n";
    
    // Deuxième tentative
    $success = $service->run($taskId);
    if (!$success) {
        // Si attempts >= max_attempts, statut → FAILED
    }
}
```

### Cas 3 : Consultation des tâches

```php
$service = app(UniqueTaskService::class);

// Récupérer une tâche spécifique
$task = $service->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));

// Lister les tâches en attente
$pending = $service->findPending(10);

// Compter les tâches par statut
echo "PENDING: " . $service->countPending() . "\n";
echo "COMPLETED: " . $service->countCompleted() . "\n";
echo "FAILED: " . $service->countFailed() . "\n";
```

### Cas 4 : Suppression d'une tâche

```php
$service = app(UniqueTaskService::class);

$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');

if ($service->exists($taskId)) {
    $service->delete($taskId);
    echo "Tâche supprimée\n";
}
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskService                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ENREGISTREMENT                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  register()                                                │   │
│  │  ├─ validateTaskClass()                                    │   │
│  │  ├─ Récupérer la config (base ou personnalisée)           │   │
│  │  ├─ Générer un UUID                                        │   │
│  │  ├─ Créer le Record                                        │   │
│  │  └─ repository->create()                                   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  EXÉCUTION                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  run()                                                     │   │
│  │  ├─ findById() → modèle                                    │   │
│  │  ├─ modelToRecord() → Record                               │   │
│  │  ├─ Vérifier statut = PENDING                              │   │
│  │  ├─ Vérifier attempts < max_attempts                       │   │
│  │  ├─ instantiateTask()                                      │   │
│  │  ├─ $task->execute()                                       │   │
│  │  ├─ Succès → moveToCompleted()                             │   │
│  │  └─ Échec →                                                │   │
│  │     ├─ attempts + 1                                        │   │
│  │     ├─ attempts >= max_attempts → moveToFailed()          │   │
│  │     └─ attempts < max_attempts → update()                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  PROCESS                                                           │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  process()                                                 │   │
│  │  ├─ findReadyToRun() → Collection<UniqueTask>             │   │
│  │  ├─ Appliquer la limite                                    │   │
│  │  ├─ Pour chaque tâche : modelToRecord() → run()          │   │
│  │  └─ Retourner ['success', 'failed']                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  RECHERCHE                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  find()          → ?UniqueTaskRecord                       │   │
│  │  findPending()   → array<UniqueTaskRecord>                 │   │
│  │  findCompleted() → array<UniqueTaskRecord>                 │   │
│  │  findFailed()    → array<UniqueTaskRecord>                 │   │
│  │  exists()        → bool                                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  SUPPRESSION                                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  delete() → $model->delete() (soft delete)                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  COMPTAGE                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  count()           → int                                    │   │
│  │  countPending()    → int                                    │   │
│  │  countCompleted()  → int                                    │   │
│  │  countFailed()     → int                                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Classe invalide | `InvalidArgumentException` | `Task must extend AbstractUniqueTask` |
| Tâche non trouvée | `RuntimeException` | `Task not found: {id}` |

## Gestion des tentatives

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Gestion des tentatives                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Démarrage : attempts = 0, max_attempts = 3                       │
│                                                                     │
│  Exécution 1 → Échec → attempts = 1                               │
│  Exécution 2 → Échec → attempts = 2                               │
│  Exécution 3 → Échec → attempts = 3 → FAILED                     │
│                                                                     │
│  Exécution 1 → Succès → COMPLETED                                 │
│                                                                     │
│  Exécution 1 → Échec → attempts = 1                               │
│  Exécution 2 → Succès → COMPLETED                                 │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskRepositoryInterface` | Accès aux données |
| `LoggerInterface` | Journalisation |
| `HydrationService` | Hydratation des objets |
| `UuidFactoryInterface` | Génération des UUID |
| `Application` (Laravel) | Instanciation des classes |

## Performance

- **Complexité** : O(1) pour les opérations unitaires, O(n) pour `process()`
- **Mémoire** : Les collections sont chargées en mémoire pour les finders
- **Base de données** : Chaque opération génère des requêtes Eloquent
- **Cache** : Aucun cache implémenté

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$service = app(UniqueTaskService::class);

// 1. Enregistrer une tâche
$taskId = $service->register(
    SendWelcomeEmailTask::class,
    StrictDataObject::from(['email' => 'john@example.com']),
    new UniqueTaskConfig(
        alias: new TaskSignatureVO('welcome-email'),
        description: 'Send welcome email',
        scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
        max_attempts: new CounterVO(3),
    )
);

echo "Tâche enregistrée: {$taskId->value}\n";

// 2. Vérifier l'existence
if ($service->exists($taskId)) {
    echo "La tâche existe\n";
}

// 3. Exécuter
$success = $service->run($taskId);
echo $success ? "Exécution réussie\n" : "Exécution échouée\n";

// 4. Récupérer la tâche
$task = $service->find($taskId);
if ($task) {
    echo "Statut: {$task->status->value}\n";
    echo "Tentatives: {$task->attempts->value}/{$task->max_attempts->value}\n";
}

// 5. Compter les tâches
echo "Total: " . $service->count() . "\n";
echo "En attente: " . $service->countPending() . "\n";
echo "Terminées: " . $service->countCompleted() . "\n";
echo "En échec: " . $service->countFailed() . "\n";

// 6. Supprimer
$service->delete($taskId);
echo "Tâche supprimée\n";
```

## Voir aussi

- `UniqueTaskServiceInterface` - Interface du service
- `UniqueTaskRepository` - Repository des tâches uniques
- `UniqueTaskConfig` - Configuration des tâches
- `UniqueTaskRecord` - DTO des tâches uniques
- `RecurringTaskService` - Service des tâches récurrentes
<!-- ==== ./docs/api-reference/runners/unique-task-runner.md ==== -->

# UniqueTaskRunner - Référence Technique

## Description

Moteur d'exécution des tâches uniques. Prend une tâche en `PENDING`, valide son état, l'exécute une seule fois, puis la marque comme `COMPLETED` ou `FAILED`.

## Hiérarchie / Implémentations

```
UniqueTaskRunnerInterface
    └── UniqueTaskRunner
```

## Rôle principal

Ce runner est le moteur d'exécution d'une **seule** tâche unique. Il :

1. **Valide** que la tâche peut être exécutée (`canRun`)
2. **Instancie** la classe de tâche concrète
3. **Exécute** la tâche avec son payload
4. **Ajoute** une entrée de debug
5. **Met à jour** le statut (COMPLETED ou FAILED)
6. **Retourne** le résultat de l'exécution

## API

### `run(UniqueTaskRecord $record): ExecutionResultRecord`

Point d'entrée principal du runner.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à exécuter |

**Retourne :** `ExecutionResultRecord` - Résultat de l'exécution

**Cas de retour :**
- `success: true, error: null` → Exécution réussie, tâche marquée COMPLETED
- `success: false, error: TaskErrorRecord` → Échec de validation ou d'exécution, tâche marquée FAILED

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$runner = new UniqueTaskRunner($validator, $logger, $hydration, $app, $repository);
$result = $runner->run($record);

if ($result->success) {
    echo "✅ Tâche exécutée en {$result->execution_time}s";
} else {
    echo "❌ Erreur: {$result->error->error}";
}
```

---

### `instantiateTask(UniqueTaskRecord $record): AbstractUniqueTask`

Instancie la classe de tâche concrète.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à instancier |

**Retourne :** `AbstractUniqueTask` - Instance de la tâche

**Processus :**
1. Crée un `UniqueTaskContext`
2. Injecte l'ID, l'alias, la date planifiée
3. Retourne une nouvelle instance de `$record->fqcn`

**Exceptions :** `Error` - Si la classe n'existe pas ou n'étend pas `AbstractUniqueTask`

---

### `calculateDuration(Iso8601DateTimeVO $start): float`

Calcule la durée d'exécution en secondes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$start` | `Iso8601DateTimeVO` | Date de début de l'exécution |

**Retourne :** `float` - Durée en secondes (différence entre `$start` et maintenant)

## Cas d'utilisation

### Cas 1 : Exécution réussie d'une tâche

```php
$runner = app(UniqueTaskRunner::class);

// Tâche en PENDING, scheduled_at dans le passé
$result = $runner->run($record);

// $result->success = true
// $result->execution_time = 0.45 (secondes)
// Statut → COMPLETED
// Debug ajouté avec status = 'succeeded'
```

### Cas 2 : Échec de validation

```php
// Tâche avec scheduled_at dans le futur
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Validation failed: Task is not ready to run (scheduled_at in the future)'
// Statut → FAILED
// Debug ajouté avec status = 'failed'
```

### Cas 3 : Échec d'exécution avec exception

```php
// Tâche qui lance une exception
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Test exception'
// Statut → FAILED
// Debug ajouté avec status = 'failed'
```

### Cas 4 : Tâche avec max_attempts atteint

```php
// Tâche avec attempts = 3, max_attempts = 3
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Validation failed: Maximum attempts reached'
// Statut → FAILED
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskRunner                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ENTRÉE : UniqueTaskRecord                                         │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 1 : VALIDATION                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $validator->canRun($record)                        │   │   │
│  │  │  ├─ Classe existe et étend AbstractUniqueTask ?   │   │   │
│  │  │  ├─ Statut = PENDING ?                              │   │   │
│  │  │  ├─ scheduled_at <= now ?                           │   │   │
│  │  │  ├─ attempts < max_attempts ?                       │   │   │
│  │  │  └─ non expiré (grace_period) ?                    │   │   │
│  │  │  ❌ Échec → retourne ExecutionResultRecord(fail)   │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 2 : LOG DÉBUT                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $logger->logStart($record)                         │   │   │
│  │  │  → "unique_task_started"                            │   │   │
│  │  │  → task_id, alias, scheduled_at, attempts          │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 3 : INSTANCIATION                                   │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $task = new $record->fqcn($context, ...)          │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 4 : EXÉCUTION                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  try {                                              │   │   │
│  │  │    $task->execute($payload)                         │   │   │
│  │  │    $success = true                                  │   │   │
│  │  │    $logger->logSuccess()                            │   │   │
│  │  │  } catch (\Throwable $e) {                          │   │   │
│  │  │    $success = false                                 │   │   │
│  │  │    $error = $e->getMessage()                       │   │   │
│  │  │    $logger->logFailure()                            │   │   │
│  │  │  }                                                  │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 5 : AJOUTER LE DEBUG                                │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $repository->addDebug($record, status, info)      │   │   │
│  │  │  → task_type = 'unique'                            │   │   │
│  │  │  → status = 'succeeded' ou 'failed'                │   │   │
│  │  │  → info = message                                  │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 6 : METTRE À JOUR LE STATUT                         │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  if ($success) {                                    │   │   │
│  │  │    $repository->moveToCompleted($record)            │   │   │
│  │  │    ✅ status = COMPLETED                            │   │   │
│  │  │    ✅ finished_at = now                             │   │   │
│  │  │  } else {                                           │   │   │
│  │  │    $repository->moveToFailed($record)               │   │   │
│  │  │    ❌ status = FAILED                               │   │   │
│  │  │    ❌ finished_at = now                             │   │   │
│  │  │  }                                                  │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  SORTIE : ExecutionResultRecord                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  success: true/false,                                      │   │
│  │  error: TaskErrorRecord|null,                              │   │
│  │  execution_time: float                                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message | Action |
|-----------|-----------|---------|--------|
| Classe inexistante | ❌ Non bloquant | `Validation failed: Invalid task class...` | Retourne `success: false` |
| Statut ≠ PENDING | ❌ Non bloquant | `Validation failed: Task is not in PENDING state` | Retourne `success: false` |
| scheduled_at > now | ❌ Non bloquant | `Validation failed: Task is not ready to run` | Retourne `success: false` |
| attempts >= max_attempts | ❌ Non bloquant | `Validation failed: Maximum attempts reached` | Retourne `success: false` |
| Tâche expirée | ❌ Non bloquant | `Validation failed: Task has expired` | Retourne `success: false` |
| Exception dans l'exécution | `Throwable` | Message de l'exception | Retourne `success: false` |

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskValidatorInterface` | Valide la tâche avant exécution |
| `UniqueTaskLoggerInterface` | Logge le début, succès, échec |
| `HydrationService` | Utilisé pour l'instanciation |
| `Application` (Laravel) | Pour instancier les classes |
| `UniqueTaskRepositoryInterface` | Pour ajouter le debug et mettre à jour le statut |

## Cycle de vie d'une tâche unique

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche unique                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. Création                                                       │
│     status = PENDING                                               │
│     attempts = 0                                                   │
│     scheduled_at = date prévue                                     │
│                                                                     │
│  2. Runner appelé                                                  │
│     ┌─────────────────────────────────────────────────────────┐   │
│     │  Validation :                                           │   │
│     │  ✅ Classe valide                                       │   │
│     │  ✅ status = PENDING                                    │   │
│     │  ✅ scheduled_at <= now                                 │   │
│     │  ✅ attempts < max_attempts                             │   │
│     │  ✅ non expiré                                          │   │
│     └─────────────────────────────────────────────────────────┘   │
│                                                                     │
│  3. Exécution                                                      │
│     ┌─────────────────────────────────────────────────────────┐   │
│     │  SUCCÈS :                                               │   │
│     │  ✅ status = COMPLETED                                  │   │
│     │  ✅ finished_at = now                                   │   │
│     │  ✅ debug status = 'succeeded'                          │   │
│     │                                                          │   │
│     │  ÉCHEC :                                                 │   │
│     │  ❌ status = FAILED                                     │   │
│     │  ❌ finished_at = now                                   │   │
│     │  ❌ debug status = 'failed'                             │   │
│     └─────────────────────────────────────────────────────────┘   │
│                                                                     │
│  4. Fin de vie                                                    │
│     Status terminal : COMPLETED ou FAILED                         │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Performance

- **Complexité** : O(1) - une seule tâche exécutée
- **Mémoire** : Une seule instance de tâche créée
- **Base de données** : 2 requêtes (addDebug + moveToCompleted/Failed)
- **Temps** : Variable selon la tâche exécutée

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Ramsey\Uuid\Uuid;

// Créer une tâche en PENDING
$record = new UniqueTaskRecord(
    id: new TaskIdVO((string) Uuid::uuid4()),
    alias: new TaskSignatureVO('send-welcome-email'),
    fqcn: SendWelcomeEmailTask::class,
    payload: StrictDataObject::from(['email' => 'john@example.com']),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(5)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// Exécuter
$runner = app(UniqueTaskRunner::class);
$result = $runner->run($record);

if ($result->success) {
    echo "✅ Tâche exécutée avec succès\n";
    echo "⏱️ Temps: {$result->execution_time}s\n";
    // Statut → COMPLETED
} else {
    echo "❌ Échec: {$result->error->error}\n";
    // Statut → FAILED
}
```

## Voir aussi

- `UniqueTaskProcessor` - Processeur de lots
- `UniqueTaskValidator` - Validation des tâches
- `ExecutionResultRecord` - Structure de résultat
- `RecurringTaskRunner` - Runner pour les tâches récurrentes
- `UniqueTaskLogger` - Logger des tâches uniques
<!-- ==== ./docs/api-reference/runners/recurring-task-runner.md ==== -->

# RecurringTaskRunner - Référence Technique

## Description

Moteur d'exécution des tâches récurrentes. Prend une tâche en `PLAYING`, vérifie si elle doit être exécutée selon son intervalle, l'exécute et met à jour son état.

## Hiérarchie / Implémentations

```
RecurringTaskRunnerInterface
    └── RecurringTaskRunner
```

## Rôle principal

Ce runner est le moteur d'exécution d'une **seule** tâche récurrente. Il :

1. **Valide** que la tâche peut être exécutée (`canRun`)
2. **Vérifie** si l'intervalle est atteint (`shouldRunAgain`)
3. **Instancie** la classe de tâche concrète
4. **Exécute** la tâche avec son payload
5. **Met à jour** la tâche après exécution (`updateAfterRun`)
6. **Retourne** le résultat de l'exécution

## API

### `run(RecurringTaskRecord $record): ExecutionResultRecord`

Point d'entrée principal du runner.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à exécuter |

**Retourne :** `ExecutionResultRecord` - Résultat de l'exécution

**Cas de retour :**
- `success: true, error: null` → Exécution réussie
- `success: false, error: TaskErrorRecord` → Échec de validation ou d'exécution
- `success: true, error: null, execution_time: 0.0` → Intervalle non atteint (skip)

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$runner = new RecurringTaskRunner($validator, $logger, $hydration, $app, $repository);
$result = $runner->run($record);

if ($result->success) {
    echo "Tâche exécutée en {$result->execution_time}s";
} else {
    echo "Erreur: {$result->error->error}";
}
```

---

### `instantiateTask(RecurringTaskRecord $record): AbstractRecurringTask`

Instancie la classe de tâche concrète.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à instancier |

**Retourne :** `AbstractRecurringTask` - Instance de la tâche

**Processus :**
1. Crée un `RecurringTaskContext`
2. Injecte l'alias, l'intervalle, les dates
3. Retourne une nouvelle instance de `$record->fqcn`

---

### `calculateDuration(Iso8601DateTimeVO $start): float`

Calcule la durée d'exécution en secondes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$start` | `Iso8601DateTimeVO` | Date de début de l'exécution |

**Retourne :** `float` - Durée en secondes (différence entre `$start` et maintenant)

## Cas d'utilisation

### Cas 1 : Exécution d'une tâche récurrente

```php
$runner = app(RecurringTaskRunner::class);

// Tâche en PLAYING, last_run_at = 10:00, interval = 3600 (1h)
// Exécution à 11:00 → intervalle atteint
$result = $runner->run($record);

// $result->success = true
// $result->execution_time = 0.45 (secondes)
// last_run_at mis à jour à 11:00
```

### Cas 2 : Intervalle non atteint (skip)

```php
// Tâche en PLAYING, last_run_at = 10:00, interval = 3600 (1h)
// Exécution à 10:30 → intervalle non atteint
$result = $runner->run($record);

// $result->success = true
// $result->execution_time = 0.0
// last_run_at NON mis à jour
// Aucun debug ajouté
```

### Cas 3 : Échec de validation

```php
// Tâche en WAITING (non exécutable)
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Validation failed: Task is in WAITING state, not PLAYING'
```

### Cas 4 : Échec d'exécution avec exception

```php
// Tâche qui lance une exception
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Test exception'
// last_run_at mis à jour (même en échec)
// Debug ajouté avec status = 'failed'
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskRunner                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ENTRÉE : RecurringTaskRecord                                      │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 1 : VALIDATION                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $validator->canRun($record)                        │   │   │
│  │  │  ├─ Statut = PLAYING ?                              │   │   │
│  │  │  └─ end_at pas dépassé ?                           │   │   │
│  │  │  ❌ Échec → retourne ExecutionResultRecord(fail)   │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 2 : VÉRIFICATION INTERVALLE                        │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $validator->shouldRunAgain($record)                │   │   │
│  │  │  ├─ last_run_at null ? → OUI                       │   │   │
│  │  │  └─ now - last_run_at >= interval ? → OUI          │   │   │
│  │  │  ❌ Non → retourne ExecutionResultRecord(skip)     │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 3 : LOG DÉBUT                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $logger->logStart($record)                         │   │   │
│  │  │  → "recurring_task_started"                         │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 4 : INSTANCIATION                                   │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $task = new $record->fqcn($context, ...)          │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 5 : EXÉCUTION                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  try {                                              │   │   │
│  │  │    $task->execute($payload)                         │   │   │
│  │  │    $success = true                                  │   │   │
│  │  │    $logger->logSuccess()                            │   │   │
│  │  │  } catch (\Throwable $e) {                          │   │   │
│  │  │    $success = false                                 │   │   │
│  │  │    $error = $e->getMessage()                       │   │   │
│  │  │    $logger->logFailure()                            │   │   │
│  │  │  }                                                  │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 6 : MISE À JOUR                                     │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $repository->updateAfterRun($record, $success, $error) │   │   │
│  │  │  → last_run_at = maintenant                          │   │   │
│  │  │  → Ajout d'une entrée de debug                      │   │   │
│  │  │  → Statut reste PLAYING                             │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  SORTIE : ExecutionResultRecord                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  success: true/false,                                      │   │
│  │  error: TaskErrorRecord|null,                              │   │
│  │  execution_time: float                                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message | Action |
|-----------|-----------|---------|--------|
| Tâche non en PLAYING | ❌ Non bloquant | `Validation failed: Task is in WAITING state` | Retourne `success: false` |
| Tâche expirée | ❌ Non bloquant | `Validation failed: Task has expired` | Retourne `success: false` |
| Intervalle non atteint | ❌ Non bloquant | - | Retourne `success: true, execution_time: 0.0` |
| Exception dans l'exécution | `Throwable` | Message de l'exception | `updateAfterRun` avec `success: false` |

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `RecurringTaskValidatorInterface` | Valide la tâche avant exécution |
| `RecurringTaskLoggerInterface` | Logge le début, succès, échec |
| `HydrationService` | Utilisé pour l'instanciation |
| `Application` (Laravel) | Pour instancier les classes |
| `RecurringTaskRepositoryInterface` | Pour mettre à jour la tâche |

## Performance

- **Complexité** : O(1) - une seule tâche exécutée
- **Mémoire** : Une seule instance de tâche créée
- **Base de données** : 1 requête pour `updateAfterRun`
- **Temps** : Variable selon la tâche exécutée

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

// Créer une tâche en PLAYING
$record = new RecurringTaskRecord(
    alias: new TaskSignatureVO('email-newsletter'),
    fqcn: EmailNewsletterTask::class,
    payload: StrictDataObject::from(['list' => 'subscribers']),
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO('2026-06-22T10:00:00+00:00'),
    end_at: new Iso8601DateTimeVO('2026-12-31T23:59:59+00:00'),
    status: RecurringTaskStatus::PLAYING,
    last_run_at: new Iso8601DateTimeVO('2026-06-22T10:00:00+00:00'),
);

// Exécuter
$runner = app(RecurringTaskRunner::class);
$result = $runner->run($record);

if ($result->success && $result->execution_time > 0) {
    echo "✅ Tâche exécutée avec succès\n";
    echo "⏱️ Temps: {$result->execution_time}s\n";
} elseif ($result->success && $result->execution_time === 0.0) {
    echo "⏭️ Intervalle non atteint, aucune exécution\n";
} else {
    echo "❌ Échec: {$result->error->error}\n";
}
```

## Voir aussi

- `RecurringTaskProcessor` - Processeur de lots
- `RecurringTaskValidator` - Validation des tâches
- `ExecutionResultRecord` - Structure de résultat
- `UniqueTaskRunner` - Runner pour les tâches uniques
- `RecurringTaskLogger` - Logger des tâches récurrentes
<!-- ==== ./docs/api-reference/abstract-task.md ==== -->

# AbstractTask - Référence Technique

## Description

Classe abstraite de base pour toutes les tâches du système. Fournit les fonctionnalités communes d'exécution, de journalisation structurée, les hooks de cycle de vie et l'accès au contexte via `TaskContext`. Utilise l'injection de dépendances dans le constructeur (immutable).

## Hiérarchie

```
AbstractTask
```

La classe est abstraite et doit être étendue par toutes les tâches concrètes. Le constructeur est `final` pour garantir l'immutabilité.

## Rôle principal

Définir le contrat et le comportement standard pour l'exécution des tâches, incluant la journalisation automatique des événements (démarrage, succès, échec), les points d'extension via les méthodes `before()` et `after()`, et l'accès au payload via `$this->context->getPayload()`.

## API / Méthodes publiques

### `__construct(TaskContext $context, LoggerInterface $logger, HydrationService $hydration): void` (final)

Constructeur avec injection des dépendances. Le constructeur est `final` pour garantir l'immutabilité.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$context` | `TaskContext` | Contexte d'exécution (payload, taskId, signature, app) |
| `$logger` | `LoggerInterface` | Service de journalisation |
| `$hydration` | `HydrationService` | Service d'hydratation pour la création d'objets |

**Exemple :**
```php
$context = new TaskContext();
$context->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
$context->setSignature(new TaskSignatureVO('backup-database'));
$context->setLaravelApp(app());

$task = new BackupTask($context, $logger, $hydration);
```

### `getConfig(): TaskConfigRecord` (abstraite)

Retourne la configuration de la tâche avec les Value Objects.

**Retourne :** `TaskConfigRecord` - Configuration incluant signature (`TaskSignatureVO`), délai (`CounterVO`), tentatives max (`CounterVO`), etc.

**Exemple :**
```php
public function getConfig(): TaskConfigRecord
{
    return new TaskConfigRecord(
        signature: new TaskSignatureVO('backup-database'),
        description: 'Sauvegarde la base de données',
        delay_seconds: new CounterVO(0),
        max_attempts: new CounterVO(3),
        start_at: null,
        end_at: null,
    );
}
```

### `execute(TaskPayloadRecord $payload): void` (final)

Point d'entrée principal de la tâche. Gère automatiquement la journalisation et les hooks. Le payload est stocké dans le `TaskContext`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$payload` | `TaskPayloadRecord` | Données d'entrée de la tâche (type + StrictDataObject) |

**Retourne :** `void`

**Exceptions :** `\Throwable` - Re-lève toute exception de `process()`

**Exemple :**
```php
$payload = new TaskPayloadRecord(
    type: 'backup',
    data: new StrictDataObject(['database' => 'mysql']),
);

$task->execute($payload);
```

### `info(string $message): void`

Enregistre un message d'information dans les logs. Crée automatiquement un log de type `task_output` avec l'événement 'info'.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `string` | Message à logger |

**Exemple :**
```php
$this->info("Traitement de l'utilisateur {$userId}");
```

### `error(string $message): void`

Enregistre un message d'erreur dans les logs. Crée automatiquement un log de type `task_output` avec l'événement 'error'.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `string` | Message d'erreur à logger |

**Exemple :**
```php
$this->error("Impossible de connecter à l'API : {$error}");
```

## Propriétés protégées

| Propriété | Type | Description |
|-----------|------|-------------|
| `$context` | `TaskContext` | Contexte d'exécution (payload, IDs, app Laravel) |
| `$logger` | `LoggerInterface` | Service de journalisation |
| `$hydration` | `HydrationService` | Service d'hydratation |

## Hooks protégés

### `before(): void`

Hook appelé avant l'exécution de `process()`. À surcharger pour des actions de pré-traitement.

**Exemple :**
```php
protected function before(): void
{
    $this->info("Préparation de la tâche...");
    $this->context->getLaravelApp()->make(DatabaseService::class)->beginTransaction();
}
```

### `after(bool $success, ?string $error = null): void`

Hook appelé après l'exécution de `process()` (succès ou échec).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$success` | `bool` | Indique si la tâche a réussi |
| `$error` | `string|null` | Message d'erreur si échec |

**Exemple :**
```php
protected function after(bool $success, ?string $error = null): void
{
    if ($success) {
        $this->info("Tâche terminée avec succès");
    } else {
        $this->error("Tâche échouée : {$error}");
    }
}
```

### `process(): void` (abstraite)

Logique métier principale de la tâche. À implémenter dans les classes filles. Accès au payload via `$this->context->getPayload()`.

## Pattern : Dependency Injection (Injection de dépendances via constructeur)

Le pattern d'injection de dépendances est utilisé pour fournir à la tâche ses dépendances externes (logger, contexte) via le constructeur plutôt que par des setters.

### Problème résolu

```php
// ❌ Sans injection - Couplage fort, setters requis
class BadTask extends AbstractTask
{
    protected function process(): void
    {
        // Dépend du logger injecté via setter
        $this->logger->info("Processing...");
        // Impossible de garantir que le logger est défini
    }
}

// Nécessite 3 appels avant execute()
$task->setLogger($logger)->setTaskId($id)->setSignature($signature)->execute($payload);
```

### Solution avec injection dans le constructeur

```php
// ✅ Avec injection dans le constructeur - Garantie d'initialisation
class GoodTask extends AbstractTask
{
    // Le constructeur est final et accepte toutes les dépendances
    // Toutes les propriétés sont disponibles dès l'instanciation
}

// Une seule ligne pour créer la tâche
$task = new GoodTask($context, $logger, $hydration);
$task->execute($payload);  // Toutes les dépendances sont déjà présentes
```

### Avantages du pattern

| Aspect | Sans injection (setters) | Avec injection (constructeur) |
|--------|--------------------------|-------------------------------|
| **État** | Mutable (setters appelables à tout moment) | Immutable (dépendances figées) |
| **Testabilité** | Bonne | Excellente (tout est injecté) |
| **Sécurité** | Risque d'utilisation avant setter | Garantie d'initialisation |
| **Complexité d'utilisation** | 3-4 appels avant execute() | 1 appel constructeur |
| **Nombre de lignes de code** | Plus | Moins |

## Pattern : Template Method

`AbstractTask` utilise le pattern **Template Method** : l'algorithme d'exécution est défini dans `execute()` (final), et les étapes personnalisables sont déléguées aux méthodes `before()`, `process()`, `after()`.

## Flux d'exécution

```
execute(TaskPayloadRecord $payload)
    │
    ├── context->setPayload($payload)
    │
    ├── Log "task_started"
    │   ├── signature (depuis context)
    │   └── task_id (si présent)
    │
    ├── before() hook
    │
    ├── try
    │   ├── process() hook (logique métier)
    │   ├── after(true)
    │   └── Log "task_completed" (success)
    │
    └── catch (\Throwable $e)
        ├── after(false, $e->getMessage())
        ├── Log "task_failed" (error)
        └── throw $e
```

## Logs automatiques

| Événement | Type de log | Niveau | Contenu |
|-----------|-------------|--------|---------|
| `task_started` | `task` | `info` | signature, task_id (optionnel) |
| `task_completed` | `task` | `info` | signature, task_id (optionnel), status=success |
| `task_failed` | `task` | `error` | signature, task_id (optionnel), status=failed, error |
| `info` | `task_output` | `info` | event=info, message |
| `error` | `task_output` | `error` | event=error, message |

## Cas d'utilisation

### Cas 1 : Tâche simple avec log et accès payload

```php
final class SendEmailTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('send-email'),
            description: 'Envoie un email',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );
    }
    
    protected function process(): void
    {
        $data = $this->context->getPayload()->data;
        $email = $data->email;
        $subject = $data->subject ?? 'Welcome';
        
        $this->info("Début de l'envoi d'email à {$email}");
        
        // Logique d'envoi
        if (mail($email, $subject, $data->body)) {
            $this->info("Email envoyé avec succès à {$email}");
        } else {
            $this->error("Échec de l'envoi d'email à {$email}");
            throw new \RuntimeException("Email sending failed");
        }
    }
}
```

### Cas 2 : Tâche avec hooks et accès au container Laravel

```php
final class BackupTask extends AbstractTask
{
    private DatabaseService $db;
    
    protected function before(): void
    {
        // Accès au container Laravel via le contexte
        $this->db = $this->context->getLaravelApp()->make(DatabaseService::class);
        $this->info("Préparation de la sauvegarde");
        $this->db->beginTransaction();
    }
    
    protected function process(): void
    {
        $data = $this->context->getPayload()->data;
        $database = $data->database ?? 'default';
        
        $this->info("Sauvegarde de la base {$database}");
        // Logique de sauvegarde
    }
    
    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->db->commit();
            $this->info("Sauvegarde terminée avec succès");
        } else {
            $this->db->rollBack();
            $this->error("Sauvegarde échouée : {$error}");
        }
    }
}
```

## Intégration

### Avec TaskRunnerService

```php
// TaskRunnerService instancie la tâche avec le constructeur
private function instantiateTask(string $className, TaskRecord $task): AbstractTask
{
    $context = new TaskContext();
    $context->setTaskId($task->id);
    $context->setSignature($task->signature);
    $context->setLaravelApp($this->app);
    
    return new $className($context, $this->logger, $this->hydration);
}
```

### Avec le système de logging

Les logs sont structurés au format JSON et incluent automatiquement :
- `signature` - Type de tâche (via `TaskContext`)
- `task_id` - Identifiant unique de la tâche (si présent dans le contexte)
- `event` - Type d'événement (started/completed/failed/info/error)
- `status` - Succès/échec (pour completed/failed)
- `error` - Message d'erreur (pour failed)

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `execute()` | O(1) + temps de `process()` | Dépend de l'implémentation |
| `info()` / `error()` | O(1) | Écriture synchrone via LoggerInterface |

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

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class ProcessOrderTask extends AbstractTask
{
    private int $orderId;
    
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('process-order'),
            description: 'Traite une commande client',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );
    }
    
    protected function before(): void
    {
        $payload = $this->context->getPayload();
        $this->orderId = $payload->data->order_id;
        $this->info("Début du traitement de la commande {$this->orderId}");
    }
    
    protected function process(): void
    {
        if ($this->orderId <= 0) {
            throw new \InvalidArgumentException("Invalid order ID");
        }
        
        $this->info("Traitement de la commande {$this->orderId}");
        // Logique métier...
    }
    
    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->info("Commande {$this->orderId} traitée avec succès");
        } else {
            $this->error("Échec du traitement de la commande {$this->orderId} : {$error}");
        }
    }
}

// Utilisation
$context = new TaskContext();
$context->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
$context->setSignature(new TaskSignatureVO('process-order'));
$context->setLaravelApp(app());

$logger = app(LoggerInterface::class);
$hydration = new HydrationService();

$task = new ProcessOrderTask($context, $logger, $hydration);

$payload = new TaskPayloadRecord(
    type: 'order',
    data: new StrictDataObject(['order_id' => 12345]),
);

$task->execute($payload);
```

## Voir aussi

- `TaskContext` - Contexte d'exécution (payload, IDs, app Laravel)
- `TaskRunnerService` - Service qui instancie et exécute les tâches
- `TaskConfigRecord` - Record de configuration (avec Value Objects)
- `TaskPayloadRecord` - Record de payload (type + StrictDataObject)
- `LoggerInterface` - Interface de journalisation
- `HydrationService` - Service d'hydratation
- `TaskRecord` - Record de persistance pour tâches uniques
- `RecurringTaskRecord` - Record de persistance pour tâches récurrentes
- `CounterVO` - Value Object pour les compteurs
- `TaskIdVO` / `TaskSignatureVO` - Value Objects d'identifiants

---
<!-- ==== ./docs/api-reference/directives/process-tasks-directive.md ==== -->

# ProcessTasksDirective - Référence Technique

## Description

Directive console pour exécuter un lot de tâches en une seule opération. Elle orchestre le traitement des tâches uniques et récurrentes avec des options de filtrage et de limitation.

## Hiérarchie

```
AbstractDirective
    └── ProcessTasksDirective
```

## Rôle principal

Cette directive sert de point d'entrée CLI pour le traitement par lots des tâches. Elle coordonne l'exécution des services `UniqueTaskService` et `RecurringTaskService`, agrège les résultats et les présente à l'utilisateur.

## API

### `getSignature(): string`

Retourne la signature de la directive pour la console.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `string` - La signature de la directive

**Exemple :**
```php
$directive = new ProcessTasksDirective($context, $interaction);
echo $directive->getSignature();
// 'process-tasks {--unique-only} {--recurring-only} {--verbose} {--limit=}'
```

---

### `shouldBootLaravel(): bool`

Indique si Laravel doit être booté avant l'exécution.

**Retourne :** `bool` - Toujours `true` car la directive dépend des services Laravel

**Exemple :**
```php
if ($directive->shouldBootLaravel()) {
    // Laravel sera booté avant l'exécution
}
```

---

### `getDescription(): string`

Retourne la description de la directive.

**Retourne :** `string` - Description lisible par l'utilisateur

**Exemple :**
```php
echo $directive->getDescription();
// 'Process all pending tasks in a single batch (no polling, no waiting)'
```

---

### `getAliases(): StringTypedCollection`

Retourne les alias de la directive.

**Retourne :** `StringTypedCollection` - Collection des alias

**Exemple :**
```php
$aliases = $directive->getAliases();
echo $aliases->first(); // 'task:process'
echo $aliases->last();  // 'tasks:process'
```

---

### `execute(): ExitCode`

Point d'entrée principal de la directive. Orchestre la validation, le traitement et l'affichage des résultats.

| Étape | Action |
|-------|--------|
| 1 | Valide les options |
| 2 | Récupère les services |
| 3 | Exécute le traitement par lots |
| 4 | Affiche les résultats |
| 5 | Retourne le code de sortie |

**Retourne :** `ExitCode` - Code de sortie (SUCCESS ou FAILURE)

**Exceptions :** `RuntimeException` - Si Laravel n'est pas disponible

**Exemple :**
```bash
./vendor/bin/directive process-tasks --limit=10 --verbose
```

---

### Méthodes privées

#### `getUniqueTaskService(): UniqueTaskServiceInterface`

Récupère le service des tâches uniques depuis le conteneur Laravel.

#### `getRecurringTaskService(): RecurringTaskServiceInterface`

Récupère le service des tâches récurrentes depuis le conteneur Laravel.

#### `validateOptions(): ?ExitCode`

Valide les options de la ligne de commande.

#### `getValidatedLimit(): ?int`

Récupère et valide la limite.

#### `displayProcessingStart(?int $limit): void`

Affiche le message de début de traitement.

#### `executeBatchProcessing(...): BatchResultRecord`

Orchestre l'exécution des tâches selon les options sélectionnées.

#### `processUniqueOnly(...): BatchResultRecord`

Traite uniquement les tâches uniques.

#### `processRecurringOnly(...): BatchResultRecord`

Traite uniquement les tâches récurrentes.

#### `processFull(...): BatchResultRecord`

Traite tous les types de tâches.

#### `displayResultsSummary(BatchResultRecord $record): void`

Affiche le résumé des résultats.

#### `displayTaskTypeSummary(...): void`

Affiche le résumé par type de tâche.

#### `displayErrorsIfVerbose(bool $verbose, BatchResultRecord $record): void`

Affiche les erreurs en mode verbose.

#### `getDurationMilliseconds(BatchResultRecord $record): int`

Calcule la durée d'exécution en millisecondes.

## Cas d'utilisation

### Cas 1 : Traitement standard de toutes les tâches

```bash
./vendor/bin/directive process-tasks
```

Exécute toutes les tâches prêtes (uniques et récurrentes) sans limite.

---

### Cas 2 : Traitement avec limite

```bash
./vendor/bin/directive process-tasks --limit=50 --verbose
```

Traite un maximum de 50 tâches avec affichage détaillé.

---

### Cas 3 : Traitement unique uniquement

```bash
./vendor/bin/directive process-tasks --unique-only --limit=10
```

Traite uniquement les 10 premières tâches uniques prêtes.

---

### Cas 4 : Traitement récurrent uniquement

```bash
./vendor/bin/directive process-tasks --recurring-only --verbose
```

Traite toutes les tâches récurrentes prêtes avec affichage détaillé.

---

## Flux d'exécution

```
execute()
    ├── validateOptions()
    │   ├── Vérifie que --unique-only et --recurring-only ne sont pas ensemble
    │   └── Vérifie que limit > 0
    │
    ├── displayProcessingStart($limit)
    │
    ├── getUniqueTaskService()
    ├── getRecurringTaskService()
    │
    ├── executeBatchProcessing()
    │   ├── [--unique-only] → processUniqueOnly()
    │   │   └── UniqueTaskService::process($limit) → BatchResultRecord
    │   │
    │   ├── [--recurring-only] → processRecurringOnly()
    │   │   └── RecurringTaskService::process($limit) → BatchResultRecord
    │   │
    │   └── [default] → processFull()
    │       ├── UniqueTaskService::process($limit)
    │       └── RecurringTaskService::process($limit)
    │
    ├── displayResultsSummary($record)
    │
    ├── displayErrorsIfVerbose($verbose, $record)
    │
    └── return ExitCode
        ├── FAILURE si unique_failed > 0 ou recurring_failed > 0
        └── SUCCESS sinon
```

## Options disponibles

| Option | Type | Description |
|--------|------|-------------|
| `--unique-only` | Flag | Traite uniquement les tâches uniques |
| `--recurring-only` | Flag | Traite uniquement les tâches récurrentes |
| `--verbose` | Flag | Affiche les détails et les erreurs |
| `--limit=N` | int | Limite le nombre de tâches à N |

## Résultat attendu

### Mode normal
```
Processing tasks...

=== Batch Results ===
  Unique tasks: 15 processed (✅ 12, ❌ 3)
  Recurring tasks: 8 processed (✅ 8, ❌ 0)
  Total:          23 tasks in 1245 ms
```

### Mode verbose avec erreurs
```
Processing tasks...

=== Batch Results ===
  Unique tasks: 15 processed (✅ 12, ❌ 3)
  Recurring tasks: 8 processed (✅ 8, ❌ 0)
  Total:          23 tasks in 1245 ms

=== Failed Tasks ===
  Unique tasks:
    ❌ 550e8400-e29b-41d4-a716-446655440000: Task execution failed
    ❌ 550e8400-e29b-41d4-a716-446655440001: Validation failed
    ❌ 550e8400-e29b-41d4-a716-446655440002: Task expired
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Laravel non disponible | `RuntimeException` | `Laravel container is not available. Task processing requires Laravel.` |
| Options incompatibles | `ExitCode::INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| Limite invalide (≤ 0) | `ExitCode::INVALID_ARGUMENT` | `Limit must be a positive integer` |

## Performance

- **Temps d'exécution** : Variable selon le nombre de tâches
- **Mémoire** : Les collections sont limitées par l'option `--limit`
- **Affichage** : Les messages sont envoyés directement dans la console

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```bash
# 1. Exécution standard
./vendor/bin/directive process-tasks

# 2. Tâches uniques uniquement avec limite
./vendor/bin/directive process-tasks --unique-only --limit=5

# 3. Tâches récurrentes avec affichage détaillé
./vendor/bin/directive process-tasks --recurring-only --verbose

# 4. Toutes les tâches avec limite et détails
./vendor/bin/directive process-tasks --limit=20 --verbose

# 5. Utilisation d'un alias
./vendor/bin/directive task:process --limit=10
```

## Voir aussi

- `UniqueTaskService` - Service d'exécution des tâches uniques
- `RecurringTaskService` - Service d'exécution des tâches récurrentes
- `BatchResultRecord` - Structure des résultats de batch
- `DirectiveTestingService` - Service de test des directives
<!-- ==== ./docs/api-reference/processors/unique-task-processor.md ==== -->

# UniqueTaskProcessor - Référence Technique

## Description

Processeur de tâches uniques qui orchestre l'exécution d'un lot de tâches en une seule fois. Il récupère les tâches prêtes, les valide, les exécute et gère les expirations.

## Hiérarchie / Implémentations

```
UniqueTaskProcessorInterface
    └── UniqueTaskProcessor
```

## Rôle principal

Ce processeur est le cœur du traitement des tâches uniques. Il :

1. **Récupère** les tâches prêtes (`findReadyToRun`)
2. **Valide** chaque tâche avant exécution (`validator->canRun`)
3. **Orchestre** l'exécution via le `UniqueTaskRunner`
4. **Gère** les tâches expirées (grace period dépassée)
5. **Agrège** les résultats et les erreurs

## API

### `process(?int $limit = null): ProcessResultRecord`

Point d'entrée principal du processeur.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de tâches à exécuter (`null` = illimité) |

**Retourne :** `ProcessResultRecord` - Résultat du traitement (succès, échecs, finitions)

**Exemple :**
```php
$processor = new UniqueTaskProcessor($repository, $runner, $validator);
$result = $processor->process(10);
```

---

### `modelToRecord(UniqueTask $model): UniqueTaskRecord`

Convertit un modèle Eloquent en Record DTO.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$model` | `UniqueTask` | Modèle Eloquent à convertir |

**Retourne :** `UniqueTaskRecord` - DTO de la tâche

**Exemple :**
```php
$model = UniqueTask::find('550e8400-e29b-41d4-a716-446655440000');
$record = $processor->modelToRecord($model);
// $record est un UniqueTaskRecord immuable
```

## Cas d'utilisation

### Cas 1 : Traitement standard des tâches uniques

```php
$processor = app(UniqueTaskProcessor::class);
$result = $processor->process();

echo "Succès: {$result->success->value}\n";
echo "Échecs: {$result->failed->value}\n";
```

### Cas 2 : Traitement avec limite

```php
// Traiter uniquement les 5 premières tâches
$result = $processor->process(5);
```

### Cas 3 : Tâche invalidée par le validator

```php
// Une tâche avec attempts = max_attempts
// Le validator la rejette → la tâche est marquée FAILED
// L'erreur "Validation failed: Maximum attempts reached" est enregistrée
```

### Cas 4 : Tâche expirée

```php
// Une tâche avec scheduled_at = now - 48h, grace_period = 3600 (1h)
// Le processeur la détecte et la marque FAILED
// L'erreur "Task expired" est enregistrée
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskProcessor                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ÉTAPE 1 : Récupérer les tâches prêtes                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findReadyToRun(date('c'))                                  │   │
│  │  → Collection<UniqueTask> (modèles Eloquent)               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 2 : Appliquer la limite                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  if ($limit !== null) { $tasks = $tasks->take($limit); }   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 3 : Exécuter chaque tâche                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche :                                        │   │
│  │  ├─ modelToRecord($task) → UniqueTaskRecord                │   │
│  │  │                                                          │   │
│  │  ├─ validator->canRun($taskRecord) ?                       │   │
│  │  │  ├─ NON → moveToFailed() + error                       │   │
│  │  │  └─ OUI → runner->run($taskRecord)                     │   │
│  │  │                                                          │   │
│  │  └─ runner->run() → ExecutionResultRecord                  │   │
│  │     ├─ success → success++                                 │   │
│  │     └─ failure → failed++ + error                          │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 4 : Traiter les tâches expirées                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findExpired(date('c')) → Collection<UniqueTask>           │   │
│  │  Pour chaque tâche :                                        │   │
│  │  ├─ modelToRecord($task) → UniqueTaskRecord                │   │
│  │  ├─ validator->isExpired($taskRecord) ?                    │   │
│  │  └─ moveToFailed($taskRecord) + error                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  SORTIE : ProcessResultRecord                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  success: nombre de succès                                  │   │
│  │  failed: nombre d'échecs (validations + expirations)       │   │
│  │  finished: toujours 0 (tâches uniques)                     │   │
│  │  errors: collection des erreurs                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

### Cas de validation échouée

| Condition | Action | Erreur |
|-----------|--------|--------|
| Statut ≠ PENDING | `moveToFailed()` | `Validation failed: Task is not in PENDING state` |
| `attempts >= max_attempts` | `moveToFailed()` | `Validation failed: Maximum attempts reached` |
| Tâche expirée | `moveToFailed()` | `Validation failed: Task has expired` |
| `scheduled_at > now` | `moveToFailed()` | `Validation failed: Task is not ready to run` |

### Cas d'exécution échouée

| Situation | Action | Erreur |
|-----------|--------|--------|
| Exception dans le runner | `moveToFailed()` | Message de l'exception |
| Erreur d'instanciation | `moveToFailed()` | `Failed to instantiate task: ...` |

### Cas d'expiration

| Condition | Action | Erreur |
|-----------|--------|--------|
| `now > scheduled_at + grace_period` | `moveToFailed()` | `Task expired` |

## Validation avant exécution

```php
// Le validator vérifie 4 conditions
if (! $this->validator->canRun($taskRecord)) {
    // 1. Statut PENDING
    // 2. scheduled_at <= now
    // 3. attempts < max_attempts
    // 4. non expiré (grace_period)
    
    // Récupération des erreurs détaillées
    $errors = $this->validator->getValidationErrors($taskRecord);
    // Exemple: "Task is not in PENDING state, Maximum attempts reached"
}
```

## Performance

- **Complexité** : O(n) où n = nombre de tâches récupérées
- **Mémoire** : Les tâches sont chargées en mémoire via les collections
- **Base de données** : 2 requêtes (`findReadyToRun`, `findExpired`) + requêtes pour les mises à jour
- **Limite** : Permet de contrôler la charge

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Records\ProcessResultRecord;

// Récupérer le processeur
$processor = app(UniqueTaskProcessor::class);

// Exécuter avec limite de 10 tâches
$result = $processor->process(10);

// Afficher les résultats
echo "Traitement terminé.\n";
echo "✅ Succès: {$result->success->value}\n";
echo "❌ Échecs: {$result->failed->value}\n";

// Afficher les erreurs
foreach ($result->errors as $error) {
    echo "Erreur: {$error->identifier} - {$error->error}\n";
    if ($error->context) {
        echo "  Contexte: {$error->context}\n";
    }
}

// Vérifier le statut global
$hasErrors = $result->failed->value > 0;
echo $hasErrors ? "⚠️ Des erreurs sont survenues" : "✅ Tout s'est bien passé";
```

## Voir aussi

- `UniqueTaskRunner` - Exécuteur de tâches uniques
- `UniqueTaskValidator` - Validation des tâches
- `UniqueTaskRepository` - Accès aux données
- `ProcessResultRecord` - Structure de résultat
- `RecurringTaskProcessor` - Processeur pour les tâches récurrentes
<!-- ==== ./docs/api-reference/processors/recurring-task-processor.md ==== -->

# RecurringTaskProcessor - Référence Technique

## Description

Processeur de tâches récurrentes qui orchestre l'exécution d'un lot de tâches. Il gère le cycle de vie complet des tâches récurrentes : démarrage, exécution périodique et terminaison.

## Hiérarchie / Implémentations

```
RecurringTaskProcessorInterface
    └── RecurringTaskProcessor
```

## Rôle principal

Ce processeur est le cœur du traitement des tâches récurrentes. Il :

1. **Récupère** les tâches en attente (`WAITING`) et actives (`PLAYING`)
2. **Détermine** les actions à effectuer (démarrer, exécuter, terminer)
3. **Orchestre** l'exécution via le `RecurringTaskRunner`
4. **Gère** les transitions d'état (WAITING → PLAYING → FINISHED)
5. **Calcule** les prochaines exécutions selon les intervalles

## API

### `process(?int $limit = null): ProcessResultRecord`

Point d'entrée principal du processeur.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de tâches à exécuter (`null` = illimité) |

**Retourne :** `ProcessResultRecord` - Résultat du traitement (succès, échecs, finitions)

**Exemple :**
```php
$processor = new RecurringTaskProcessor($repository, $runner, $validator);
$result = $processor->process(10);
```

---

### `shouldRunAgain(RecurringTaskRecord $record): bool`

Vérifie si une tâche en `PLAYING` doit être exécutée à nouveau selon son intervalle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être ré-exécutée

**Conditions :**
- Statut = `PLAYING`
- Non expirée (`end_at` non dépassé)
- Dernière exécution + intervalle ≤ maintenant

**Exemple :**
```php
$lastRun = new Iso8601DateTimeVO('2026-06-22T10:00:00+00:00');
$record = new RecurringTaskRecord(
    // ...
    last_run_at: $lastRun,
    interval_seconds: new CounterVO(3600),
    status: RecurringTaskStatus::PLAYING,
);

$shouldRun = $processor->shouldRunAgain($record);
// true si maintenant >= 11:00:00
```

---

### `modelToRecord(RecurringTask $model): RecurringTaskRecord`

Convertit un modèle Eloquent en Record DTO.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$model` | `RecurringTask` | Modèle Eloquent à convertir |

**Retourne :** `RecurringTaskRecord` - DTO de la tâche

**Exemple :**
```php
$model = RecurringTask::find(1);
$record = $processor->modelToRecord($model);
// $record est un RecurringTaskRecord immuable
```

## Cas d'utilisation

### Cas 1 : Traitement standard des tâches récurrentes

```php
$processor = app(RecurringTaskProcessor::class);
$result = $processor->process();

echo "Succès: {$result->success->value}\n";
echo "Échecs: {$result->failed->value}\n";
echo "Terminées: {$result->finished->value}\n";
```

### Cas 2 : Traitement avec limite

```php
// Traiter uniquement les 5 premières tâches
$result = $processor->process(5);
```

### Cas 3 : Tâche en PLAYING avec intervalle

```php
// Une tâche avec last_run_at = 10:00, interval = 3600 (1h)
// À 10:30 → ne sera pas exécutée (intervalle non atteint)
// À 11:00 → sera exécutée (intervalle atteint)
```

### Cas 4 : Tâche en WAITING qui expire avant de démarrer

```php
// Tâche avec start_at = 10:00, end_at = 09:00
// Le processeur la termine directement sans l'exécuter
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskProcessor                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ÉTAPE 1 : Récupérer les tâches                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findWaiting()  → Tâches à démarrer                        │   │
│  │  findPlaying()  → Tâches déjà actives                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 2 : Analyser les WAITING                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche WAITING :                                │   │
│  │  ├─ end_at dépassé ? → tasksToFinish                       │   │
│  │  └─ start_at atteint ? → tasksToPlay                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 3 : Analyser les PLAYING                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche PLAYING :                                │   │
│  │  ├─ end_at dépassé ? → tasksToFinish                       │   │
│  │  └─ intervalle dépassé ? → tasksToPlay                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 4 : Terminer les tâches (moveToFinished)                   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 5 : Appliquer la limite                                    │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 6 : Exécuter les tâches                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche dans tasksToPlay :                       │   │
│  │  ├─ WAITING → moveToPlaying()                              │   │
│  │  ├─ Récupérer la tâche mise à jour                         │   │
│  │  ├─ Exécuter via le runner                                 │   │
│  │  └─ Vérifier si doit être terminée après exécution         │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  SORTIE : ProcessResultRecord                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  success: nombre de succès                                  │   │
│  │  failed: nombre d'échecs                                    │   │
│  │  finished: nombre terminées                                 │   │
│  │  errors: collection des erreurs                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Tâche non trouvée après move | `RuntimeException` | `Task not found: {alias}` |
| Runner échoue | ❌ Non bloquant | L'erreur est ajoutée à `$errors` |
| Erreur de validation | ❌ Non bloquant | L'erreur est ajoutée à `$errors` |

## Transitions d'état

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche récurrente             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  WAITING ──────────────────────────────────────────────────────────►│
│     │                                                               │
│     │  start_at <= now                                              │
│     ▼                                                               │
│  PLAYING ──────────────────────────────────────────────────────────►│
│     │                                                               │
│     │  Chaque cycle :                                               │
│     │  1. shouldRunAgain() vérifie l'intervalle                    │
│     │  2. Exécution via le runner                                  │
│     │  3. updateAfterRun() met à jour last_run_at                  │
│     │                                                               │
│     │  end_at <= now                                                │
│     ▼                                                               │
│  FINISHED ◄─────────────────────────────────────────────────────────│
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Performance

- **Complexité** : O(n) où n = nombre de tâches récupérées
- **Mémoire** : Les tâches sont chargées en mémoire via les collections
- **Base de données** : 2 requêtes (`findWaiting`, `findPlaying`) + requêtes pour les mises à jour
- **Limite** : Permet de contrôler la charge

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Processors\RecurringTaskProcessor;
use AndyDefer\Task\Records\ProcessResultRecord;

// Récupérer le processeur
$processor = app(RecurringTaskProcessor::class);

// Exécuter avec limite de 10 tâches
$result = $processor->process(10);

// Afficher les résultats
echo "Traitement terminé.\n";
echo "✅ Succès: {$result->success->value}\n";
echo "❌ Échecs: {$result->failed->value}\n";
echo "🏁 Terminées: {$result->finished->value}\n";

// Afficher les erreurs
foreach ($result->errors as $error) {
    echo "Erreur: {$error->identifier} - {$error->error}\n";
}

// Vérifier le statut global
$hasErrors = $result->failed->value > 0 || $result->finished->value > 0;
echo $hasErrors ? "⚠️ Des erreurs sont survenues" : "✅ Tout s'est bien passé";
```

## Voir aussi

- `RecurringTaskRunner` - Exécuteur de tâches récurrentes
- `RecurringTaskValidator` - Validation des tâches
- `RecurringTaskRepository` - Accès aux données
- `ProcessResultRecord` - Structure de résultat
- `UniqueTaskProcessor` - Processeur pour les tâches uniques
<!-- ==== ./docs/api-reference/tasks/recurring-task.md ==== -->

# Tâches Récurrentes - Référence Technique

## Description

Les tâches récurrentes sont des tâches qui s'exécutent périodiquement selon un intervalle défini. Elles restent actives (`PLAYING`) entre les exécutions et se terminent automatiquement à une date de fin (`end_at`).

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Architecture d'une tâche récurrente             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  AbstractRecurringTask                      │   │
│  │  - Classe abstraite de base                                 │   │
│  │  - Définit le cycle de vie (before, process, after)        │   │
│  │  - Gère la journalisation automatique                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              ▲                                      │
│                              │                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  TestRecurringTask (Fixture)                │   │
│  │  - Implémentation concrète pour les tests                   │   │
│  │  - Définit la configuration via getConfig()                │   │
│  │  - Contient la logique métier dans process()               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  RecurringTaskContext                       │   │
│  │  - Contexte d'exécution de la tâche                         │   │
│  │  - Contient : alias, interval, start_at, end_at, etc.      │   │
│  │  - Injecté dans la tâche via le constructeur                │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cycle de vie

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche récurrente              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Création                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = WAITING                                           │   │
│  │  start_at = date de début                                   │   │
│  │  interval_seconds = période d'exécution                     │   │
│  │  end_at = date de fin (optionnelle)                        │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Démarrage (start_at atteint)                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = PLAYING                                           │   │
│  │  La tâche devient active                                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Exécution périodique                                             │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Chaque cycle :                                             │   │
│  │  1. Vérifier si intervalle atteint                          │   │
│  │  2. Exécuter la tâche                                       │   │
│  │  3. Mettre à jour last_run_at                               │   │
│  │  4. Vérifier si end_at atteint                              │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Fin (end_at atteint)                                             │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = FINISHED                                          │   │
│  │  finished_at = date de fin                                  │   │
│  │  La tâche est terminée                                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Modèle Eloquent

### `RecurringTask`

```php
final class RecurringTask extends Model
{
    use SoftDeletes;

    protected $table = 'recurring_tasks';

    protected $fillable = [
        'alias',          // Identifiant unique
        'fqcn',           // Nom complet de la classe
        'payload',        // Données de la tâche
        'interval_seconds', // Intervalle en secondes
        'start_at',       // Date de début
        'end_at',         // Date de fin
        'status',         // WAITING, PLAYING, PAUSED, FINISHED
        'last_run_at',    // Dernière exécution
        'finished_at',    // Date de fin effective
    ];
}
```

### Accesseurs

| Méthode | Retour | Description |
|---------|--------|-------------|
| `getId(): int` | `int` | ID auto-incrémenté |
| `getAlias(): TaskSignatureVO` | `TaskSignatureVO` | Alias de la tâche |
| `getIntervalSeconds(): CounterVO` | `CounterVO` | Intervalle en secondes |
| `getStartAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Date de début |
| `getLastRunAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Dernière exécution |
| `getFinishedAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Date de fin |
| `getStatus(): RecurringTaskStatus` | `RecurringTaskStatus` | Statut actuel |
| `getPayload(): StrictDataObject` | `StrictDataObject` | Données de la tâche |
| `getFqcn(): string` | `string` | Nom de la classe |

## Classe Abstraite

### `AbstractRecurringTask`

```php
abstract class AbstractRecurringTask implements RecurringTaskInterface
{
    protected RecurringTaskContext $context;
    protected LoggerInterface $logger;
    protected HydrationService $hydration;

    // Méthodes abstraites à implémenter
    abstract public function getConfig(): RecurringTaskConfigInterface;
    abstract protected function process(): void;

    // Méthodes optionnelles à surcharger
    protected function before(): void {}
    protected function after(bool $success, ?string $error = null): void {}

    // Méthode finale d'exécution
    final public function execute(StrictDataObject $payload): void;

    // Méthodes de journalisation
    public function info(string $message): void;
    public function error(string $message): void;
}
```

### Cycle d'exécution

```
execute()
    ├── setPayload($payload)
    ├── log('task_started')
    ├── before()
    ├── try
    │   ├── process()          ← Implémentée par la tâche concrète
    │   ├── after(true)
    │   └── log('task_completed')
    ├── catch
    │   ├── after(false, $error)
    │   ├── log('task_failed')
    │   └── throw $e
    └── end
```

## Contexte

### `RecurringTaskContext`

```php
class RecurringTaskContext implements RecurringTaskContextInterface
{
    // Propriétés
    private StrictDataObject $payload;
    private TaskSignatureVO $alias;
    private CounterVO $intervalSeconds;
    private ?Iso8601DateTimeVO $startAt;
    private ?Iso8601DateTimeVO $endAt;
    private ?Iso8601DateTimeVO $lastRunAt;
    private ?Iso8601DateTimeVO $nextRunAt;
    private ?Application $app;

    // Getters / Setters
    public function setPayload(StrictDataObject $payload): void;
    public function getPayload(): StrictDataObject;

    public function setAlias(TaskSignatureVO $alias): void;
    public function getAlias(): TaskSignatureVO;

    public function setIntervalSeconds(CounterVO $intervalSeconds): void;
    public function getIntervalSeconds(): CounterVO;

    public function setStartAt(?Iso8601DateTimeVO $startAt): void;
    public function getStartAt(): ?Iso8601DateTimeVO;

    public function setEndAt(?Iso8601DateTimeVO $endAt): void;
    public function getEndAt(): ?Iso8601DateTimeVO;

    public function setLastRunAt(?Iso8601DateTimeVO $lastRunAt): void;
    public function getLastRunAt(): ?Iso8601DateTimeVO;

    public function setNextRunAt(?Iso8601DateTimeVO $nextRunAt): void;
    public function getNextRunAt(): ?Iso8601DateTimeVO;

    public function setLaravelApp(Application $app): void;
    public function getLaravelApp(): ?Application;
}
```

## Configuration

### `RecurringTaskConfig`

```php
class RecurringTaskConfig implements RecurringTaskConfigInterface
{
    public function __construct(
        public readonly TaskSignatureVO $alias,
        public readonly string $description,
        public readonly CounterVO $interval_seconds,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly CounterVO $max_attempts = new CounterVO(3),
    ) {}
}
```

## Statuts

### `RecurringTaskStatus`

| Statut | Valeur | Description |
|--------|--------|-------------|
| `WAITING` | `'waiting'` | En attente de démarrage |
| `PLAYING` | `'playing'` | Active, peut être exécutée |
| `PAUSED` | `'paused'` | Mise en pause |
| `FINISHED` | `'finished'` | Terminée |

```php
enum RecurringTaskStatus: string
{
    case WAITING = 'waiting';
    case PLAYING = 'playing';
    case PAUSED = 'paused';
    case FINISHED = 'finished';

    public function isWaiting(): bool { /* ... */ }
    public function isPlaying(): bool { /* ... */ }
    public function isPaused(): bool { /* ... */ }
    public function isFinished(): bool { /* ... */ }
}
```

## Cas d'utilisation

### Cas 1 : Créer une tâche récurrente

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$config = $task->getConfig();
echo $config->getAlias()->value; // 'test-recurring'
echo $config->getIntervalSeconds()->value; // 3600
```

### Cas 2 : Exécuter une tâche récurrente

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$payload = StrictDataObject::from(['data' => 'value']);
$task->execute($payload);

$log = $task->getExecutionLog();
// [['time' => '...', 'payload' => ['data' => 'value']]]
```

### Cas 3 : Journalisation

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$task->info('Processing started');
$task->error('An error occurred');

// Les messages sont automatiquement journalisés
```

### Cas 4 : Tâche avec échec

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$task->setFailOn('Planned failure');
$payload = StrictDataObject::from([]);

try {
    $task->execute($payload);
} catch (RuntimeException $e) {
    echo $e->getMessage(); // 'Planned failure'
    // Une entrée de log 'task_failed' a été créée
}
```

## Journalisation

Les tâches récurrentes produisent automatiquement les logs suivants :

| Événement | Type | Description |
|-----------|------|-------------|
| `task_started` | `recurring_task` | Début de l'exécution |
| `task_completed` | `recurring_task` | Exécution réussie |
| `task_failed` | `recurring_task` | Échec de l'exécution |
| `info` | `recurring_task_output` | Message d'information |
| `error` | `recurring_task_output` | Message d'erreur |

## Bonnes pratiques

1. **Configurer l'intervalle** : Utiliser `CounterVO` pour garantir l'immutabilité
2. **Gérer les dates** : Utiliser `Iso8601DateTimeVO` pour les dates
3. **Journaliser** : Utiliser `$this->info()` et `$this->error()`
4. **Surcharger `before()` et `after()`** : Pour les actions pré/post-exécution
5. **Utiliser `StrictDataObject`** : Pour le payload, garantit l'intégrité des données

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Configs\RecurringTaskConfig;

class BackupTask extends AbstractRecurringTask
{
    public function getConfig(): RecurringTaskConfig
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('database-backup'),
            description: 'Backup the database',
            interval_seconds: new CounterVO(86400),
            start_at: new Iso8601DateTimeVO('2026-01-01T00:00:00+00:00'),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $config = $this->context->getPayload()->toArray();
        $database = $config['database'] ?? 'default';

        $this->info("Starting backup for database: {$database}");

        // Logique de backup
        $success = $this->performBackup($database);

        if (!$success) {
            throw new \RuntimeException('Backup failed');
        }

        $this->info("Backup completed successfully");
    }

    private function performBackup(string $database): bool
    {
        // Implémentation du backup
        return true;
    }
}
```

## Voir aussi

- `AbstractRecurringTask` - Classe abstraite de base
- `RecurringTaskContext` - Contexte d'exécution
- `RecurringTaskConfig` - Configuration des tâches
- `RecurringTaskStatus` - Énumération des statuts
- `RecurringTaskRepository` - Repository des tâches récurrentes
- `RecurringTaskService` - Service de gestion des tâches récurrentes
<!-- ==== ./docs/api-reference/tasks/unique-task.md ==== -->

# Tâches Uniques - Référence Technique

## Description

Les tâches uniques sont des tâches qui s'exécutent une seule fois à une date planifiée (`scheduled_at`). Elles disposent d'une période de grâce (`grace_period`) et d'un système de tentatives pour gérer les échecs.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Architecture d'une tâche unique                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  AbstractUniqueTask                         │   │
│  │  - Classe abstraite de base                                 │   │
│  │  - Définit le cycle de vie (before, process, after)        │   │
│  │  - Gère la journalisation automatique                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              ▲                                      │
│                              │                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  TestUniqueTask (Fixture)                   │   │
│  │  - Implémentation concrète pour les tests                   │   │
│  │  - Définit la configuration via getConfig()                │   │
│  │  - Contient la logique métier dans process()               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  UniqueTaskContext                          │   │
│  │  - Contexte d'exécution de la tâche                         │   │
│  │  - Contient : taskId, alias, scheduled_at                  │   │
│  │  - Injecté dans la tâche via le constructeur                │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cycle de vie

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche unique                  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Création                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = PENDING                                           │   │
│  │  id = UUID généré automatiquement                           │   │
│  │  scheduled_at = date planifiée                              │   │
│  │  attempts = 0                                               │   │
│  │  max_attempts = 3 (par défaut)                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Attente (scheduled_at > now)                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  La tâche attend son heure d'exécution                      │   │
│  │  Statut reste PENDING                                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Exécution (scheduled_at <= now)                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  1. Vérifier les conditions (statut, tentatives, expiration)│   │
│  │  2. Exécuter la tâche                                       │   │
│  │  3. Succès → COMPLETED                                      │   │
│  │  4. Échec → attempts++                                      │   │
│  │  5. attempts >= max_attempts → FAILED                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Fin                                                               │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Statut terminal : COMPLETED ou FAILED                      │   │
│  │  finished_at = date de fin                                  │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  Période de grâce (Grace Period)                                  │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  La tâche peut être exécutée après scheduled_at             │   │
│  │  Délai = grace_period_seconds (défaut: 86400 = 24h)        │   │
│  │  Expiration si now > scheduled_at + grace_period            │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Modèle Eloquent

### `UniqueTask`

```php
final class UniqueTask extends Model
{
    use SoftDeletes;

    // Configuration UUID
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',                    // UUID
        'alias',                 // Identifiant unique
        'fqcn',                  // Nom complet de la classe
        'payload',               // Données de la tâche
        'scheduled_at',          // Date planifiée
        'grace_period_seconds',  // Période de grâce en secondes
        'status',                // PENDING, COMPLETED, FAILED
        'attempts',              // Nombre de tentatives
        'max_attempts',          // Nombre maximum de tentatives
        'finished_at',           // Date de fin effective
    ];
}
```

### Accesseurs

| Méthode | Retour | Description |
|---------|--------|-------------|
| `getId(): TaskIdVO` | `TaskIdVO` | UUID de la tâche |
| `getAlias(): TaskSignatureVO` | `TaskSignatureVO` | Alias de la tâche |
| `getScheduledAt(): Iso8601DateTimeVO` | `Iso8601DateTimeVO` | Date planifiée |
| `getFinishedAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Date de fin |
| `getStatus(): UniqueTaskStatus` | `UniqueTaskStatus` | Statut actuel |
| `getAttempts(): CounterVO` | `CounterVO` | Nombre de tentatives |
| `getMaxAttempts(): CounterVO` | `CounterVO` | Nombre maximum de tentatives |
| `getGracePeriodSeconds(): int` | `int` | Période de grâce |
| `getPayload(): StrictDataObject` | `StrictDataObject` | Données de la tâche |
| `getFqcn(): string` | `string` | Nom de la classe |

## Classe Abstraite

### `AbstractUniqueTask`

```php
abstract class AbstractUniqueTask implements UniqueTaskInterface
{
    protected UniqueTaskContext $context;
    protected LoggerInterface $logger;
    protected HydrationService $hydration;

    // Méthodes abstraites à implémenter
    abstract public function getConfig(): UniqueTaskConfigInterface;
    abstract protected function process(): void;

    // Méthodes optionnelles à surcharger
    protected function before(): void {}
    protected function after(bool $success, ?string $error = null): void {}

    // Méthode finale d'exécution
    final public function execute(StrictDataObject $payload): void;

    // Méthodes de journalisation
    public function info(string $message): void;
    public function error(string $message): void;
}
```

### Cycle d'exécution

```
execute()
    ├── setPayload($payload)
    ├── log('task_started')
    ├── before()
    ├── try
    │   ├── process()          ← Implémentée par la tâche concrète
    │   ├── after(true)
    │   └── log('task_completed')
    ├── catch
    │   ├── after(false, $error)
    │   ├── log('task_failed')
    │   └── throw $e
    └── end
```

## Contexte

### `UniqueTaskContext`

```php
class UniqueTaskContext implements UniqueTaskContextInterface
{
    // Propriétés
    private StrictDataObject $payload;
    private TaskIdVO $taskId;
    private TaskSignatureVO $alias;
    private Iso8601DateTimeVO $scheduledAt;
    private ?Application $app;

    // Getters / Setters
    public function setPayload(StrictDataObject $payload): void;
    public function getPayload(): StrictDataObject;

    public function setTaskId(TaskIdVO $taskId): void;
    public function getTaskId(): TaskIdVO;

    public function setAlias(TaskSignatureVO $alias): void;
    public function getAlias(): TaskSignatureVO;

    public function setScheduledAt(Iso8601DateTimeVO $scheduledAt): void;
    public function getScheduledAt(): Iso8601DateTimeVO;

    public function setLaravelApp(Application $app): void;
    public function getLaravelApp(): ?Application;
}
```

## Configuration

### `UniqueTaskConfig`

```php
class UniqueTaskConfig implements UniqueTaskConfigInterface
{
    public function __construct(
        public readonly TaskSignatureVO $alias,
        public readonly string $description,
        public readonly Iso8601DateTimeVO $scheduled_at,
        public readonly CounterVO $max_attempts = new CounterVO(3),
    ) {}
}
```

## Statuts

### `UniqueTaskStatus`

| Statut | Valeur | Description |
|--------|--------|-------------|
| `PENDING` | `'pending'` | En attente d'exécution |
| `COMPLETED` | `'completed'` | Exécutée avec succès |
| `FAILED` | `'failed'` | Échec (tentatives épuisées ou expirée) |

```php
enum UniqueTaskStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isPending(): bool { /* ... */ }
    public function isCompleted(): bool { /* ... */ }
    public function isFailed(): bool { /* ... */ }
    public function isTerminal(): bool { /* ... */ }
}
```

## Période de grâce (Grace Period)

La période de grâce permet d'exécuter une tâche après sa date planifiée sans qu'elle soit considérée comme expirée.

```
scheduled_at ────────────────────────────────────────────────► temps
     │                                                            │
     │  Période de grâce (grace_period_seconds)                  │
     │  ┌──────────────────────────────────────────┐              │
     │  │   La tâche peut être exécutée            │              │
     │  │   même si scheduled_at est dépassé       │              │
     │  └──────────────────────────────────────────┘              │
     │                                                            │
     └────────────────────────────────────────────────────────────┘
                          ▲                                ▲
                          │                                │
                    Exécution possible          Expiration
                    (dans la période)           (hors période)
```

## Cas d'utilisation

### Cas 1 : Créer une tâche unique

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$config = $task->getConfig();
echo $config->getAlias()->value; // 'test-unique'
echo $config->getScheduledAt()->value; // Date planifiée
echo $config->getMaxAttempts()->value; // 3
```

### Cas 2 : Exécuter une tâche unique

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$payload = StrictDataObject::from(['email' => 'john@example.com']);
$task->execute($payload);

$log = $task->getExecutionLog();
// [['time' => '...', 'payload' => ['email' => 'john@example.com']]]
```

### Cas 3 : Journalisation

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$task->info('Sending welcome email');
$task->error('Failed to send email');

// Les messages sont automatiquement journalisés
```

### Cas 4 : Tâche avec échec

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$task->setFailOn('Email sending failed');
$payload = StrictDataObject::from(['email' => 'john@example.com']);

try {
    $task->execute($payload);
} catch (RuntimeException $e) {
    echo $e->getMessage(); // 'Email sending failed'
    // Une entrée de log 'task_failed' a été créée
}
```

### Cas 5 : Gestion des tentatives

```php
// Le système incrémente automatiquement les tentatives
// après chaque échec
$record = new UniqueTaskRecord(
    // ...
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// Première exécution → échec → attempts = 1
// Deuxième exécution → échec → attempts = 2
// Troisième exécution → échec → attempts = 3 → FAILED
```

## Journalisation

Les tâches uniques produisent automatiquement les logs suivants :

| Événement | Type | Description |
|-----------|------|-------------|
| `task_started` | `unique_task` | Début de l'exécution |
| `task_completed` | `unique_task` | Exécution réussie |
| `task_failed` | `unique_task` | Échec de l'exécution |
| `info` | `unique_task_output` | Message d'information |
| `error` | `unique_task_output` | Message d'erreur |

## Bonnes pratiques

1. **Configurer la date** : Utiliser `Iso8601DateTimeVO` pour `scheduled_at`
2. **Définir les tentatives** : Ajuster `max_attempts` selon la criticité
3. **Période de grâce** : Ajuster `grace_period_seconds` selon les besoins
4. **Journaliser** : Utiliser `$this->info()` et `$this->error()`
5. **Surcharger `before()` et `after()`** : Pour les actions pré/post-exécution
6. **UUID** : L'ID est un UUID généré automatiquement

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Configs\UniqueTaskConfig;

class SendWelcomeEmailTask extends AbstractUniqueTask
{
    public function getConfig(): UniqueTaskConfig
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('welcome-email'),
            description: 'Send welcome email to new user',
            scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $payload = $this->context->getPayload()->toArray();
        $email = $payload['email'] ?? throw new \RuntimeException('Email not provided');

        $this->info("Sending welcome email to {$email}");

        $success = $this->sendEmail($email);

        if (!$success) {
            throw new \RuntimeException('Failed to send email');
        }

        $this->info("Welcome email sent to {$email}");
    }

    private function sendEmail(string $email): bool
    {
        // Implémentation de l'envoi d'email
        return true;
    }
}
```

## Voir aussi

- `AbstractUniqueTask` - Classe abstraite de base
- `UniqueTaskContext` - Contexte d'exécution
- `UniqueTaskConfig` - Configuration des tâches
- `UniqueTaskStatus` - Énumération des statuts
- `UniqueTaskRepository` - Repository des tâches uniques
- `UniqueTaskService` - Service de gestion des tâches uniques
<!-- ==== ./docs/api-reference/validators/unique-task-validator.md ==== -->

# UniqueTaskValidator - Référence Technique

## Description

Validateur des tâches uniques. Fournit des méthodes pour vérifier si une tâche peut être exécutée, si elle est prête, expirée, ou si elle a atteint le nombre maximum de tentatives.

## Hiérarchie / Implémentations

```
UniqueTaskValidatorInterface
    └── UniqueTaskValidator
```

## Rôle principal

Ce validateur est le gardien de l'intégrité des tâches uniques. Il :

1. **Valide** l'intégrité de la classe de tâche
2. **Vérifie** les conditions d'exécution (`canRun`)
3. **Détermine** si une tâche est prête à être exécutée (`isReadyToRun`)
4. **Détecte** les tâches expirées (`isExpired`)
5. **Vérifie** les tentatives (`hasReachedMaxAttempts`)
6. **Rapporte** les erreurs de validation (`getValidationErrors`)

## API

### `canRun(UniqueTaskRecord $record): bool`

Vérifie si une tâche peut être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la tâche peut être exécutée

**Conditions :**
- Classe valide (existe et étend `AbstractUniqueTask`)
- Statut = `PENDING`
- `scheduled_at <= now`
- `attempts < max_attempts`
- Non expirée (`now <= scheduled_at + grace_period`)

**Exemple :**
```php
$validator = new UniqueTaskValidator();
if ($validator->canRun($record)) {
    // Exécuter la tâche
}
```

---

### `isReadyToRun(UniqueTaskRecord $record): bool`

Vérifie si une tâche est prête à être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est prête

**Conditions :**
- Classe valide
- Statut = `PENDING`
- `scheduled_at <= now`

**Exemple :**
```php
if ($validator->isReadyToRun($record)) {
    $runner->run($record);
}
```

---

### `isExpired(UniqueTaskRecord $record): bool`

Vérifie si une tâche est expirée (période de grâce dépassée).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est expirée

**Calcul :** `now > scheduled_at + grace_period_seconds`

**Exemple :**
```php
if ($validator->isExpired($record)) {
    $repository->moveToFailed($record);
}
```

---

### `hasReachedMaxAttempts(UniqueTaskRecord $record): bool`

Vérifie si la tâche a atteint le nombre maximum de tentatives.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si `attempts >= max_attempts`

**Exemple :**
```php
if ($validator->hasReachedMaxAttempts($record)) {
    $repository->moveToFailed($record);
}
```

---

### `getValidationErrors(UniqueTaskRecord $record): StringTypedCollection`

Retourne toutes les erreurs de validation.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à valider |

**Retourne :** `StringTypedCollection` - Collection des messages d'erreur

**Erreurs possibles :**
- Classe invalide
- Statut ≠ PENDING
- `attempts >= max_attempts`
- Tâche expirée
- `scheduled_at > now`

**Exemple :**
```php
$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    echo "Erreurs: " . $errors->join(', ');
}
```

---

### `isValidTaskClass(UniqueTaskRecord $record): bool` (privée)

Vérifie que la classe de la tâche existe et étend `AbstractUniqueTask`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la classe est valide

**Conditions :**
- `class_exists($record->fqcn)`
- `is_subclass_of($record->fqcn, AbstractUniqueTask::class)`

## Cas d'utilisation

### Cas 1 : Validation avant exécution

```php
$validator = new UniqueTaskValidator();

if (!$validator->canRun($record)) {
    $errors = $validator->getValidationErrors($record);
    throw new RuntimeException('Task cannot run: ' . $errors->join(', '));
}

// Exécuter la tâche
$runner->run($record);
```

### Cas 2 : Vérification de l'expiration

```php
$validator = new UniqueTaskValidator();

if ($validator->isExpired($record)) {
    $repository->moveToFailed($record);
    echo "Tâche expirée (scheduled_at + grace_period dépassé)";
}
```

### Cas 3 : Gestion des tentatives

```php
$validator = new UniqueTaskValidator();

if ($validator->hasReachedMaxAttempts($record)) {
    // Plus de tentatives disponibles
    $repository->moveToFailed($record);
} elseif ($validator->isReadyToRun($record)) {
    // Exécuter la tâche
    $runner->run($record);
}
```

### Cas 4 : Détection des erreurs de validation

```php
$validator = new UniqueTaskValidator();

$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    foreach ($errors as $error) {
        echo "❌ $error\n";
    }
} else {
    echo "✅ Tâche valide\n";
}
```

## Flux de validation

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskValidator                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  canRun()                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PENDING                            │   │
│  │  ✅ $record->scheduled_at <= now                           │   │
│  │  ✅ $record->attempts < $record->max_attempts              │   │
│  │  ✅ !$this->isExpired($record)                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isReadyToRun()                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PENDING                            │   │
│  │  ✅ $record->scheduled_at <= now                           │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isExpired()                                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ now > scheduled_at + grace_period_seconds              │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  hasReachedMaxAttempts()                                           │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ $record->attempts >= $record->max_attempts             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Messages d'erreur

| Situation | Message |
|-----------|---------|
| Classe invalide | `Invalid task class: {fqcn} does not exist or does not extend AbstractUniqueTask` |
| Statut ≠ PENDING | `Task is not in PENDING state` |
| Max attempts atteint | `Maximum attempts reached` |
| Tâche expirée | `Task has expired` |
| scheduled_at > now | `Task is not ready to run (scheduled_at in the future)` |

## Performance

- **Complexité** : O(1) - toutes les opérations sont constantes
- **Mémoire** : Aucune allocation mémoire significative
- **Validation** : Utilise `class_exists` et `is_subclass_of` (rapides)
- **Dates** : Utilise `strtotime` pour les comparaisons

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$validator = new UniqueTaskValidator();

// 1. Tâche valide en PENDING
$validRecord = new UniqueTaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    alias: new TaskSignatureVO('test'),
    fqcn: TestUniqueTask::class,
    payload: StrictDataObject::from([]),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

echo "canRun: " . ($validator->canRun($validRecord) ? '✅' : '❌') . "\n";
echo "isReadyToRun: " . ($validator->isReadyToRun($validRecord) ? '✅' : '❌') . "\n";
echo "isExpired: " . ($validator->isExpired($validRecord) ? '✅' : '❌') . "\n";

// 2. Tâche invalide (classe inexistante)
$invalidRecord = new UniqueTaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440001'),
    alias: new TaskSignatureVO('test'),
    fqcn: 'NonExistentClass',
    payload: StrictDataObject::from([]),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// Récupérer les erreurs
$errors = $validator->getValidationErrors($invalidRecord);
echo "Erreurs: " . $errors->join(', ') . "\n";
// Output: Invalid task class: NonExistentClass does not exist or does not extend AbstractUniqueTask

// 3. Tâche avec max attempts atteint
$maxAttemptsRecord = new UniqueTaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440002'),
    alias: new TaskSignatureVO('test'),
    fqcn: TestUniqueTask::class,
    payload: StrictDataObject::from([]),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(3),
    max_attempts: new CounterVO(3),
);

echo "hasReachedMaxAttempts: " . ($validator->hasReachedMaxAttempts($maxAttemptsRecord) ? '✅' : '❌') . "\n";
echo "canRun: " . ($validator->canRun($maxAttemptsRecord) ? '✅' : '❌') . "\n";
```

## Voir aussi

- `UniqueTaskValidatorInterface` - Interface du validateur
- `RecurringTaskValidator` - Validateur des tâches récurrentes
- `UniqueTaskRecord` - DTO des tâches uniques
- `UniqueTaskStatus` - Énumération des statuts
<!-- ==== ./docs/api-reference/validators/recurring-task-validator.md ==== -->

# RecurringTaskValidator - Référence Technique

## Description

Validateur des tâches récurrentes. Fournit des méthodes pour vérifier si une tâche peut être exécutée, si elle est prête, expirée, ou si elle doit être ré-exécutée selon son intervalle.

## Hiérarchie / Implémentations

```
RecurringTaskValidatorInterface
    └── RecurringTaskValidator
```

## Rôle principal

Ce validateur est le gardien de l'intégrité des tâches récurrentes. Il :

1. **Valide** l'intégrité de la classe de tâche
2. **Vérifie** les conditions d'exécution (`canRun`)
3. **Détermine** si une tâche est prête à démarrer (`isReadyToRun`)
4. **Détecte** les tâches expirées (`isExpired`, `shouldMoveToFinished`)
5. **Calcule** si une tâche doit être ré-exécutée (`shouldRunAgain`)
6. **Rapporte** les erreurs de validation (`getValidationErrors`)

## API

### `canRun(RecurringTaskRecord $record): bool`

Vérifie si une tâche peut être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la tâche peut être exécutée

**Conditions :**
- Classe valide (existe et étend `AbstractRecurringTask`)
- Statut = `PLAYING`
- `end_at` non dépassé

**Exemple :**
```php
$validator = new RecurringTaskValidator();
if ($validator->canRun($record)) {
    // Exécuter la tâche
}
```

---

### `isReadyToRun(RecurringTaskRecord $record): bool`

Vérifie si une tâche en `WAITING` est prête à passer en `PLAYING`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est prête

**Conditions :**
- Classe valide
- Statut = `WAITING`
- `start_at` non null
- `start_at <= now`

**Exemple :**
```php
if ($validator->isReadyToRun($record)) {
    $repository->moveToPlaying($record);
}
```

---

### `isExpired(RecurringTaskRecord $record): bool`

Vérifie si une tâche est expirée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est expirée

**Conditions :**
- `end_at` non null
- `end_at < now`

**Exemple :**
```php
if ($validator->isExpired($record)) {
    $repository->moveToFinished($record);
}
```

---

### `shouldMoveToFinished(RecurringTaskRecord $record): bool`

Vérifie si une tâche doit être terminée (alias de `isExpired`).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être terminée

---

### `shouldRunAgain(RecurringTaskRecord $record): bool`

Vérifie si une tâche en `PLAYING` doit être exécutée à nouveau selon son intervalle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être ré-exécutée

**Conditions :**
- Classe valide
- Statut = `PLAYING`
- Non expirée
- `last_run_at` null OU `(now - last_run_at) >= interval_seconds`

**Exemple :**
```php
if ($validator->shouldRunAgain($record)) {
    // Exécuter la tâche
} else {
    // Attendre le prochain cycle
}
```

---

### `getValidationErrors(RecurringTaskRecord $record): StringTypedCollection`

Retourne toutes les erreurs de validation.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à valider |

**Retourne :** `StringTypedCollection` - Collection des messages d'erreur

**Erreurs possibles :**
- Classe invalide
- Statut incorrect
- Tâche expirée
- Intervalle non atteint

**Exemple :**
```php
$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    echo "Erreurs: " . $errors->join(', ');
}
```

---

### `isValidTaskClass(RecurringTaskRecord $record): bool` (privée)

Vérifie que la classe de la tâche existe et étend `AbstractRecurringTask`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la classe est valide

**Conditions :**
- `class_exists($record->fqcn)`
- `is_subclass_of($record->fqcn, AbstractRecurringTask::class)`

## Cas d'utilisation

### Cas 1 : Validation avant exécution

```php
$validator = new RecurringTaskValidator();

if (!$validator->canRun($record)) {
    $errors = $validator->getValidationErrors($record);
    throw new RuntimeException('Task cannot run: ' . $errors->join(', '));
}

// Exécuter la tâche
```

### Cas 2 : Vérification de l'intervalle

```php
$validator = new RecurringTaskValidator();

if ($validator->shouldRunAgain($record)) {
    // L'intervalle est atteint, exécuter
    $runner->run($record);
} else {
    // L'intervalle n'est pas atteint, ne pas exécuter
    echo "Prochaine exécution dans " . $this->getNextRunDelay($record) . " secondes";
}
```

### Cas 3 : Gestion des tâches en WAITING

```php
$validator = new RecurringTaskValidator();

if ($validator->isReadyToRun($record)) {
    // La tâche est prête à démarrer
    $repository->moveToPlaying($record);
} elseif ($validator->shouldMoveToFinished($record)) {
    // La tâche est expirée avant d'avoir démarré
    $repository->moveToFinished($record);
}
```

### Cas 4 : Détection des erreurs de validation

```php
$validator = new RecurringTaskValidator();

$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    foreach ($errors as $error) {
        echo "❌ $error\n";
    }
} else {
    echo "✅ Tâche valide\n";
}
```

## Flux de validation

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskValidator                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  canRun()                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PLAYING                            │   │
│  │  ✅ !$this->isExpired($record)                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isReadyToRun()                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === WAITING                            │   │
│  │  ✅ $record->start_at !== null                             │   │
│  │  ✅ strtotime($record->start_at) <= now                   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isExpired()                                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ $record->end_at !== null                               │   │
│  │  ✅ strtotime($record->end_at) < now                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  shouldRunAgain()                                                  │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PLAYING                            │   │
│  │  ✅ !$this->isExpired($record)                             │   │
│  │  ✅ $record->last_run_at === null                          │   │
│  │     OU (now - last_run_at) >= interval                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Messages d'erreur

| Situation | Message |
|-----------|---------|
| Classe invalide | `Invalid task class: {fqcn} does not exist or does not extend AbstractRecurringTask` |
| Statut WAITING | `Task is in WAITING state, not PLAYING` |
| Statut PAUSED | `Task is in PAUSED state` |
| Statut FINISHED | `Task is already FINISHED` |
| Statut invalide | `Task is not in PLAYING or WAITING state` |
| Expirée | `Task has expired (end_at reached)` |
| Pas prête | `Task is not ready to run (start_at not reached)` |
| Intervalle non atteint | `Interval not reached (next run in X seconds)` |

## Performance

- **Complexité** : O(1) - toutes les opérations sont constantes
- **Mémoire** : Aucune allocation mémoire significative
- **Validation** : Utilise `class_exists` et `is_subclass_of` (rapides)
- **Dates** : Utilise `strtotime` pour les comparaisons

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$validator = new RecurringTaskValidator();

// 1. Tâche valide en PLAYING
$validRecord = new RecurringTaskRecord(
    alias: new TaskSignatureVO('test'),
    fqcn: TestRecurringTask::class,
    payload: StrictDataObject::from([]),
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    end_at: new Iso8601DateTimeVO(now()->addDays(1)->toIso8601String()),
    last_run_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    status: RecurringTaskStatus::PLAYING,
);

echo "canRun: " . ($validator->canRun($validRecord) ? '✅' : '❌') . "\n";
echo "shouldRunAgain: " . ($validator->shouldRunAgain($validRecord) ? '✅' : '❌') . "\n";

// 2. Tâche invalide (classe inexistante)
$invalidRecord = new RecurringTaskRecord(
    alias: new TaskSignatureVO('test'),
    fqcn: 'NonExistentClass',
    payload: StrictDataObject::from([]),
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    end_at: new Iso8601DateTimeVO(now()->addDays(1)->toIso8601String()),
    last_run_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    status: RecurringTaskStatus::PLAYING,
);

// Récupérer les erreurs
$errors = $validator->getValidationErrors($invalidRecord);
echo "Erreurs: " . $errors->join(', ') . "\n";
// Output: Invalid task class: NonExistentClass does not exist or does not extend AbstractRecurringTask
```

## Voir aussi

- `RecurringTaskValidatorInterface` - Interface du validateur
- `UniqueTaskValidator` - Validateur des tâches uniques
- `RecurringTaskRecord` - DTO des tâches récurrentes
- `RecurringTaskStatus` - Énumération des statuts