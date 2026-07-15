# RecurringTaskService - Référence Technique

## Description

Le `RecurringTaskService` est le service métier central pour la gestion des tâches récurrentes. Il orchestre l'enregistrement, l'exécution, le cycle de vie et les transitions d'état des tâches qui s'exécutent à intervalles réguliers.

## Hiérarchie / Implémentations

```
RecurringTaskService (final)
    └── RecurringTaskServiceInterface
```

**Interfaces implémentées :**
- `RecurringTaskServiceInterface` - Contrat définissant toutes les opérations métier

## Rôle principal

Ce service agit comme la couche de orchestration métier pour les tâches récurrentes :

1. **Enregistrement** des nouvelles tâches récurrentes
2. **Exécution** des tâches avec gestion des tentatives et des erreurs
3. **Gestion du cycle de vie** : pause, reprise, fin, annulation
4. **Modification des paramètres** : intervalle, dates de début/fin
5. **Recherche et comptage** des tâches par statut
6. **Journalisation** des événements et des erreurs

## API / Méthodes publiques

### `register(RecurringTaskFqcnVO $fqcn, StrictDataObject $payload, RecurringTaskConfigRecord $config): TaskAliasVO`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `RecurringTaskFqcnVO` | Nom complet de la classe de la tâche |
| `$payload` | `StrictDataObject` | Données à transmettre à la tâche |
| `$config` | `RecurringTaskConfigRecord` | Configuration (intervalle, dates, tentatives) |

**Retourne :** `TaskAliasVO` - Alias unique généré pour la tâche

**Exceptions :** 
- `InvalidArgumentException` si la classe n'existe pas
- `InvalidArgumentException` si la classe n'étend pas `AbstractRecurringTask`

**Exemple :**
```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;

$service = app(RecurringTaskService::class);

$fqcn = new RecurringTaskFqcnVO(EmailNotificationTask::class);
$payload = StrictDataObject::from([
    'recipient' => 'user@example.com',
    'subject' => 'Daily Report',
]);

$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => new DurationVO(3600), // Toutes les heures
    'start_at' => new Iso8601DateTimeVO('2026-01-01 09:00:00'),
    'end_at' => new Iso8601DateTimeVO('2026-12-31 18:00:00'),
    'max_attempts' => new MaxFailedAttemptsVO(3),
]);

$alias = $service->register($fqcn, $payload, $config);
echo "✅ Tâche enregistrée : " . $alias->getValue() . "\n";
```

---

### `run(TaskAliasVO $alias): TaskRunResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à exécuter |

**Retourne :** `TaskRunResultRecord` - Résultat de l'exécution

**Comportement :**
1. Vérifie que la tâche existe
2. Vérifie que le statut est `PLAYING`
3. Vérifie que la tâche n'a pas expiré (`end_at` dépassé)
4. Instancie la tâche et exécute sa méthode `execute()`
5. Met à jour `last_run_at` via `updateAfterRun()`
6. Réinitialise ou incrémente `failed_attempts` selon le résultat

**Exemple :**
```php
<?php

$alias = new TaskAliasVO('recurring@550e8400-e29b-41d4-a716-446655440000');
$result = $service->run($alias);

if ($result->success) {
    echo "✅ Tâche exécutée avec succès\n";
    echo "Temps : " . $result->execution_time_ms->getValue() . "ms\n";
} else {
    echo "❌ Échec : " . $result->error . "\n";
}
```

---

### `process(LimitVO $limit = new LimitVO): ProcessResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter |

**Retourne :** `ProcessResultRecord` - Bilan du traitement

**Comportement :**
1. Récupère les tâches prêtes via `findReadyToRun()`
2. Enregistre les transitions d'état (WAITING→PLAYING, PLAYING→FINISHED)
3. Exécute chaque tâche récupérée
4. Agrège les résultats (succès, échecs, terminées)
5. Collecte les erreurs dans une collection

**Exemple :**
```php
<?php

$limit = new LimitVO(50);
$result = $service->process($limit);

echo "📊 Bilan :\n";
echo "   ✅ Succès : " . $result->success->getValue() . "\n";
echo "   ❌ Échecs : " . $result->failed->getValue() . "\n";
echo "   🏁 Terminées : " . $result->finished->getValue() . "\n";
echo "   ⚠️ Erreurs : " . $result->errors->count() . "\n";
```

---

### `pause(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à mettre en pause |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche doit être en statut `PLAYING`

**Transition :** `PLAYING` → `PAUSED`

---

### `resume(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à reprendre |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche doit être en statut `PAUSED`

**Transition :** `PAUSED` → `PLAYING`

---

### `finish(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à terminer |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche ne doit pas être en statut `CANCELED`

**Transition :** `PLAYING`/`WAITING`/`PAUSED` → `FINISHED`

