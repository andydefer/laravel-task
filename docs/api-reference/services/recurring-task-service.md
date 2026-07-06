# RecurringTaskService - Référence Technique

## Description

Service central de gestion des tâches récurrentes. Il orchestre l'enregistrement, l'exécution, la transition d'états et le traitement par lots des tâches récurrentes.

## Hiérarchie / Implémentations

```
RecurringTaskServiceInterface
    └── RecurringTaskService
```

## Rôle principal

Fournir une API complète pour la gestion du cycle de vie des tâches récurrentes :
- Enregistrement de nouvelles tâches
- Exécution individuelle ou par lots
- Gestion des états (WAITING → PLAYING → PAUSED → FINISHED → CANCELED)
- Modification des paramètres (intervalle, date de début/fin)
- Recherche et comptage des tâches

## API / Méthodes publiques

### `register(RecurringTaskFqcnVO $fqcn, StrictDataObject $payload, RecurringTaskConfigRecord $config): TaskAliasVO`

Enregistre une nouvelle tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `RecurringTaskFqcnVO` | Nom complet de la classe de la tâche |
| `$payload` | `StrictDataObject` | Données d'entrée de la tâche |
| `$config` | `RecurringTaskConfigRecord` | Configuration (intervalle, dates, tentatives) |

**Retourne :** `TaskAliasVO` - Alias unique de la tâche créée

**Exceptions :** `InvalidArgumentException` - Si la classe n'existe pas ou n'hérite pas de `AbstractRecurringTask`

**Exemple :**
```php
$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => 3600,
    'start_at' => '2026-01-01T00:00:00+00:00',
    'end_at' => '2026-12-31T23:59:59+00:00',
    'max_attempts' => 3,
]);

$alias = $service->register(
    new RecurringTaskFqcnVO(MyRecurringTask::class),
    StrictDataObject::from(['key' => 'value']),
    $config
);
```

---

### `run(TaskAliasVO $alias): TaskRunResultRecord`

Exécute une tâche récurrente spécifique par son alias.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à exécuter |

**Retourne :** `TaskRunResultRecord` - Résultat de l'exécution (succès/échec, temps, erreur)

**Exemple :**
```php
$result = $service->run($alias);

if ($result->success) {
    echo "Tâche exécutée avec succès en {$result->execution_time_ms}ms";
} else {
    echo "Échec : {$result->error}";
}
```

---

### `process(LimitVO $limit = new LimitVO): ProcessResultRecord`

Traite un lot de tâches récurrentes prêtes à être exécutées.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter |

**Retourne :** `ProcessResultRecord` - Résumé du traitement (succès, échecs, finis)

**Exemple :**
```php
$result = $service->process(new LimitVO(50));

echo "Succès : {$result->success->getValue()}\n";
echo "Échecs : {$result->failed->getValue()}\n";
echo "Finis : {$result->finished->getValue()}\n";
```

---

### `pause(TaskAliasVO $alias): bool`

Met en pause une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à mettre en pause |

**Retourne :** `bool` - `true` si la pause a réussi, `false` sinon

**Exemple :**
```php
if ($service->pause($alias)) {
    echo "Tâche mise en pause";
}
```

---

### `resume(TaskAliasVO $alias): bool`

Reprend l'exécution d'une tâche récurrente en pause.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à reprendre |

**Retourne :** `bool` - `true` si la reprise a réussi, `false` sinon

---

### `finish(TaskAliasVO $alias): bool`

Marque une tâche comme terminée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à terminer |

**Retourne :** `bool` - `true` si la tâche a été marquée comme terminée, `false` sinon

---

### `cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool`

Annule une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à annuler |
| `$reason` | `DescriptionVO|null` | Raison de l'annulation (optionnelle) |

**Retourne :** `bool` - `true` si l'annulation a réussi, `false` sinon

---

### `advanceStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool`

Avance la date de début d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newStartAt` | `Iso8601DateTimeVO` | Nouvelle date de début |

**Retourne :** `bool` - `true` si la mise à jour a réussi, `false` sinon

---

### `postponeStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool`

Reporte la date de début d'une tâche (alias de `advanceStartAt`).

---

### `changeInterval(TaskAliasVO $alias, DurationVO $intervalSeconds): bool`

Modifie l'intervalle d'exécution d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$intervalSeconds` | `DurationVO` | Nouvel intervalle en secondes |

**Retourne :** `bool` - `true` si la modification a réussi, `false` sinon

---

### `extendEndAt(TaskAliasVO $alias, Iso8601DateTimeVO $newEndAt): bool`

