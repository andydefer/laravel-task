# UniqueTaskRepository - Référence Technique

## Description

Repository pour la gestion des tâches uniques en base de données. Fournit une API complète pour la persistance, la recherche, les changements d'état et le comptage des tâches uniques.

## Hiérarchie / Implémentations

```
AbstractRepository<UniqueTask, UniqueTaskRecord>
    └── UniqueTaskRepository
        └── UniqueTaskRepositoryInterface
```

## Rôle principal

Ce repository est responsable de l'accès aux données des tâches uniques. Il orchestre toutes les opérations de persistance :

1. **Recherche** des tâches par statut, ID, alias, dates
2. **Changements d'état** (mouvements entre statuts)
3. **Mise à jour** des tentatives
4. **Ajout** de logs de débogage
5. **Comptage** des tâches par statut
6. **Filtrage** avancé via `UniqueTaskFiltersRecord`

## API

### `findPending(?int $limit = null): Collection`

Récupère toutes les tâches en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de résultats |

**Retourne :** `Collection<int, UniqueTask>` - Collection de modèles Eloquent

**Exemple :**
```php
$repository = app(UniqueTaskRepository::class);
$pendingTasks = $repository->findPending(10);
```

---

### `findCompleted(?int $limit = null): Collection`

Récupère toutes les tâches terminées avec succès.

---

### `findFailed(?int $limit = null): Collection`

Récupère toutes les tâches en échec.

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
- Statut = `PENDING`
- `scheduled_at <= now`

**Retourne :** `Collection<int, UniqueTask>`

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
- Statut = `PENDING`
- `scheduled_at + grace_period_seconds < now`

**Retourne :** `Collection<int, UniqueTask>`

**Exemple :**
```php
$expired = $repository->findExpired(now()->toIso8601String());
```

---

### `findById(string $id): ?UniqueTask`

Trouve une tâche par son UUID.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `string` | UUID de la tâche |

**Validation :** Format UUID v4 (36 caractères avec tirets)

**Retourne :** `?UniqueTask` - Modèle de la tâche ou `null`

**Exemple :**
```php
$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
```

---

### `updateAttempts(UniqueTaskRecord $task, int $newAttempts): void`

Met à jour le nombre de tentatives d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | DTO de la tâche |
| `$newAttempts` | `int` | Nouveau nombre de tentatives |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `addDebug(UniqueTaskRecord $task, string $status, string $info): void`

Ajoute une entrée de débogage pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | DTO de la tâche |
| `$status` | `string` | Statut de l'opération |
| `$info` | `string` | Informations supplémentaires |

**Comportement :** Délègue à `TaskExecutionDebugRepository`

---

### `moveToCompleted(UniqueTaskRecord $task): void`

Déplace une tâche vers le statut `COMPLETED`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `UniqueTaskRecord` | DTO de la tâche |

**Comportement :**
- Définit `finished_at` à la date actuelle

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

---

### `moveToFailed(UniqueTaskRecord $task): void`

Déplace une tâche vers le statut `FAILED`.

**Comportement :**
- Définit `finished_at` à la date actuelle

---

### `moveToCanceled(UniqueTaskRecord $task): void`

Déplace une tâche vers le statut `CANCELED`.

**Comportement :**
- Définit `finished_at` à la date actuelle

---

### `countPending(): int`

Compte le nombre de tâches en attente.

### `countCompleted(): int`

Compte le nombre de tâches terminées avec succès.

### `countFailed(): int`

Compte le nombre de tâches en échec.

### `countCanceled(): int`

Compte le nombre de tâches annulées.

## Filtres

Le repository utilise `UniqueTaskFiltersRecord` pour les recherches avancées :

| Champ | Type | Description |
|-------|------|-------------|
| `id` | `TaskIdVO` | UUID de la tâche |
| `alias` | `TaskSignatureVO` | Alias de la tâche |
| `fqcn` | `string` | Classe de la tâche |
| `status` | `UniqueTaskStatus` | Statut de la tâche |
| `scheduled_at_from` | `Iso8601DateTimeVO` | Date planifiée (>=) |
| `scheduled_at_to` | `Iso8601DateTimeVO` | Date planifiée (<=) |
| `finished_at_from` | `Iso8601DateTimeVO` | Date de fin (>=) |
| `finished_at_to` | `Iso8601DateTimeVO` | Date de fin (<=) |
| `attempts` | `int` | Nombre de tentatives |
| `max_attempts` | `int` | Nombre maximum de tentatives |
| `include_deleted` | `bool` | Inclure les tâches supprimées |

**Exemple de filtres :**
```php
$filters = new UniqueTaskFiltersRecord(
    status: UniqueTaskStatus::PENDING,
    scheduled_at_from: new Iso8601DateTimeVO(now()->subDays(1)->toIso8601String()),
    attempts: 0,
);

$results = $repository->findBy(new FindByRecord(filters: $filters));
```

