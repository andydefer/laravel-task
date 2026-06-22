# UniqueTaskService - Référence Technique

## Description

Service métier pour la gestion des tâches uniques. Fournit une API complète pour l'enregistrement, l'exécution, la gestion d'état, la modification et la recherche des tâches uniques.

## Hiérarchie / Implémentations

```
UniqueTaskServiceInterface
    └── UniqueTaskService
```

## Rôle principal

Ce service est le point d'entrée principal pour la gestion des tâches uniques. Il orchestre toutes les opérations métier :

1. **Enregistrement** des nouvelles tâches uniques avec génération d'UUID
2. **Exécution** des tâches en `PENDING` avec gestion des tentatives
3. **Gestion d'état** (annulation, reprogrammation)
4. **Modification** des paramètres (date d'exécution, période de grâce)
5. **Recherche** et consultation des tâches
6. **Suppression** des tâches
7. **Comptage** des tâches par statut

## API

### `register(string $taskClass, StrictDataObject $payload, ?UniqueTaskConfigInterface $config = null): TaskIdVO`

Enregistre une nouvelle tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskClass` | `string` | Classe de la tâche (doit étendre `AbstractUniqueTask`) |
| `$payload` | `StrictDataObject` | Données de la tâche |
| `$config` | `?UniqueTaskConfigInterface` | Configuration personnalisée (optionnelle) |

**Retourne :** `TaskIdVO` - UUID de la tâche créée

**Exceptions :** 
- `InvalidArgumentException` - Si la classe est invalide

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

### `cancel(TaskIdVO $taskId, ?string $reason = null): void`

Annule une tâche en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | UUID de la tâche |
| `$reason` | `?string` | Raison de l'annulation (optionnelle) |

**Comportement :**
- Marque la tâche comme `CANCELED`
- Log un événement `unique_task_cancelled`

**Conditions :** La tâche doit être en `PENDING`

**Exceptions :** 
- `RuntimeException` - Si la tâche n'existe pas ou n'est pas en `PENDING`

**Exemple :**
```php
$service->cancel(
    new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    'User requested cancellation'
);
// Statut → CANCELED
```

---

### `reschedule(TaskIdVO $taskId, Iso8601DateTimeVO $newScheduledAt): void`

Repousse la date d'exécution d'une tâche en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | UUID de la tâche |
| `$newScheduledAt` | `Iso8601DateTimeVO` | Nouvelle date planifiée |

**Comportement :**
- Met à jour `scheduled_at`
- Log un événement `unique_task_rescheduled`

**Conditions :** La tâche doit être en `PENDING`

**Exceptions :** 
- `RuntimeException` - Si la tâche n'existe pas ou n'est pas en `PENDING`

**Exemple :**
```php
$service->reschedule(
    new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    new Iso8601DateTimeVO(now()->addDays(2)->toIso8601String())
);
```

---

### `extendGracePeriod(TaskIdVO $taskId, int $extraSeconds): void`

Prolonge la période de grâce d'une tâche en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskId` | `TaskIdVO` | UUID de la tâche |
| `$extraSeconds` | `int` | Secondes supplémentaires à ajouter |

**Comportement :**
- Ajoute `$extraSeconds` à `grace_period_seconds`
- Log un événement `unique_task_grace_period_extended`

**Conditions :** 
- La tâche doit être en `PENDING`
- `$extraSeconds` doit être positif

**Exceptions :** 
- `InvalidArgumentException` - Si `$extraSeconds <= 0`
- `RuntimeException` - Si la tâche n'existe pas ou n'est pas en `PENDING`

**Exemple :**
```php
$service->extendGracePeriod(
    new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    3600 // 1 heure supplémentaire
);
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

### `findCanceled(?int $limit = null): array`

Récupère toutes les tâches annulées.

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

### `countCanceled(): int`

Compte le nombre de tâches annulées.

## Cas d'utilisation

### Cas 1 : Enregistrement et exécution d'une tâche unique

```php
$service = app(UniqueTaskService::class);

// 1. Enregistrer
$taskId = $service->register(
    SendWelcomeEmailTask::class,
    StrictDataObject::from(['email' => 'john@example.com']),
    new UniqueTaskConfig(
        alias: new TaskSignatureVO('welcome-email'),
        scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
        max_attempts: new CounterVO(3),
    )
);

// 2. Exécuter
$success = $service->run($taskId);
```

### Cas 2 : Gestion des tentatives

```php
$service = app(UniqueTaskService::class);
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');

// Première tentative
$success = $service->run($taskId);
if (!$success) {
    $task = $service->find($taskId);
    echo "Tentative {$task->attempts->value}/{$task->max_attempts->value}\n";
    
    // Deuxième tentative
    $success = $service->run($taskId);
    if (!$success) {
        // Si attempts >= max_attempts, statut → FAILED
        echo "La tâche a échoué après toutes les tentatives\n";
    }
}
```

### Cas 3 : Annulation d'une tâche

```php
$service = app(UniqueTaskService::class);
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');

// Annuler avec une raison
$service->cancel(
    $taskId,
    'User requested cancellation'
);

// La tâche est marquée CANCELED
// Un log 'unique_task_cancelled' est créé
```

### Cas 4 : Reprogrammation d'une tâche

```php
$service = app(UniqueTaskService::class);
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');

// Repousser l'exécution de 2 jours
$service->reschedule(
    $taskId,
    new Iso8601DateTimeVO(now()->addDays(2)->toIso8601String())
);
```

### Cas 5 : Prolongation de la période de grâce

```php
$service = app(UniqueTaskService::class);
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');

// Ajouter 1 heure de période de grâce
$service->extendGracePeriod($taskId, 3600);
```

### Cas 6 : Consultation des tâches

```php
$service = app(UniqueTaskService::class);

// Récupérer une tâche spécifique
$task = $service->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));