Prolonge la date de fin d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newEndAt` | `Iso8601DateTimeVO` | Nouvelle date de fin |

**Retourne :** `bool` - `true` si la prolongation a réussi, `false` sinon

---

### `find(TaskAliasVO $alias): ?RecurringTaskRecord`

Recherche une tâche par son alias.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `RecurringTaskRecord|null` - Le record de la tâche ou `null` si non trouvée

---

### `findWaiting(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`

Retourne les tâches en attente (statut WAITING).

---

### `findPlaying(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`

Retourne les tâches en cours d'exécution (statut PLAYING).

---

### `findPaused(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`

Retourne les tâches en pause (statut PAUSED).

---

### `findFinished(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`

Retourne les tâches terminées (statut FINISHED).

---

### `findCanceled(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection`

Retourne les tâches annulées (statut CANCELED).

---

### `exists(TaskAliasVO $alias): bool`

Vérifie si une tâche existe.

---

### `delete(TaskAliasVO $alias): bool`

Supprime une tâche.

---

### `count(): CounterVO`

Compte toutes les tâches.

---

### `countWaiting(): CounterVO`, `countPlaying(): CounterVO`, etc.

Compteurs par statut.

## Cycle de vie des états

```
WAITING ──(start_at atteint)──▶ PLAYING
                                  │
                      ┌───────────┼───────────┐
                      │           │           │
                      ▼           ▼           ▼
                   PAUSED     FINISHED    CANCELED
                      │           │           │
                      └───────────┘           │
                                  │           │
                                  ▼           ▼
                              (terminal)  (terminal)
```

## Cas d'utilisation

### Cas 1 : Enregistrement d'une tâche récurrente

**Problème :** Créer une tâche qui s'exécute toutes les heures.

```php
$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => 3600,
    'start_at' => Carbon::now()->addMinutes(5)->toIso8601String(),
    'max_attempts' => 3,
]);

$alias = $service->register(
    new RecurringTaskFqcnVO(CleanupTask::class),
    StrictDataObject::from(['max_items' => 100]),
    $config
);
```

---

### Cas 2 : Traitement par lots

**Problème :** Traiter toutes les tâches récurrentes prêtes.

```php
$result = $service->process(new LimitVO(100));

if ($result->failed->isPositive()) {
    foreach ($result->errors as $error) {
        echo "Erreur : {$error->description}\n";
    }
}
```

---

### Cas 3 : Mise en pause et reprise

**Problème :** Mettre en pause une tâche pendant la maintenance.

```php
// Pause
if ($service->pause($alias)) {
    echo "Tâche mise en pause\n";
}

// ... maintenance ...

// Reprise
if ($service->resume($alias)) {
    echo "Tâche reprise\n";
}
```

---

### Cas 4 : Modification de l'intervalle

**Problème :** Une tâche doit passer de toutes les heures à toutes les 30 minutes.

```php
$service->changeInterval($alias, new DurationVO(1800));
```

---

### Cas 5 : Annulation avec notification

**Problème :** Annuler une tâche et enregistrer la raison.

```php
$reason = new DescriptionVO('Projet terminé');
$service->cancel($alias, $reason);
```

## Gestion des erreurs

| Situation | Exception/Retour | Message |
|-----------|------------------|---------|
| Classe de tâche inexistante | `InvalidArgumentException` | `Task class "X" does not exist.` |
| Classe non valide | `InvalidArgumentException` | `Class "X" must extend AbstractRecurringTask` |
| Tâche non trouvée | `false` / `null` | - |
| Statut invalide pour l'opération | `false` | - |
| Échec d'exécution | `TaskRunResultRecord` avec `success = false` | Message d'erreur de l'exception |

## Intégration

### Dépendances

- `RecurringTaskRepositoryInterface` : Accès aux données
- `LoggerInterface` : Logging
- `HydrationService` : Hydratation des objets
- `Application` : Conteneur Laravel

### Points d'extension

- Le repository peut être remplacé pour utiliser un stockage différent
- Les loggers peuvent être personnalisés

## Performance

- **Recherches** : Indexées sur `alias` et `status`
- **Traitement par lots** : Utilise des requêtes optimisées
- **Transactions** : Les transitions d'état sont atomiques
- **Recommandation** : Utiliser `LimitVO` pour les gros volumes

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\LimitVO;

/** @var RecurringTaskService $service */
$service = app(RecurringTaskService::class);

// Enregistrement
$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => 3600,
    'max_attempts' => 3,
]);

$alias = $service->register(
    new RecurringTaskFqcnVO(BackupTask::class),
    StrictDataObject::from(['path' => '/backup']),
    $config
);

// Vérification
if ($service->exists($alias)) {
    echo "Tâche créée : {$alias->getValue()}\n";
}

// Traitement
$result = $service->process(new LimitVO(10));
echo "Traitement : {$result->success->getValue()} succès, {$result->failed->getValue()} échecs\n";

// Modification de l'intervalle
$service->changeInterval($alias, new DurationVO(7200));

// Annulation
$service->cancel($alias, new DescriptionVO('Maintenance planifiée'));
```

## Voir aussi

- `UniqueTaskService` - Service de tâches uniques
- `RecurringTaskRepositoryInterface` - Repository des tâches récurrentes
- `RecurringTaskRecord` - Structure de données
- `RecurringTaskStatus` - États des tâches
---