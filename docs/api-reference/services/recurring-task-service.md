# RecurringTaskService - Référence Technique

## Description

Service de gestion des tâches récurrentes. Orchestre l'enregistrement, l'exécution, les transitions d'état et le cycle de vie complet des tâches qui s'exécutent à intervalles réguliers.

## Hiérarchie

```
RecurringTaskService
    └── RecurringTaskServiceInterface
```

## Rôle principal

Fournit une couche d'abstraction métier pour les tâches récurrentes, coordonnant les opérations du repository, la journalisation et l'exécution des tâches à intervalles définis.

---

## API / Méthodes publiques

### `register(RecurringTaskFqcnVO $fqcn, StrictDataObject $payload, RecurringTaskConfigRecord $config): TaskAliasVO`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `RecurringTaskFqcnVO` | Classe de la tâche (doit étendre `AbstractRecurringTask`) |
| `$payload` | `StrictDataObject` | Données de la tâche |
| `$config` | `RecurringTaskConfigRecord` | Configuration (intervalle, dates de début/fin, tentatives) |

**Retourne :** `TaskAliasVO` - Alias de la tâche créée (format `recurring@{uuid}`)

**Exceptions :**
- `InvalidArgumentException` - Classe non trouvée ou n'étend pas `AbstractRecurringTask`

**Exemple :**
```php
$fqcn = new RecurringTaskFqcnVO(SyncUsersRecurringTask::class);
$payload = StrictDataObject::from(['batch_size' => 100]);
$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => 3600, // Toutes les heures
    'start_at' => new Iso8601DateTimeVO,
    'end_at' => (new Iso8601DateTimeVO)->addSeconds(604800), // 7 jours
    'max_attempts' => 3,
]);

$alias = $service->register($fqcn, $payload, $config);
echo "Tâche récurrente enregistrée : " . $alias->getValue();
```

---

### `run(TaskAliasVO $alias): TaskRunResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à exécuter |

**Retourne :** `TaskRunResultRecord` - Résultat de l'exécution

**Préconditions :** La tâche doit être en statut `PLAYING`

**Comportement :**
1. Recherche la tâche par alias
2. Vérifie le statut (`PLAYING` requis)
3. Vérifie que `end_at` n'est pas dépassé
4. Instancie et exécute la tâche
5. Met à jour `last_run_at` et les compteurs d'échecs

**Exemple :**
```php
$alias = new TaskAliasVO('recurring@550e8400-e29b-41d4-a716-446655440000');
$result = $service->run($alias);

if ($result->success) {
    echo "✅ Tâche récurrente exécutée\n";
} else {
    echo "❌ Échec : " . $result->error . "\n";
}
```

---

### `process(LimitVO $limit = new LimitVO, ?callable $onProgress = null, ?TaskFqcnVOCollection $fqcns = null): ProcessResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter |
| `$onProgress` | `callable|null` | Callback de progression (`function($processed, $total, $type, $record)`) |
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `ProcessResultRecord` - Résultats du traitement

**Comportement :**
1. Met à jour l'état des tâches via `findReadyToRun()`
2. Récupère les tâches en statut `PLAYING` prêtes à être exécutées
3. Exécute chaque tâche si l'intervalle est respecté
4. Compte les succès et échecs
5. Appelle le callback de progression si fourni

**Exemple :**
```php
$limit = new LimitVO(50);
$callback = function($processed, $total) {
    echo "Progression : {$processed}/{$total}\n";
};

$result = $service->process($limit, $callback);
echo "✅ Succès : " . $result->success->getValue() . "\n";
echo "❌ Échecs : " . $result->failed->getValue() . "\n";
echo "🏁 Tâches terminées : " . $result->finished->getValue() . "\n";
```

---

### `pause(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si la pause a réussi

**Préconditions :** La tâche doit être en statut `PLAYING`

**Effet :** Statut → `PAUSED`

**Exemple :**
```php
$service->pause($alias);
```

---

### `resume(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si la reprise a réussi

**Préconditions :** La tâche doit être en statut `PAUSED`