---

### `cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à annuler |
| `$reason` | `DescriptionVO|null` | Raison de l'annulation |

**Retourne :** `bool` - `true` si l'opération a réussi

**Comportement :**
1. Marque la tâche comme `CANCELED`
2. Journalise l'annulation avec la raison

**Transition :** → `CANCELED`

---

### `advanceStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newStartAt` | `Iso8601DateTimeVO` | Nouvelle date de début |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche ne doit pas être en statut `CANCELED`

---

### `postponeStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool`

**Alias de `advanceStartAt()`** - Même comportement.

---

### `changeInterval(TaskAliasVO $alias, DurationVO $intervalSeconds): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$intervalSeconds` | `DurationVO` | Nouvel intervalle en secondes |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche ne doit pas être en statut `CANCELED`

---

### `extendEndAt(TaskAliasVO $alias, Iso8601DateTimeVO $newEndAt): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newEndAt` | `Iso8601DateTimeVO` | Nouvelle date de fin |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche ne doit pas être en statut `CANCELED`

---

### `find(TaskAliasVO $alias): ?RecurringTaskRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `RecurringTaskRecord|null` - Enregistrement de la tâche ou null

---

### `findWaiting(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`
### `findPlaying(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`
### `findPaused(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`
### `findFinished(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`
### `findCanceled(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `RecurringTaskRecordCollection` - Collection d'enregistrements filtrés par statut

---

### `exists(TaskAliasVO $alias): bool`

**Retourne :** `bool` - `true` si la tâche existe

---

### `delete(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à supprimer |

**Retourne :** `bool` - `true` si la suppression a réussi

---

### `count(): CounterVO`
### `countWaiting(): CounterVO`
### `countPlaying(): CounterVO`
### `countPaused(): CounterVO`
### `countFinished(): CounterVO`
### `countCanceled(): CounterVO`

**Retourne :** `CounterVO` - Nombre total de tâches ou par statut

---

## Cas d'utilisation

### Cas 1 : Enregistrement et exécution automatique d'une tâche récurrente

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$service = app(RecurringTaskService::class);

// 1. Enregistrer une tâche de nettoyage toutes les 30 minutes
$fqcn = new RecurringTaskFqcnVO(CleanupTask::class);
$payload = StrictDataObject::from([
    'max_age_hours' => 24,
    'batch_size' => 100,
]);

$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => new DurationVO(1800), // 30 min
    'start_at' => new Iso8601DateTimeVO('2026-01-15 10:00:00'),
    'max_attempts' => new MaxFailedAttemptsVO(5),
]);

$alias = $service->register($fqcn, $payload, $config);
echo "📝 Tâche enregistrée : " . $alias->getValue() . "\n";

// 2. Exécuter le traitement des tâches prêtes (à mettre dans un cron ou worker)
$result = $service->process(new LimitVO(10));

echo "📊 Résultat du traitement :\n";
echo "  ✅ Succès : " . $result->success->getValue() . "\n";
echo "  ❌ Échecs : " . $result->failed->getValue() . "\n";
echo "  🏁 Terminées : " . $result->finished->getValue() . "\n";
```

### Cas 2 : Gestion du cycle de vie (pause/reprise/annulation)

```php
<?php

use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;

$alias = new TaskAliasVO('recurring@550e8400-e29b-41d4-a716-446655440000');

// ✅ Mettre en pause pendant la maintenance
if ($service->pause($alias)) {
    echo "⏸️ Tâche mise en pause\n";
}

// ✅ Reprendre après la maintenance
if ($service->resume($alias)) {
    echo "▶️ Tâche reprise\n";
}

// ✅ Terminer la tâche prématurément
if ($service->finish($alias)) {
    echo "🏁 Tâche terminée\n";
}

// ✅ Annuler avec une raison
$reason = new DescriptionVO('Service désactivé pour la saison');
if ($service->cancel($alias, $reason)) {
    echo "🚫 Tâche annulée\n";
}
```

### Cas 3 : Modification des paramètres d'une tâche existante

```php
<?php

use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

$alias = new TaskAliasVO('recurring@550e8400-e29b-41d4-a716-446655440000');

// ✅ Modifier l'intervalle (toutes les heures → toutes les 2 heures)
$service->changeInterval($alias, new DurationVO(7200));

// ✅ Reporter le début à plus tard
$service->postponeStartAt($alias, new Iso8601DateTimeVO('2026-02-01 00:00:00'));

// ✅ Prolonger la date de fin
$service->extendEndAt($alias, new Iso8601DateTimeVO('2026-12-31 23:59:59'));

echo "✅ Paramètres mis à jour\n";
```

### Cas 4 : Supervision et monitoring des tâches

```php
<?php

