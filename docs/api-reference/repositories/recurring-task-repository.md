# RecurringTaskRepository - Référence Technique

## Description

Repository pour la gestion des tâches récurrentes en base de données. Fournit une API complète pour la persistance, la recherche, les changements d'état et le comptage des tâches récurrentes.

## Hiérarchie / Implémentations

```
AbstractRepository<RecurringTask, RecurringTaskRecord>
    └── RecurringTaskRepository
        └── RecurringTaskRepositoryInterface
```

## Rôle principal

Ce repository est responsable de l'accès aux données des tâches récurrentes. Il orchestre toutes les opérations de persistance :

1. **Recherche** des tâches par statut, alias, dates
2. **Changements d'état** (mouvements entre statuts)
3. **Mise à jour** après exécution
4. **Comptage** des tâches par statut
5. **Filtrage** avancé via `RecurringTaskFiltersRecord`

## API

### `findWaiting(?int $limit = null): Collection`

Récupère toutes les tâches en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de résultats |

**Retourne :** `Collection<int, RecurringTask>` - Collection de modèles Eloquent

**Exemple :**
```php
$repository = app(RecurringTaskRepository::class);
$waitingTasks = $repository->findWaiting(10);
```

---

### `findPlaying(?int $limit = null): Collection`

Récupère toutes les tâches en cours d'exécution.

---

### `findPaused(?int $limit = null): Collection`

Récupère toutes les tâches en pause.

---

### `findFinished(?int $limit = null): Collection`

Récupère toutes les tâches terminées.

---

### `findCanceled(?int $limit = null): Collection`

Récupère toutes les tâches annulées.

---

### `findReadyToRun(string $now, ?int $limit = null): Collection`

Récupère les tâches prêtes à être exécutées.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `string` | Date au format ISO 8601 |
| `$limit` | `?int` | Nombre maximum de résultats |

**Conditions :**
- Statut = `WAITING`
- `start_at <= now`

**Retourne :** `Collection<int, RecurringTask>`

**Exemple :**
```php
$ready = $repository->findReadyToRun(now()->toIso8601String(), 50);
```

---

### `findExpired(string $now, ?int $limit = null): Collection`