**Effet :** Statut → `PLAYING`

**Exemple :**
```php
$service->resume($alias);
```

---

### `finish(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si la tâche a été terminée

**Effet :** Statut → `FINISHED`, `finished_at` = maintenant

**Exemple :**
```php
$service->finish($alias);
```

---

### `cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$reason` | `DescriptionVO|null` | Raison de l'annulation |

**Retourne :** `bool` - `true` si l'annulation a réussi

**Effet :** Statut → `CANCELED`, `finished_at` = maintenant, `cancelled_at` = maintenant

**Exemple :**
```php
$reason = new DescriptionVO('Tâche annulée manuellement');
$service->cancel($alias, $reason);
```

---

### `advanceStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newStartAt` | `Iso8601DateTimeVO` | Nouvelle date de début |

**Retourne :** `bool` - `true` si la date a été avancée

**Exemple :**
```php
$newStart = new Iso8601DateTimeVO('2026-12-26T10:00:00+00:00');
$service->advanceStartAt($alias, $newStart);
```

---

### `postponeStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newStartAt` | `Iso8601DateTimeVO` | Nouvelle date de début |

**Retourne :** `bool` - `true` si la date a été repoussée

---

### `changeInterval(TaskAliasVO $alias, DurationVO $intervalSeconds): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$intervalSeconds` | `DurationVO` | Nouvel intervalle en secondes |

**Retourne :** `bool` - `true` si l'intervalle a été modifié

**Exemple :**
```php
$newInterval = new DurationVO(7200); // 2 heures
$service->changeInterval($alias, $newInterval);
```

---

### `extendEndAt(TaskAliasVO $alias, Iso8601DateTimeVO $newEndAt): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newEndAt` | `Iso8601DateTimeVO` | Nouvelle date de fin |

**Retourne :** `bool` - `true` si la date de fin a été prolongée

**Exemple :**
```php
$newEnd = (new Iso8601DateTimeVO)->addSeconds(86400); // +1 jour
$service->extendEndAt($alias, $newEnd);
```

---

### `find(TaskAliasVO $alias): ?RecurringTaskRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `RecurringTaskRecord|null` - Record de la tâche ou `null`

---

### Méthodes de recherche par statut

```php
public function findWaiting(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
public function findPlaying(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
public function findPaused(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
public function findFinished(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
public function findCanceled(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
```

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `RecurringTaskRecordCollection` - Collection des tâches du statut demandé

---

### `exists(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si la tâche existe

---

### `delete(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si la suppression a réussi

---

### Méthodes de comptage

Chaque méthode de comptage accepte un filtre FQCN optionnel :

```php
public function count(): CounterVO
public function countWaiting(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countPlaying(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countPaused(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countFinished(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countCanceled(?TaskFqcnVOCollection $fqcns = null): CounterVO
```

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `CounterVO` - Le nombre de tâches

**Exemple :**
```php
$fqcns = TaskFqcnVOCollection::from([SyncUsersRecurringTask::class]);
$count = $service->countPlaying($fqcns);
echo "Tâches actives : " . $count->getValue();
```

---

## Cas d'utilisation

### Cas 1 : Enregistrement et exécution d'une tâche récurrente

```php
// 1. Enregistrer une tâche récurrente
$fqcn = new RecurringTaskFqcnVO(CleanupLogsTask::class);
$payload = StrictDataObject::from(['days_to_keep' => 30]);
$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => 86400, // Tous les jours
    'start_at' => new Iso8601DateTimeVO,
    'end_at' => (new Iso8601DateTimeVO)->addSeconds(2592000), // 30 jours
    'max_attempts' => 3,
]);

$alias = $service->register($fqcn, $payload, $config);

// 2. Exécuter une occurrence
$result = $service->run($alias);

if (!$result->success) {
    echo "Erreur : " . $result->error . "\n";
}
```

### Cas 2 : Traitement par lot des tâches récurrentes

