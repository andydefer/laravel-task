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