Récupère les tâches expirées.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$now` | `string` | Date au format ISO 8601 |
| `$limit` | `?int` | Nombre maximum de résultats |

**Conditions :**
- Statut = `PLAYING`
- `end_at <= now`

**Retourne :** `Collection<int, RecurringTask>`

---

### `findByAlias(string $alias): ?RecurringTask`

Trouve une tâche par son alias.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `string` | Alias de la tâche |

**Retourne :** `?RecurringTask` - Modèle de la tâche ou `null`

**Exemple :**
```php
$task = $repository->findByAlias('email-newsletter');
```

---

### `moveToPlaying(RecurringTaskRecord $task): void`

Déplace une tâche vers le statut `PLAYING`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | DTO de la tâche |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `moveToPaused(RecurringTaskRecord $task): void`

Déplace une tâche vers le statut `PAUSED`.

---

### `moveToWaiting(RecurringTaskRecord $task): void`

Déplace une tâche vers le statut `WAITING`.

---

### `moveToFinished(RecurringTaskRecord $task): void`

Déplace une tâche vers le statut `FINISHED`.

**Comportement :**
- Définit `finished_at` à la date actuelle

---

### `moveToCanceled(RecurringTaskRecord $task): void`

Déplace une tâche vers le statut `CANCELED`.

**Comportement :**
- Définit `finished_at` à la date actuelle
- Définit `cancelled_at` à la date actuelle

---

### `updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void`

Met à jour une tâche après exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | DTO de la tâche |
| `$success` | `bool` | Succès de l'exécution |
| `$error` | `?string` | Message d'erreur (optionnel) |

**Comportement :**
- Met à jour `last_run_at` à la date actuelle
- Ajoute une entrée de débogage via `TaskExecutionDebugRepository`
- Statut reste `PLAYING` pour les tâches récurrentes

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `countWaiting(): int`

Compte le nombre de tâches en attente.

### `countPlaying(): int`

Compte le nombre de tâches en cours d'exécution.

### `countPaused(): int`

Compte le nombre de tâches en pause.

### `countFinished(): int`

Compte le nombre de tâches terminées.

### `countCanceled(): int`

Compte le nombre de tâches annulées.

## Filtres

Le repository utilise `RecurringTaskFiltersRecord` pour les recherches avancées :

| Champ | Type | Description |
|-------|------|-------------|
| `alias` | `TaskSignatureVO` | Alias de la tâche |
| `fqcn` | `string` | Classe de la tâche |
| `status` | `RecurringTaskStatus` | Statut de la tâche |
| `start_at_from` | `Iso8601DateTimeVO` | Date de début (>=) |
| `start_at_to` | `Iso8601DateTimeVO` | Date de début (<=) |
| `end_at_from` | `Iso8601DateTimeVO` | Date de fin (>=) |
| `end_at_to` | `Iso8601DateTimeVO` | Date de fin (<=) |
| `last_run_at_from` | `Iso8601DateTimeVO` | Dernière exécution (>=) |
| `last_run_at_to` | `Iso8601DateTimeVO` | Dernière exécution (<=) |
| `cancelled_at_from` | `Iso8601DateTimeVO` | Date d'annulation (>=) |
| `cancelled_at_to` | `Iso8601DateTimeVO` | Date d'annulation (<=) |
| `include_deleted` | `bool` | Inclure les tâches supprimées |

**Exemple de filtres :**
```php
$filters = new RecurringTaskFiltersRecord(
    status: RecurringTaskStatus::PLAYING,
    start_at_from: new Iso8601DateTimeVO(now()->subDays(1)->toIso8601String()),
    include_deleted: false,
);

$results = $repository->findBy(new FindByRecord(filters: $filters));
```

## Flux des mouvements d'état

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Mouvements d'état                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  WAITING ──────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ moveToPlaying() (start_at atteint)                         ││
│     ▼                                                             ││
│  PLAYING ─────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ moveToPaused() (pause manuelle)                            ││
│     ▼                                                             ││
│  PAUSED ─────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ moveToWaiting() (reprise)                                  ││
│     ▼                                                             ││
│  WAITING ─────────────────────────────────────────────────────────┐│
│                                                                     ││
│  PLAYING ─────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ moveToFinished() (fin manuelle ou end_at atteint)          ││
│     ▼                                                             ││
│  FINISHED                                                         ││
│                                                                     ││
│  * → moveToCanceled() → CANCELED (depuis n'importe quel état)    ││
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cas d'utilisation

### Cas 1 : Recherche de tâches prêtes

```php
$repository = app(RecurringTaskRepository::class);

// Récupérer les 10 premières tâches prêtes
$readyTasks = $repository->findReadyToRun(now()->toIso8601String(), 10);

foreach ($readyTasks as $task) {
    $record = $task->toRecord();
    // Traiter la tâche...
}
```

### Cas 2 : Changement d'état

```php
$repository = app(RecurringTaskRepository::class);

// Trouver une tâche
$task = $repository->findByAlias('email-newsletter');
if ($task) {
    $record = $task->toRecord();
    
    // Mettre en pause
    $repository->moveToPaused($record);
    
    // Plus tard, reprendre
    $repository->moveToWaiting($record);
}
```

### Cas 3 : Mise à jour après exécution

```php
$repository = app(RecurringTaskRepository::class);

$task = $repository->findByAlias('email-newsletter');
$record = $task->toRecord();

try {
    // Exécuter la tâche...
    $repository->updateAfterRun($record, true);
} catch (\Throwable $e) {
    $repository->updateAfterRun($record, false, $e->getMessage());
}
```

### Cas 4 : Comptage des tâches

```php
$repository = app(RecurringTaskRepository::class);

