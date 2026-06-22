# TaskExecutionDebugRepository - Référence Technique

## Description

Repository pour la gestion des logs de débogage des tâches. Fournit une API pour enregistrer, consulter, supprimer et compter les traces d'exécution des tâches uniques et récurrentes.

## Hiérarchie / Implémentations

```
AbstractRepository<TaskExecutionDebug, TaskExecutionDebugRecord>
    └── TaskExecutionDebugRepository
        └── TaskExecutionDebugRepositoryInterface
```

## Rôle principal

Ce repository est responsable de l'accès aux données des logs de débogage. Il orchestre toutes les opérations de persistance :

1. **Enregistrement** des entrées de débogage avec `acted_at`
2. **Recherche** des logs par type de tâche et identifiant
3. **Suppression** des logs de débogage
4. **Comptage** des entrées de débogage
5. **Filtrage** avancé via `TaskExecutionDebugFiltersRecord`

## API

### `findByTask(string $taskType, string $taskIdentifier): Collection`

Récupère tous les logs de débogage pour une tâche spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskType` | `string` | Type de tâche (`'recurring'` ou `'unique'`) |
| `$taskIdentifier` | `string` | Identifiant de la tâche (alias pour récurrente, UUID pour unique) |

**Retourne :** `Collection<int, TaskExecutionDebug>` - Collection triée par `created_at` décroissant

**Exemple :**
```php
$repository = app(TaskExecutionDebugRepository::class);
$debugs = $repository->findByTask('recurring', 'email-newsletter');
```

---

### `addDebug(string $taskType, string $taskIdentifier, string $status, string $info): void`

Ajoute une entrée de débogage pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskType` | `string` | Type de tâche (`'recurring'` ou `'unique'`) |
| `$taskIdentifier` | `string` | Identifiant de la tâche (alias pour récurrente, UUID pour unique) |
| `$status` | `string` | Statut de l'opération (ex: `'succeeded'`, `'failed'`, `'started'`) |
| `$info` | `string` | Informations supplémentaires sur l'opération |

**Comportement :**
- Crée automatiquement un timestamp `acted_at`
- Stocke les données dans une colonne JSON

**Exemple :**
```php
$repository = app(TaskExecutionDebugRepository::class);

$repository->addDebug(
    'recurring',
    'email-newsletter',
    'failed',
    'Connection timeout while sending email'
);
```

---

### `clearTaskDebug(string $taskType, string $taskIdentifier): void`

Supprime tous les logs de débogage pour une tâche spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskType` | `string` | Type de tâche (`'recurring'` ou `'unique'`) |
| `$taskIdentifier` | `string` | Identifiant de la tâche (alias pour récurrente, UUID pour unique) |

**Comportement :**
- Trouve toutes les entrées correspondantes
- Les supprime (hard delete)

**Exemple :**
```php
$repository = app(TaskExecutionDebugRepository::class);

// Supprimer tous les logs d'une tâche récurrente
$repository->clearTaskDebug('recurring', 'email-newsletter');
```

---

### `countTaskDebug(string $taskType, string $taskIdentifier): int`

Compte le nombre d'entrées de débogage pour une tâche spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskType` | `string` | Type de tâche (`'recurring'` ou `'unique'`) |
| `$taskIdentifier` | `string` | Identifiant de la tâche (alias pour récurrente, UUID pour unique) |

**Retourne :** `int` - Nombre d'entrées de débogage

**Exemple :**
```php
$repository = app(TaskExecutionDebugRepository::class);
$count = $repository->countTaskDebug('recurring', 'email-newsletter');
echo "Nombre de tentatives: {$count}\n";
```

## Filtres

Le repository utilise `TaskExecutionDebugFiltersRecord` pour les recherches avancées :

| Champ | Type | Description |
|-------|------|-------------|
| `task_type` | `string` | Type de tâche (`'recurring'` ou `'unique'`) |
| `task_identifier` | `string` | Identifiant de la tâche |
| `status` | `ExecutionStatus` | Statut de l'opération (`succeeded`, `failed`, etc.) |
| `acted_at_from` | `Iso8601DateTimeVO` | Date d'action (>=) |
| `acted_at_to` | `Iso8601DateTimeVO` | Date d'action (<=) |

**Exemple de filtres :**
```php
$filters = new TaskExecutionDebugFiltersRecord(
    task_type: 'unique',
    status: ExecutionStatus::FAILED,
    acted_at_from: new Iso8601DateTimeVO(now()->subDays(7)->toIso8601String()),
);

$results = $repository->findBy(new FindByRecord(filters: $filters));
```

## Structure des données

### Modèle Eloquent (TaskExecutionDebug)

```php
// Table : task_execution_debugs
// Colonnes : id, task_type, task_identifier, data, created_at, updated_at

// Structure de la colonne data (JSON) :
$data = [
    'acted_at' => '2026-06-22T14:30:00+00:00',  // Date de l'action
    'status' => 'succeeded',                     // Statut de l'opération
    'info' => 'Task executed successfully',      // Informations
];
```

### Accès aux données via le modèle

```php
$debug = $debugs->first();

// Méthodes du modèle
$id = $debug->getId();           // int
$taskType = $debug->getTaskType(); // string
$taskIdentifier = $debug->getTaskIdentifier(); // string
$data = $debug->getData();       // StrictDataObject
$actedAt = $debug->getActedAtVO(); // Iso8601DateTimeVO
$status = $debug->getStatusVO(); // ExecutionStatus
$info = $debug->getInfo();       // string
```

## Cas d'utilisation