use AndyDefer\Task\ValueObjects\LimitVO;

// 📊 Statistiques globales
echo "📊 Statistiques des tâches récurrentes :\n";
echo "   📦 Total : " . $service->count()->getValue() . "\n";
echo "   ⏳ En attente : " . $service->countWaiting()->getValue() . "\n";
echo "   ▶️  En cours : " . $service->countPlaying()->getValue() . "\n";
echo "   ⏸️  En pause : " . $service->countPaused()->getValue() . "\n";
echo "   🏁 Terminées : " . $service->countFinished()->getValue() . "\n";
echo "   🚫 Annulées : " . $service->countCanceled()->getValue() . "\n";

// 📋 Liste des tâches en cours avec détails
$playingTasks = $service->findPlaying(new LimitVO(20));
foreach ($playingTasks as $task) {
    echo sprintf(
        "   - %s (intervalle: %ds, début: %s)\n",
        $task->alias->getValue(),
        $task->interval_seconds->getValue(),
        $task->start_at->getValue()
    );
}
```

### Cas 5 : Gestion des erreurs avec récupération

```php
<?php

use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\LimitVO;

$alias = new TaskAliasVO('recurring@550e8400-e29b-41d4-a716-446655440000');

try {
    // Tenter d'exécuter la tâche
    $result = $service->run($alias);
    
    if (!$result->success) {
        // La tâche a échoué, vérifier le statut
        $task = $service->find($alias);
        if ($task && $task->status === RecurringTaskStatus::CANCELED) {
            echo "⚠️ Tâche annulée suite à trop d'échecs\n";
            
            // Réactiver avec de nouveaux paramètres
            $service->changeInterval($alias, new DurationVO(300)); // 5 min
            // Note: Le service n'a pas de méthode directe pour réactiver
            // Il faudrait repasser en PLAYING via le repository
        }
    }
} catch (Throwable $e) {
    echo "💥 Erreur critique : " . $e->getMessage() . "\n";
}