## Flux des mouvements d'état

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Mouvements d'état                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  PENDING ──────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ moveToCompleted() (succès)                                 ││
│     ▼                                                             ││
│  COMPLETED                                                        ││
│                                                                     ││
│  PENDING ──────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ moveToFailed() (échec final)                               ││
│     ▼                                                             ││
│  FAILED                                                           ││
│                                                                     ││
│  PENDING ──────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ moveToCanceled() (annulation manuelle)                     ││
│     ▼                                                             ││
│  CANCELED                                                         ││
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cas d'utilisation

### Cas 1 : Recherche de tâches prêtes

```php
$repository = app(UniqueTaskRepository::class);

// Récupérer les 10 premières tâches prêtes
$readyTasks = $repository->findReadyToRun(now()->toIso8601String(), 10);

foreach ($readyTasks as $task) {
    $record = $task->toRecord();
    // Traiter la tâche...
}
```

### Cas 2 : Changement d'état

```php
$repository = app(UniqueTaskRepository::class);

// Trouver une tâche
$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
if ($task) {
    $record = $task->toRecord();
    
    // Marquer comme complétée
    $repository->moveToCompleted($record);
    
    // ou comme échouée
    $repository->moveToFailed($record);
    
    // ou comme annulée
    $repository->moveToCanceled($record);
}
```

### Cas 3 : Mise à jour des tentatives

```php
$repository = app(UniqueTaskRepository::class);

$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
if ($task) {
    $record = $task->toRecord();
    $newAttempts = $record->attempts->increment();
    
    // Mettre à jour le nombre de tentatives
    $repository->updateAttempts($record, $newAttempts->value);
}
```

### Cas 4 : Ajout de logs de débogage

```php
$repository = app(UniqueTaskRepository::class);

$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
if ($task) {
    $record = $task->toRecord();
    
    $repository->addDebug(
        $record,
        'succeeded',
        'Task executed successfully'
    );
}
```

### Cas 5 : Comptage des tâches

```php
$repository = app(UniqueTaskRepository::class);

echo "PENDING: " . $repository->countPending() . "\n";
echo "COMPLETED: " . $repository->countCompleted() . "\n";
echo "FAILED: " . $repository->countFailed() . "\n";
echo "CANCELED: " . $repository->countCanceled() . "\n";
```

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `TaskExecutionDebugRepository` | Ajout des logs de débogage |
| `AbstractRepository` | Classe de base du Repository Pattern |
| `UniqueTask` | Modèle Eloquent |
| `UniqueTaskRecord` | DTO de la tâche |

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
  - `id` (clé primaire)
  - `status`
  - `scheduled_at`
  - `alias`

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 12.x, 13.x, 14.x, 15.x | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;

$repository = app(UniqueTaskRepository::class);

// 1. Trouver une tâche par ID
$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
if ($task) {
    echo "Tâche trouvée: {$task->getId()->value}\n";
    echo "Statut: {$task->getStatus()->value}\n";
}

// 2. Récupérer les tâches prêtes
$ready = $repository->findReadyToRun(now()->toIso8601String(), 10);
echo "Tâches prêtes: " . $ready->count() . "\n";

// 3. Récupérer les tâches expirées
$expired = $repository->findExpired(now()->toIso8601String());
echo "Tâches expirées: " . $expired->count() . "\n";

// 4. Compter par statut
echo "PENDING: " . $repository->countPending() . "\n";
echo "COMPLETED: " . $repository->countCompleted() . "\n";
echo "FAILED: " . $repository->countFailed() . "\n";
echo "CANCELED: " . $repository->countCanceled() . "\n";

// 5. Recherche avancée
$filters = new UniqueTaskFiltersRecord(
    status: UniqueTaskStatus::PENDING,
    scheduled_at_from: new Iso8601DateTimeVO(now()->subDays(7)->toIso8601String()),
    attempts: 0,
);

$results = $repository->findBy(new FindByRecord(filters: $filters, limit: 20));

foreach ($results as $task) {
    echo "{$task->getAlias()->value} (planifiée: {$task->getScheduledAt()->value})\n";
}

// 6. Mettre à jour les tentatives
$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
if ($task) {
    $record = $task->toRecord();
    $repository->updateAttempts($record, 2);
}

// 7. Ajouter un log de débogage
$task = $repository->findById('550e8400-e29b-41d4-a716-446655440000');
if ($task) {
    $record = $task->toRecord();
    $repository->addDebug($record, 'succeeded', 'Task completed');
}
```

## Voir aussi

- `UniqueTaskRepositoryInterface` - Interface du repository
- `UniqueTask` - Modèle Eloquent
- `UniqueTaskRecord` - DTO des tâches uniques
- `UniqueTaskFiltersRecord` - DTO de filtres
- `RecurringTaskRepository` - Repository des tâches récurrentes
- `UniqueTaskService` - Service des tâches uniques