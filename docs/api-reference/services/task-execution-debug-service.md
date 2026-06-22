# TaskExecutionDebugService - Référence Technique

## Description

Service de gestion des logs de débogage pour les tâches. Fournit une API pour enregistrer, consulter et gérer les traces d'exécution des tâches uniques et récurrentes.

## Hiérarchie / Implémentations

```
TaskExecutionDebugServiceInterface
    └── TaskExecutionDebugService
```

## Rôle principal

Ce service est le point d'entrée pour la gestion des logs de débogage des tâches. Il orchestre toutes les opérations liées au débogage :

1. **Enregistrement** des entrées de débogage pour les tâches
2. **Consultation** des logs par type de tâche et identifiant
3. **Suppression** des logs de débogage
4. **Comptage** des entrées de débogage

## API

### `findByTask(string $taskType, string $taskIdentifier): Collection`

Récupère tous les logs de débogage pour une tâche spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskType` | `string` | Type de tâche (`'recurring'` ou `'unique'`) |
| `$taskIdentifier` | `string` | Identifiant de la tâche (alias pour récurrente, UUID pour unique) |

**Retourne :** `Collection<int, object>` - Collection des entrées de débogage triées par date décroissante

**Exemple :**
```php
$service = app(TaskExecutionDebugService::class);

$debugs = $service->findByTask('recurring', 'email-newsletter');

foreach ($debugs as $debug) {
    echo $debug->getData()->status . ': ' . $debug->getData()->info . "\n";
}
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

**Exemple :**
```php
$service = app(TaskExecutionDebugService::class);

$service->addDebug(
    'recurring',
    'email-newsletter',
    'failed',
    'Connection timeout while sending email'
);
```

---

### `findByRecurringTask(string $alias): Collection`

Récupère tous les logs de débogage pour une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `string` | Alias de la tâche récurrente |

**Retourne :** `Collection<int, object>` - Collection des entrées de débogage

**Exemple :**
```php
$service = app(TaskExecutionDebugService::class);

$debugs = $service->findByRecurringTask('email-newsletter');

echo "Nombre d'exécutions: " . $debugs->count() . "\n";
```

---

### `findByUniqueTask(string $taskId): Collection`

Récupère tous les logs de débogage pour une tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `string` | UUID de la tâche unique |

**Retourne :** `Collection<int, object>` - Collection des entrées de débogage

**Exemple :**
```php
$service = app(TaskExecutionDebugService::class);

$debugs = $service->findByUniqueTask('550e8400-e29b-41d4-a716-446655440000');

echo "Nombre de tentatives: " . $debugs->count() . "\n";
```

---

### `addDebugForRecurringTask(string $alias, string $status, string $info): void`

Ajoute une entrée de débogage pour une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `string` | Alias de la tâche récurrente |
| `$status` | `string` | Statut de l'opération |
| `$info` | `string` | Informations supplémentaires |

**Exemple :**
```php
$service = app(TaskExecutionDebugService::class);

$service->addDebugForRecurringTask(
    'email-newsletter',
    'started',
    'Task execution started at ' . now()
);
```

---

### `addDebugForUniqueTask(string $taskId, string $status, string $info): void`

Ajoute une entrée de débogage pour une tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `string` | UUID de la tâche unique |
| `$status` | `string` | Statut de l'opération |
| `$info` | `string` | Informations supplémentaires |

**Exemple :**
```php
$service = app(TaskExecutionDebugService::class);

$service->addDebugForUniqueTask(
    '550e8400-e29b-41d4-a716-446655440000',
    'completed',
    'Task completed successfully'
);
```

---

### `clearTaskDebug(string $taskType, string $taskIdentifier): void`

Supprime tous les logs de débogage pour une tâche spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskType` | `string` | Type de tâche (`'recurring'` ou `'unique'`) |
| `$taskIdentifier` | `string` | Identifiant de la tâche (alias pour récurrente, UUID pour unique) |

**Exemple :**
```php
$service = app(TaskExecutionDebugService::class);

// Supprimer tous les logs d'une tâche récurrente
$service->clearTaskDebug('recurring', 'email-newsletter');

// Supprimer tous les logs d'une tâche unique
$service->clearTaskDebug('unique', '550e8400-e29b-41d4-a716-446655440000');
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
$service = app(TaskExecutionDebugService::class);

$count = $service->countTaskDebug('recurring', 'email-newsletter');
echo "Nombre de tentatives: {$count}\n";
```

## Structure des données

### Entrée de débogage (TaskExecutionDebug)

```php
// Structure des données stockées
$data = [
    'acted_at' => '2026-06-22T14:30:00+00:00',  // Date de l'action
    'status' => 'succeeded',                     // Statut de l'opération
    'info' => 'Task executed successfully',      // Informations
];

// Accès via le modèle
$debug = $debugs->first();
$actedAt = $debug->getActedAtVO();   // Iso8601DateTimeVO
$status = $debug->getStatusVO();     // ExecutionStatus
$info = $debug->getInfo();           // string
```

## Cas d'utilisation

### Cas 1 : Journalisation des exécutions

```php
$service = app(TaskExecutionDebugService::class);