```php
$limit = new LimitVO(100);
$results = $service->process($limit);

echo "📊 Résultats du traitement :\n";
echo "  ✅ Succès : " . $results->success->getValue() . "\n";
echo "  ❌ Échecs : " . $results->failed->getValue() . "\n";
echo "  🏁 Terminées : " . $results->finished->getValue() . "\n";

foreach ($results->errors as $error) {
    echo "  - {$error->alias->getValue()} : {$error->description}\n";
}
```

### Cas 3 : Gestion des échecs et des tentatives

```php
// Une tâche qui échoue incrémente failed_attempts
$result = $service->run($alias);

if (!$result->success) {
    $record = $service->find($alias);
    if ($record) {
        $attempts = $record->failed_attempts->getValue();
        $maxAttempts = $record->max_failed_attempts->getValue();
        
        if ($attempts >= $maxAttempts) {
            echo "⚠️ Tâche annulée après {$attempts} échecs\n";
            // La tâche est automatiquement passée en CANCELED
        }
    }
}
```

### Cas 4 : Gestion du cycle de vie (pause/reprise/arrêt)

```php
// Pause d'une tâche
$service->pause($alias);
echo "⏸️ Tâche en pause\n";

// Reprise
$service->resume($alias);
echo "▶️ Tâche reprise\n";

// Arrêt prématuré
$service->finish($alias);
echo "🏁 Tâche terminée\n";
```

### Cas 5 : Modification des paramètres d'une tâche

```php
// Augmenter l'intervalle à 2 heures
$service->changeInterval($alias, new DurationVO(7200));

// Prolonger la date de fin de 7 jours
$newEnd = (new Iso8601DateTimeVO)->addSeconds(604800);
$service->extendEndAt($alias, $newEnd);

// Repousser la date de début
$newStart = (new Iso8601DateTimeVO)->addSeconds(3600);
$service->postponeStartAt($alias, $newStart);
```

---

## Flux d'exécution

### Processus d'enregistrement

```
1. register() est appelée
   ↓
2. Validation de la classe (existe, étend AbstractRecurringTask)
   ↓
3. Génération d'un UUID et d'un alias
   ↓
4. Création du record RecurringTaskRecord (statut WAITING)
   ↓
5. Persistance via le repository
   ↓
6. Retour de l'alias
```

### Processus d'exécution d'une occurrence

```
1. run() est appelée
   ↓
2. Recherche de la tâche par alias
   ↓
3. Vérifications :
   ├── Statut PLAYING ?
   └── end_at >= now ?
   ↓
4. Instanciation de la tâche
   ↓
5. Exécution (try/catch)
   ↓
6. updateAfterRun() :
   ├── Succès → failed_attempts = 0
   └── Échec → failed_attempts++
   ↓
7. Retour du résultat
```

### Processus de traitement par lot

```
1. process() est appelée
   ↓
2. findReadyToRun() :
   ├── freshState() : met à jour les états
   │   ├── WAITING → PLAYING (start_at <= now)
   │   ├── PLAYING → FINISHED (end_at <= now)
   │   └── PLAYING → CANCELED (failed_attempts >= max_failed_attempts)
   └── Sélection des tâches PLAYING avec intervalle respecté
   ↓
3. Pour chaque tâche :
   ├── shouldRunAgain() : vérifie last_run_at + interval <= now
   ├── run() (exécution)
   ├── Comptage succès/échecs
   └── Appel du callback de progression
   ↓
4. Retour du ProcessResultRecord
```

---

## Gestion des erreurs

| Situation | Log | Message |
|-----------|-----|---------|
| Classe non trouvée | - | `Task class "X" does not exist` |
| Classe invalide | - | `Class "X" must extend AbstractRecurringTask` |
| Tâche non trouvée | - | `Task not found` (dans le résultat) |
| Statut non PLAYING | - | `Task is not in PLAYING state (current: X)` |
| Tâche expirée | - | `Task has expired (end_at reached)` |
| Échec d'exécution | `error` | `recurring_task_cancelled` |
| `updateAfterRun` erreur | `error` | `recurring_task_update_after_run_error` |

---

## Performance

### Points d'attention

