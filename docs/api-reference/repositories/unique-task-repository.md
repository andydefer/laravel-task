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