### Cas 1 : Journalisation des exécutions

```php
$repository = app(TaskExecutionDebugRepository::class);

try {
    // Exécution de la tâche...
    
    $repository->addDebug(
        'recurring',
        'email-newsletter',
        'succeeded',
        'Task executed successfully'
    );
} catch (\Throwable $e) {
    $repository->addDebug(
        'recurring',
        'email-newsletter',
        'failed',
        $e->getMessage()
    );
}
```

### Cas 2 : Consultation de l'historique

```php
$repository = app(TaskExecutionDebugRepository::class);

$debugs = $repository->findByTask('unique', '550e8400-e29b-41d4-a716-446655440000');

foreach ($debugs as $debug) {
    echo sprintf(
        "[%s] %s: %s\n",
        $debug->getActedAtVO()->toDateTime()->format('Y-m-d H:i:s'),
        $debug->getStatusVO()->value,
        $debug->getInfo()
    );
}
```

### Cas 3 : Nettoyage des logs

```php
$repository = app(TaskExecutionDebugRepository::class);

// Supprimer les logs d'une tâche spécifique
$repository->clearTaskDebug('recurring', 'email-newsletter');

// Compter les logs restants
$count = $repository->countTaskDebug('recurring', 'email-newsletter');
echo "Logs restants: {$count}\n";
```

### Cas 4 : Recherche avancée

```php
$repository = app(TaskExecutionDebugRepository::class);

// Tous les échecs des 7 derniers jours
$filters = new TaskExecutionDebugFiltersRecord(
    status: ExecutionStatus::FAILED,
    acted_at_from: new Iso8601DateTimeVO(now()->subDays(7)->toIso8601String()),
);

$failures = $repository->findBy(new FindByRecord(filters: $filters));

foreach ($failures as $failure) {
    $taskType = $failure->getTaskType();
    $taskId = $failure->getTaskIdentifier();
    $info = $failure->getInfo();
    
    echo "Échec de {$taskType} {$taskId}: {$info}\n";
}
```

## Ordre de tri

Les résultats de `findByTask()` sont triés par `created_at` décroissant (les plus récents en premier) :

```php
protected function applySort(Builder $query, SortColumns $sortColumns): void
{
    // Les résultats sont automatiquement triés par created_at DESC
}
```

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `AbstractRepository` | Classe de base du Repository Pattern |
| `TaskExecutionDebug` | Modèle Eloquent |
| `TaskExecutionDebugRecord` | DTO des logs de débogage |
| `TaskExecutionDebugFiltersRecord` | DTO de filtres |

## Héritage / Méthodes héritées

Ce repository hérite de `AbstractRepository` et bénéficie des méthodes suivantes :

| Méthode | Description |
|---------|-------------|
| `create(AbstractRecord $record)` | Crée un nouvel enregistrement |
| `update(int $id, AbstractRecord $record)` | Met à jour un enregistrement existant |
| `delete(int $id)` | Supprime un enregistrement (hard delete) |
| `findBy(FindByRecord $findByRecord)` | Recherche avec filtres |
| `count(?AbstractRecord $filters = null)` | Compte les enregistrements |

## Performance

- **Complexité** : O(1) pour les opérations unitaires, O(n) pour les finders
- **Base de données** : Utilise Eloquent avec des requêtes optimisées
- **Index recommandés** :
  - `task_type`
  - `task_identifier`
  - Index composite `(task_type, task_identifier)`
  - Index sur `(data->status)` (si possible)

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 12.x, 13.x, 14.x, 15.x | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Records\TaskExecutionDebugFiltersRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

$repository = app(TaskExecutionDebugRepository::class);

// 1. Ajouter des logs
$repository->addDebug(
    'recurring',
    'email-newsletter',
    'started',
    'Task execution started'
);

sleep(1);

$repository->addDebug(
    'recurring',
    'email-newsletter',
    'succeeded',
    'Task completed successfully'
);

// 2. Consulter les logs
$debugs = $repository->findByTask('recurring', 'email-newsletter');
echo "Nombre de logs: " . $debugs->count() . "\n";

foreach ($debugs as $debug) {
    echo sprintf(
        "[%s] %s: %s\n",
        $debug->getActedAtVO()->toDateTime()->format('H:i:s'),
        $debug->getStatusVO()->value,
        $debug->getInfo()
    );
}

// 3. Compter les logs
$count = $repository->countTaskDebug('recurring', 'email-newsletter');
echo "Nombre de logs: {$count}\n";

// 4. Rechercher les échecs
$filters = new TaskExecutionDebugFiltersRecord(
    status: ExecutionStatus::FAILED,
);

$failures = $repository->findBy(new FindByRecord(filters: $filters));

foreach ($failures as $failure) {
    $taskId = $failure->getTaskIdentifier();
    $info = $failure->getInfo();
    echo "Échec de {$taskId}: {$info}\n";
}

// 5. Supprimer les logs
$repository->clearTaskDebug('recurring', 'email-newsletter');

$remaining = $repository->countTaskDebug('recurring', 'email-newsletter');
echo "Logs restants: {$remaining}\n"; // 0
```

## Voir aussi

- `TaskExecutionDebugRepositoryInterface` - Interface du repository
- `TaskExecutionDebug` - Modèle Eloquent
- `TaskExecutionDebugRecord` - DTO des logs de débogage
- `TaskExecutionDebugFiltersRecord` - DTO de filtres
- `TaskExecutionDebugService` - Service des logs de débogage
- `RecurringTaskRepository` - Repository des tâches récurrentes
- `UniqueTaskRepository` - Repository des tâches uniques