| Opération | Complexité | Risque |
|-----------|------------|--------|
| `process()` | O(n) | ⚠️ Dépend du nombre de tâches |
| `run()` | O(1) | ✅ Recherche par alias (indexé) |
| `countPlaying()` | O(1) | ✅ Indexé sur `status` |

### Recommandations

```php
// ✅ Utiliser un limit pour éviter de traiter trop de tâches
$results = $service->process(new LimitVO(100));

// ✅ Utiliser le filtre FQCN pour réduire le jeu de données
$fqcns = TaskFqcnVOCollection::from([$specificTaskClass]);
$results = $service->process($limit, $callback, $fqcns);

// ✅ Utiliser le callback de progression pour les longs traitements
$service->process($limit, function($processed, $total) {
    echo "Progression : " . round($processed/$total*100) . "%\n";
});
```

---

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 11+ | ✅ Complet |

---

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;

$service = app(RecurringTaskService::class);

// 1. Enregistrement d'une tâche récurrente
$fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
$payload = StrictDataObject::from(['data' => 'test']);
$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => 3600,
    'start_at' => (new Iso8601DateTimeVO)->addSeconds(3600),
    'end_at' => (new Iso8601DateTimeVO)->addSeconds(86400),
    'max_attempts' => 3,
]);

$alias = $service->register($fqcn, $payload, $config);
echo "✅ Tâche enregistrée : " . $alias->getValue() . "\n";

// 2. Vérification de l'existence
if ($service->exists($alias)) {
    echo "La tâche existe\n";
}

// 3. Exécution de la tâche (une occurrence)
$result = $service->run($alias);

if ($result->success) {
    echo "✅ Tâche exécutée\n";
    echo "⏱️ Temps : " . $result->execution_time_ms . "ms\n";
} else {
    echo "❌ Échec : " . $result->error . "\n";
}

// 4. Gestion du cycle de vie
$service->pause($alias);
echo "⏸️ Tâche en pause\n";

$service->resume($alias);
echo "▶️ Tâche reprise\n";

// 5. Modification des paramètres
$service->changeInterval($alias, new DurationVO(7200));
echo "📈 Intervalle modifié\n";

$newEnd = (new Iso8601DateTimeVO)->addSeconds(172800);
$service->extendEndAt($alias, $newEnd);
echo "📅 Date de fin prolongée\n";

// 6. Traitement par lot
$limit = new LimitVO(50);
$fqcns = TaskFqcnVOCollection::from([TestRecurringTask::class]);

$batchResult = $service->process(
    $limit,
    function($processed, $total) {
        echo "Progression : {$processed}/{$total}\n";
    },
    $fqcns
);

echo "📊 Résultats du batch :\n";
echo "  ✅ Succès : " . $batchResult->success->getValue() . "\n";
echo "  ❌ Échecs : " . $batchResult->failed->getValue() . "\n";
echo "  🏁 Terminées : " . $batchResult->finished->getValue() . "\n";

// 7. Statistiques
echo "📈 Statistiques :\n";
echo "  Total : " . $service->count()->getValue() . "\n";
echo "  WAITING : " . $service->countWaiting()->getValue() . "\n";
echo "  PLAYING : " . $service->countPlaying()->getValue() . "\n";
echo "  PAUSED : " . $service->countPaused()->getValue() . "\n";
echo "  FINISHED : " . $service->countFinished()->getValue() . "\n";
echo "  CANCELED : " . $service->countCanceled()->getValue() . "\n";

// 8. Annulation
$service->cancel($alias, new DescriptionVO('Annulé manuellement'));
echo "❌ Tâche annulée\n";
```

---

## Voir aussi

- `UniqueTaskService` - Service pour les tâches uniques
- `RecurringTaskRepository` - Repository pour les tâches récurrentes
- `AbstractRecurringTask` - Classe de base pour les tâches récurrentes
- `RecurringTaskConfigRecord` - Configuration des tâches récurrentes
- `RecurringTaskStatus` - Énumération des statuts
- `TaskRunResultRecord` - Résultat d'une exécution
- `ProcessResultRecord` - Résultat d'un traitement par lot