// Lister les tâches en attente
$pending = $service->findPending(10);

// Lister les tâches annulées
$cancelled = $service->findCanceled(5);

// Compter les tâches par statut
echo "PENDING: " . $service->countPending() . "\n";
echo "COMPLETED: " . $service->countCompleted() . "\n";
echo "FAILED: " . $service->countFailed() . "\n";
echo "CANCELED: " . $service->countCanceled() . "\n";
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
│  GESTION D'ÉTAT                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  cancel()        → PENDING → CANCELED + cancelled_at + log│   │
│  │  reschedule()    → Update scheduled_at + log              │   │
│  │  extendGracePeriod() → Update grace_period + log          │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  RECHERCHE                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  find()          → ?UniqueTaskRecord                       │   │
│  │  findPending()   → array<UniqueTaskRecord>                 │   │
│  │  findCompleted() → array<UniqueTaskRecord>                 │   │
│  │  findFailed()    → array<UniqueTaskRecord>                 │   │
│  │  findCanceled()  → array<UniqueTaskRecord>                 │   │
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
│  │  countCanceled()   → int                                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

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
│  cancel() → PENDING → CANCELED (quel que soit attempts)          │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cycle de vie d'une tâche unique

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche unique                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  PENDING ──────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ run() → succès                                             ││
│     ▼                                                             ││
│  COMPLETED                                                        ││
│                                                                     ││
│  PENDING ──────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ run() → échec (attempts < max_attempts)                    ││
│     ▼                                                             ││
│  PENDING (attempts + 1)                                           ││
│     │                                                             ││
│     │ run() → échec (attempts >= max_attempts)                   ││
│     ▼                                                             ││
│  FAILED                                                           ││
│                                                                     ││
│  PENDING ──────────────────────────────────────────────────────────┐│
│     │                                                             ││
│     │ cancel()                                                    ││
│     ▼                                                             ││
│  CANCELED                                                         ││
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Classe invalide | `InvalidArgumentException` | `Task must extend AbstractUniqueTask` |
| Tâche non trouvée | `RuntimeException` | `Task not found: {id}` |
| Annulation sur tâche non PENDING | `RuntimeException` | `Task '{id}' is not in PENDING state` |
| Reprogrammation sur tâche non PENDING | `RuntimeException` | `Task '{id}' is not in PENDING state` |
| Prolongation avec secondes négatives | `InvalidArgumentException` | `Extra seconds must be positive` |
| Prolongation sur tâche non PENDING | `RuntimeException` | `Task '{id}' is not in PENDING state` |

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskRepositoryInterface` | Accès aux données via Repository |
| `LoggerInterface` | Journalisation des événements |
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
| Laravel 12.x, 13.x, 14.x, 15.x | ✅ Complet |

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

// 3. Reprogrammer (si nécessaire)
$service->reschedule(
    $taskId,
    new Iso8601DateTimeVO(now()->addHours(2)->toIso8601String())
);
echo "Tâche reprogrammée\n";

// 4. Prolonger la période de grâce
$service->extendGracePeriod($taskId, 1800);
echo "Période de grâce prolongée de 30 minutes\n";

// 5. Exécuter
$success = $service->run($taskId);
echo $success ? "Exécution réussie\n" : "Exécution échouée\n";

// 6. Annuler (si besoin)
$service->cancel($taskId, 'Campagne annulée');
echo "Tâche annulée\n";

// 7. Compter les tâches
echo "Total: " . $service->count() . "\n";
echo "En attente: " . $service->countPending() . "\n";
echo "Terminées: " . $service->countCompleted() . "\n";
echo "En échec: " . $service->countFailed() . "\n";
echo "Annulées: " . $service->countCanceled() . "\n";

// 8. Supprimer
$service->delete($taskId);
echo "Tâche supprimée\n";
```

## Voir aussi

- `UniqueTaskServiceInterface` - Interface du service
- `UniqueTaskRepository` - Repository des tâches uniques
- `UniqueTaskConfig` - Configuration des tâches
- `UniqueTaskRecord` - DTO des tâches uniques
- `RecurringTaskService` - Service des tâches récurrentes