echo "WAITING: " . $repository->countWaiting() . "\n";
echo "PLAYING: " . $repository->countPlaying() . "\n";
echo "PAUSED: " . $repository->countPaused() . "\n";
echo "FINISHED: " . $repository->countFinished() . "\n";
echo "CANCELED: " . $repository->countCanceled() . "\n";
```

### Cas 5 : Recherche avancée

```php
$repository = app(RecurringTaskRepository::class);

// Tâches en PLAYING avec start_at dans les 7 derniers jours
$filters = new RecurringTaskFiltersRecord(
    status: RecurringTaskStatus::PLAYING,
    start_at_from: new Iso8601DateTimeVO(now()->subDays(7)->toIso8601String()),
);

$results = $repository->findBy(new FindByRecord(filters: $filters));
```

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `TaskExecutionDebugRepository` | Ajout des logs de débogage |
| `AbstractRepository` | Classe de base du Repository Pattern |
| `RecurringTask` | Modèle Eloquent |
| `RecurringTaskRecord` | DTO de la tâche |

## Héritage / Méthodes héritées

Ce repository hérite de `AbstractRepository` et bénéficie des méthodes suivantes :

| Méthode | Description |
|---------|-------------|
| `create(AbstractRecord $record)` | Crée un nouvel enregistrement |
| `update(int $id, AbstractRecord $record)` | Met à jour un enregistrement existant |
| `delete(int $id)` | Supprime un enregistrement (soft delete) |
| `findBy(FindByRecord $findByRecord)` | Recherche avec filtres |
| `findWithTrashed(int $id)` | Trouve un enregistrement supprimé |
| `count(?AbstractRecord $filters = null)` | Compte les enregistrements |

## Performance

- **Complexité** : O(1) pour les opérations unitaires, O(n) pour les finders avec résultats
- **Base de données** : Utilise Eloquent avec des requêtes optimisées
- **Index recommandés** :
  - `alias` (unique)
  - `status`
  - `start_at`
  - `end_at`
  - `cancelled_at`

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 12.x, 13.x, 14.x, 15.x | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$repository = app(RecurringTaskRepository::class);

// 1. Trouver une tâche par alias
$task = $repository->findByAlias('email-newsletter');
if ($task) {
    echo "Tâche trouvée: {$task->getAlias()->value}\n";
    echo "Statut: {$task->getStatus()->value}\n";
}

// 2. Récupérer les tâches prêtes
$ready = $repository->findReadyToRun(now()->toIso8601String(), 10);
echo "Tâches prêtes: " . $ready->count() . "\n";

// 3. Récupérer les tâches expirées
$expired = $repository->findExpired(now()->toIso8601String());
echo "Tâches expirées: " . $expired->count() . "\n";

// 4. Compter par statut
echo "WAITING: " . $repository->countWaiting() . "\n";
echo "PLAYING: " . $repository->countPlaying() . "\n";
echo "PAUSED: " . $repository->countPaused() . "\n";
echo "FINISHED: " . $repository->countFinished() . "\n";
echo "CANCELED: " . $repository->countCanceled() . "\n";

// 5. Recherche avancée
$filters = new RecurringTaskFiltersRecord(
    status: RecurringTaskStatus::PLAYING,
    start_at_from: new Iso8601DateTimeVO(now()->subDays(7)->toIso8601String()),
);

$results = $repository->findBy(new FindByRecord(filters: $filters, limit: 20));

foreach ($results as $task) {
    echo "{$task->getAlias()->value} (dernier run: {$task->getLastRunAt()?->value})\n";
}
```

## Voir aussi

- `RecurringTaskRepositoryInterface` - Interface du repository
- `RecurringTask` - Modèle Eloquent
- `RecurringTaskRecord` - DTO des tâches récurrentes
- `RecurringTaskFiltersRecord` - DTO de filtres
- `UniqueTaskRepository` - Repository des tâches uniques
- `RecurringTaskService` - Service des tâches récurrentes