try {
    // Exécution de la tâche
    $task->execute($payload);
    
    // Journaliser le succès
    $service->addDebugForRecurringTask(
        'email-newsletter',
        'succeeded',
        'Task executed successfully'
    );
} catch (\Throwable $e) {
    // Journaliser l'échec
    $service->addDebugForRecurringTask(
        'email-newsletter',
        'failed',
        $e->getMessage()
    );
}
```

### Cas 2 : Consultation de l'historique

```php
$service = app(TaskExecutionDebugService::class);

// Récupérer tous les logs d'une tâche
$debugs = $service->findByRecurringTask('email-newsletter');

foreach ($debugs as $debug) {
    $date = $debug->getActedAtVO()->toDateTime()->format('Y-m-d H:i:s');
    $status = $debug->getStatusVO()->value;
    $info = $debug->getInfo();
    
    echo "[{$date}] {$status}: {$info}\n";
}
```

### Cas 3 : Nettoyage des logs

```php
$service = app(TaskExecutionDebugService::class);

// Supprimer les logs d'une tâche spécifique
$service->clearTaskDebug('recurring', 'email-newsletter');

// Vérifier que les logs ont été supprimés
$count = $service->countTaskDebug('recurring', 'email-newsletter');
echo "Logs restants: {$count}\n"; // 0
```

### Cas 4 : Statut d'une tâche

```php
$service = app(TaskExecutionDebugService::class);

$debugs = $service->findByUniqueTask('550e8400-e29b-41d4-a716-446655440000');
$lastDebug = $debugs->first();

if ($lastDebug) {
    $status = $lastDebug->getStatusVO();
    
    match ($status) {
        ExecutionStatus::SUCCEEDED => echo "✅ Tâche réussie\n",
        ExecutionStatus::FAILED => echo "❌ Tâche échouée\n",
        default => echo "⏳ Tâche en cours\n",
    };
}
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                  TaskExecutionDebugService                         │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  AJOUT                                                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  addDebug() / addDebugForRecurringTask()                   │   │
│  │  addDebugForUniqueTask()                                   │   │
│  │  ├─ Valider les paramètres                                 │   │
│  │  ├─ Créer un StrictDataObject avec acted_at                │   │
│  │  └─ repository->addDebug()                                 │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  RECHERCHE                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findByTask() / findByRecurringTask() / findByUniqueTask() │   │
│  │  ├─ Déléguer au repository                                  │   │
│  │  └─ Retourner une Collection triée par date décroissante   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  SUPPRESSION                                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  clearTaskDebug()                                          │   │
│  │  ├─ Déléguer au repository                                  │   │
│  │  └─ Supprimer toutes les entrées de la tâche               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  COMPTAGE                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  countTaskDebug()                                          │   │
│  │  ├─ Déléguer au repository                                  │   │
│  │  └─ Retourner le nombre d'entrées                           │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| `addDebug()` avec `$taskType` invalide | Aucune validation - le type est stocké tel quel |
| `findByTask()` avec une tâche inexistante | Retourne une `Collection` vide |
| `clearTaskDebug()` avec une tâche inexistante | Aucune opération, pas d'erreur |
| `countTaskDebug()` avec une tâche inexistante | Retourne `0` |

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `TaskExecutionDebugRepositoryInterface` | Accès aux données via Repository |

## Performance

- **Complexité** : O(1) pour les opérations unitaires
- **Mémoire** : Les collections sont chargées en mémoire
- **Base de données** : Chaque opération génère des requêtes Eloquent
- **Index** : Les colonnes `task_type` et `task_identifier` devraient être indexées

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 12.x, 13.x, 14.x, 15.x | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskExecutionDebugService;

$service = app(TaskExecutionDebugService::class);

// 1. Ajouter des logs pour une tâche récurrente
$service->addDebugForRecurringTask(
    'email-newsletter',
    'started',
    'Task execution started'
);

sleep(1);

$service->addDebugForRecurringTask(
    'email-newsletter',
    'succeeded',
    'Task completed successfully'
);

// 2. Consulter les logs
$debugs = $service->findByRecurringTask('email-newsletter');
echo "Nombre de logs: " . $debugs->count() . "\n";

foreach ($debugs as $debug) {
    echo sprintf(
        "[%s] %s: %s\n",
        $debug->getActedAtVO()->toDateTime()->format('H:i:s'),
        $debug->getStatusVO()->value,
        $debug->getInfo()
    );
}

// 3. Ajouter un log pour une tâche unique
$service->addDebugForUniqueTask(
    '550e8400-e29b-41d4-a716-446655440000',
    'failed',
    'Connection timeout'
);

// 4. Compter les logs
$count = $service->countTaskDebug('unique', '550e8400-e29b-41d4-a716-446655440000');
echo "Nombre de logs pour la tâche unique: {$count}\n";

// 5. Supprimer les logs
$service->clearTaskDebug('recurring', 'email-newsletter');
echo "Logs supprimés\n";

// 6. Vérifier la suppression
$remaining = $service->countTaskDebug('recurring', 'email-newsletter');
echo "Logs restants: {$remaining}\n"; // 0
```

## Voir aussi

- `TaskExecutionDebugServiceInterface` - Interface du service
- `TaskExecutionDebugRepository` - Repository des logs de débogage
- `TaskExecutionDebug` - Modèle Eloquent
- `TaskExecutionDebugRecord` - DTO des logs de débogage
- `UniqueTaskService` - Service des tâches uniques
- `RecurringTaskService` - Service des tâches récurrentes