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
3. **Gestion d'état** (pause, reprise, terminaison, annulation)
4. **Modification** des paramètres (intervalle, date de début, date de fin)
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

### `cancel(TaskSignatureVO $alias, ?string $reason = null): void`

Annule une tâche récurrente avec une raison.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |
| `$reason` | `?string` | Raison de l'annulation |

**Comportement :**
- Marque la tâche comme `FINISHED`
- Enregistre `cancelled_at`
- Log un événement `recurring_task_cancelled`

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->cancel(
    new TaskSignatureVO('email-newsletter'),
    'Campaign cancelled due to budget constraints'
);
```

---

### `advanceStartAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newStartAt): void`

Avance la date de début d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |
| `$newStartAt` | `Iso8601DateTimeVO` | Nouvelle date de début |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->advanceStartAt(
    new TaskSignatureVO('email-newsletter'),
    new Iso8601DateTimeVO(now()->addHours(2)->toIso8601String())
);
```

---

### `postponeStartAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newStartAt): void`

Repousse la date de début d'une tâche (alias de `advanceStartAt`).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |
| `$newStartAt` | `Iso8601DateTimeVO` | Nouvelle date de début |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->postponeStartAt(
    new TaskSignatureVO('email-newsletter'),
    new Iso8601DateTimeVO(now()->addDays(2)->toIso8601String())
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

### `extendEndAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newEndAt): void`

Prolonge la date de fin d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskSignatureVO` | Alias de la tâche |
| `$newEndAt` | `Iso8601DateTimeVO` | Nouvelle date de fin |

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$service->extendEndAt(
    new TaskSignatureVO('email-newsletter'),
    new Iso8601DateTimeVO(now()->addMonths(3)->toIso8601String())
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

### `findCancelled(?int $limit = null): array`

Récupère toutes les tâches annulées.

**Note :** Filtre les tâches `FINISHED` qui ont un `cancelled_at` non null

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

### `countCancelled(): int`

Compte le nombre de tâches annulées.

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

### Cas 3 : Annulation d'une tâche

```php
$service = app(RecurringTaskService::class);
$alias = new TaskSignatureVO('email-newsletter');

// Annuler avec une raison
$service->cancel(
    $alias,
    'Campaign cancelled due to budget constraints'
);

// La tâche est marquée FINISHED avec cancelled_at renseigné
// Un log 'recurring_task_cancelled' est créé
```

### Cas 4 : Modification des paramètres d'une tâche

```php
$service = app(RecurringTaskService::class);
$alias = new TaskSignatureVO('email-newsletter');

// Changer l'intervalle de 1h à 2h
$service->changeInterval($alias, 7200);

// Repousser le démarrage
$service->postponeStartAt(
    $alias, 
    new Iso8601DateTimeVO(now()->addDays(7)->toIso8601String())
);

// Avancer le démarrage
$service->advanceStartAt(
    $alias, 
    new Iso8601DateTimeVO(now()->addHours(2)->toIso8601String())
);

// Prolonger la date de fin
$service->extendEndAt(
    $alias,
    new Iso8601DateTimeVO(now()->addMonths(3)->toIso8601String())
);
```

### Cas 5 : Consultation des tâches

```php
$service = app(RecurringTaskService::class);

// Récupérer une tâche spécifique
$task = $service->find(new TaskSignatureVO('email-newsletter'));

// Lister les tâches en attente
$waiting = $service->findWaiting(10);

// Lister les tâches annulées
$cancelled = $service->findCancelled(5);

// Compter les tâches par statut
echo "WAITING: " . $service->countWaiting() . "\n";
echo "PLAYING: " . $service->countPlaying() . "\n";
echo "PAUSED: " . $service->countPaused() . "\n";
echo "FINISHED: " . $service->countFinished() . "\n";
echo "CANCELLED: " . $service->countCancelled() . "\n";
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
│  │  cancel()  → * → FINISHED + cancelled_at + log            │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  MODIFICATION                                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  advanceStartAt()   → updateRaw('start_at')                │   │
│  │  postponeStartAt()  → updateRaw('start_at')                │   │
│  │  changeInterval()   → updateRaw('interval_seconds')        │   │
│  │  extendEndAt()      → updateRaw('end_at')                  │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  RECHERCHE                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  find()          → ?RecurringTaskRecord                    │   │
│  │  findWaiting()   → array<RecurringTaskRecord>              │   │
│  │  findPlaying()   → array<RecurringTaskRecord>              │   │
│  │  findPaused()    → array<RecurringTaskRecord>              │   │
│  │  findFinished()  → array<RecurringTaskRecord>              │   │
│  │  findCancelled() → array<RecurringTaskRecord>              │   │
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
│  │  countCancelled()  → int                                    │   │
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

## Nouveautés

### Méthode `cancel()`

Ajoutée pour permettre l'annulation explicite d'une tâche avec raison :
- Marque la tâche comme `FINISHED`
- Renseigne `cancelled_at`
- Logge l'événement `recurring_task_cancelled`

### Méthode `extendEndAt()`

Permet de prolonger la date de fin d'une tâche existante.

### Méthode `findCancelled()`

Permet de lister les tâches annulées.

### Méthode `countCancelled()`

Compte les tâches annulées.

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

// 6. Annuler (si besoin)
$service->cancel($alias, 'Maintenance programmée');

// 7. Compter les tâches
echo "Total: " . $service->count() . "\n";
echo "En attente: " . $service->countWaiting() . "\n";
echo "En cours: " . $service->countPlaying() . "\n";
echo "Annulées: " . $service->countCancelled() . "\n";

// 8. Supprimer
$service->delete($alias);
echo "Tâche supprimée\n";
```

## Voir aussi

- `RecurringTaskServiceInterface` - Interface du service
- `RecurringTaskRepository` - Repository des tâches récurrentes
- `RecurringTaskConfig` - Configuration des tâches
- `RecurringTaskRecord` - DTO des tâches récurrentes
- `UniqueTaskService` - Service des tâches uniques