// Traitement en lot avec limite
$processResult = $service->process(new LimitVO(25));
if ($processResult->failed->getValue() > 0) {
    // Journaliser les erreurs pour investigation
    foreach ($processResult->errors as $error) {
        echo "❌ " . $error->alias->getValue() . ": " . $error->description->getValue() . "\n";
    }
}
```

## Flux d'exécution de `process()`

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskService::process()                  │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. repository->findReadyToRun($now, $limit)                        │
│     - Applique freshState() (transitions automatiques)              │
│     - Retourne les tâches PLAYING avec lockForUpdate()              │
│     - Retourne aussi le résultat des transitions                    │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. Enregistrer les transitions d'état                              │
│     - fresh_state->playing_to_finished → $finished                  │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. Pour chaque tâche dans $result->tasks                           │
│     - Vérifier si la tâche doit s'exécuter (shouldRunAgain)         │
│       * Statut PLAYING                                              │
│       * last_run_at null OU intervalle écoulé                       │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. Pour chaque tâche valide                                        │
│     - $this->run($alias)                                            │
│     - Agrège succès/échecs                                          │
│     - Collecte les erreurs                                          │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  5. Retourner ProcessResultRecord                                   │
│     - success, failed, finished, errors                             │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Comportement | Message/Code |
|-----------|--------------|--------------|
| Tâche non trouvée (`run`) | Retourne erreur | `'Task not found'` |
| Statut non `PLAYING` (`run`) | Retourne erreur | `'Task is not in PLAYING state (current: X)'` |
| Tâche expirée (`run`) | MoveToFinished | `'Task has expired (end_at reached)'` |
| Exception dans l'exécution | `updateAfterRun(false)` | Message d'erreur original |
| Pause sur statut non `PLAYING` | Retourne `false` | - |
| Reprise sur statut non `PAUSED` | Retourne `false` | - |
| Annulation | Journalise `recurring_task_cancelled` | - |

**Exceptions propagées :**
- `InvalidArgumentException` lors de l'enregistrement si la classe est invalide
- Les autres erreurs sont capturées et retournées via les codes de retour

## Performance

| Opération | Complexité | Description |
|-----------|-----------|-------------|
| `register()` | O(1) | Insertion d'une seule tâche |
| `run()` | O(1) + exécution tâche | Récupération + exécution |
| `process()` | O(n) | n = nombre de tâches traitées (limit) |
| `find*()` | O(n) | n = limit ou nombre de résultats |
| `count*()` | O(1) | COUNT query |

**Recommandations :**
- Utiliser `process()` avec un `limit` raisonnable (10-100) pour éviter les batchs trop gros
- Les `find*()` avec de grands `limit` peuvent impacter la mémoire
- Les `count*()` sont légers et peuvent être utilisés fréquemment

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.0 | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 9 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$service = app(RecurringTaskService::class);

// ============================================================
// 1. ENREGISTREMENT D'UNE TÂCHE
// ============================================================
echo "📝 Enregistrement d'une tâche de nettoyage...\n";

$fqcn = new RecurringTaskFqcnVO(CleanupTask::class);
$payload = StrictDataObject::from([
    'max_age' => 30,
    'unit' => 'days',
]);

$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => new DurationVO(3600), // 1 heure
    'start_at' => new Iso8601DateTimeVO('2026-01-15 08:00:00'),
    'end_at' => new Iso8601DateTimeVO('2026-12-31 23:59:59'),
    'max_attempts' => new MaxFailedAttemptsVO(5),
]);

$alias = $service->register($fqcn, $payload, $config);
echo "✅ Alias : " . $alias->getValue() . "\n\n";

// ============================================================
// 2. PROCESSUS PRINCIPAL (TRAITEMENT DES TÂCHES PRÊTES)
// ============================================================
echo "🔄 Traitement des tâches prêtes...\n";

$limit = new LimitVO(20);
$result = $service->process($limit);

echo "📊 Résultats :\n";
echo "   ✅ Succès : " . $result->success->getValue() . "\n";
echo "   ❌ Échecs : " . $result->failed->getValue() . "\n";
echo "   🏁 Terminées : " . $result->finished->getValue() . "\n";
echo "   ⚠️  Erreurs : " . $result->errors->count() . "\n\n";

if ($result->errors->count() > 0) {
    echo "Détail des erreurs :\n";
    foreach ($result->errors as $error) {
        echo "   ❌ " . $error->alias->getValue() . "\n";
        echo "      " . $error->description->getValue() . "\n";
    }
    echo "\n";
}

// ============================================================
// 3. GESTION DU CYCLE DE VIE
// ============================================================
echo "⏯️  Gestion du cycle de vie...\n";

// Mettre en pause
if ($service->pause($alias)) {
    echo "⏸️ Tâche mise en pause\n";
    sleep(1);
}

// Reprendre
if ($service->resume($alias)) {
    echo "▶️ Tâche reprise\n\n";
}

// ============================================================
// 4. MODIFICATION DES PARAMÈTRES
// ============================================================
echo "⚙️ Modification des paramètres...\n";

// Changer l'intervalle
$service->changeInterval($alias, new DurationVO(1800)); // 30 min
echo "   ✅ Intervalle modifié : 30 minutes\n";

// Prolonger la date de fin
$service->extendEndAt($alias, new Iso8601DateTimeVO('2027-12-31 23:59:59'));
echo "   ✅ Date de fin prolongée jusqu'au 31/12/2027\n\n";

// ============================================================
// 5. SUPERVISION
// ============================================================
echo "📊 Supervision :\n";
echo "   📦 Total : " . $service->count()->getValue() . "\n";
echo "   ⏳ En attente : " . $service->countWaiting()->getValue() . "\n";
echo "   ▶️  En cours : " . $service->countPlaying()->getValue() . "\n";
echo "   🏁 Terminées : " . $service->countFinished()->getValue() . "\n";
echo "   🚫 Annulées : " . $service->countCanceled()->getValue() . "\n";

// ============================================================
// 6. RÉCUPÉRATION D'UNE TÂCHE SPÉCIFIQUE
// ============================================================
$task = $service->find($alias);
if ($task !== null) {
    echo "\n📋 Détail de la tâche :\n";
    echo "   Alias : " . $task->alias->getValue() . "\n";
    echo "   FQCN : " . $task->fqcn->getValue() . "\n";
    echo "   Statut : " . $task->status->value . "\n";
    echo "   Intervalle : " . $task->interval_seconds->getValue() . "s\n";
    echo "   Début : " . $task->start_at->getValue() . "\n";
    echo "   Fin : " . ($task->end_at?->getValue() ?? 'Aucune') . "\n";
    echo "   Tentatives échouées : " . $task->failed_attempts->getValue() . "\n";
}

// ============================================================
// 7. ANNULATION
// ============================================================
$reason = new DescriptionVO('Migration vers le nouveau système');
if ($service->cancel($alias, $reason)) {
    echo "\n🚫 Tâche annulée avec raison : " . $reason->getValue() . "\n";
}
```

## Voir aussi
- `RecurringTaskRepository` - Dépôt utilisé pour les opérations de base de données
- `RecurringTaskServiceInterface` - Interface du service
- `AbstractRecurringTask` - Classe abstraite à étendre pour les tâches récurrentes
- `RecurringTaskRecord` - Data Transfer Object
- `TaskRunResultRecord` - Résultat d'exécution
- `ProcessResultRecord` - Résultat du traitement en lot
- `UniqueTaskService` - Service similaire pour les tâches uniques