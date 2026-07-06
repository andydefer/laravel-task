# TaskExecutionDebugRepository - Référence Technique

## Description

Repository de gestion des informations de débogage pour l'exécution des tâches. Il permet de stocker, récupérer, compter et nettoyer les traces d'exécution des tâches (uniques et récurrentes) pour faciliter le diagnostic et le monitoring.

## Hiérarchie / Implémentations

```
AbstractRepository<TaskExecutionDebug, TaskExecutionDebugRecord>
    └── TaskExecutionDebugRepository
            └── TaskExecutionDebugRepositoryInterface
```

## Rôle principal

Gérer le cycle de vie des données de débogage des tâches en :
- Enregistrant les informations d'exécution (statut, durée, erreur)
- Récupérant les historiques par alias, FQCN ou statut
- Comptant les enregistrements pour le monitoring
- Nettoyant les données obsolètes

## API / Méthodes publiques

### `findByAlias(TaskAliasVO $alias, ?LimitVO $limit = null): Collection`

Recherche les enregistrements de débogage par alias de tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, TaskExecutionDebug>` - Collection des modèles

**Tri :** Par `created_at` décroissant (les plus récents d'abord)

**Exemple :**
```php
// Tous les enregistrements
$records = $repository->findByAlias($alias);

// Les 10 plus récents
$records = $repository->findByAlias($alias, new LimitVO(10));
```

---

### `findByFqcn(TaskFqcnVO $fqcn, ?LimitVO $limit = null): Collection`

Recherche les enregistrements de débogage par classe de tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `TaskFqcnVO` | Nom complet de la classe de tâche |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, TaskExecutionDebug>` - Collection des modèles

**Exemple :**
```php
$fqcn = new TaskFqcnVO(MyTask::class);
$records = $repository->findByFqcn($fqcn, new LimitVO(50));
```

---

### `findByAliasAndFqcn(TaskAliasVO $alias, TaskFqcnVO $fqcn, ?LimitVO $limit = null): Collection`

Recherche les enregistrements par alias ET classe de tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$fqcn` | `TaskFqcnVO` | Nom complet de la classe de tâche |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, TaskExecutionDebug>` - Collection des modèles

**Exemple :**
```php
$records = $repository->findByAliasAndFqcn($alias, $fqcn, new LimitVO(5));
```

---

### `findByStatus(ExecutionStatus $status, ?LimitVO $limit = null): Collection`

Recherche les enregistrements par statut d'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$status` | `ExecutionStatus` | Statut d'exécution (SUCCEEDED/FAILED) |
| `$limit` | `LimitVO|null` | Nombre maximum de résultats (optionnel) |

**Retourne :** `Collection<int, TaskExecutionDebug>` - Collection des modèles

**Exemple :**
```php
$records = $repository->findByStatus(ExecutionStatus::FAILED, new LimitVO(100));
```

---

### `addDebug(TaskAliasVO $alias, TaskFqcnVO $fqcn, ExecutionStatus $status, DescriptionVO $info, ?MillisecondsVO $duration_ms = null, ?DescriptionVO $error = null): void`

Ajoute un enregistrement de débogage complet (avec début et fin).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$fqcn` | `TaskFqcnVO` | Classe de la tâche |
| `$status` | `ExecutionStatus` | Statut d'exécution |
| `$info` | `DescriptionVO` | Information descriptive |
| `$duration_ms` | `MillisecondsVO|null` | Durée d'exécution en millisecondes |
| `$error` | `DescriptionVO|null` | Message d'erreur (optionnel) |

**Exemple :**
```php
$repository->addDebug(
    alias: $alias,
    fqcn: new TaskFqcnVO(MyTask::class),
    status: ExecutionStatus::SUCCEEDED,
    info: new DescriptionVO('Task completed'),
    duration_ms: new MillisecondsVO(1500),
);
```

---

### `addDebugWithStart(TaskAliasVO $alias, TaskFqcnVO $fqcn, ExecutionStatus $status, DescriptionVO $info): void`

Ajoute un enregistrement avec uniquement le début (ended_at = null).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$fqcn` | `TaskFqcnVO` | Classe de la tâche |
| `$status` | `ExecutionStatus` | Statut d'exécution |
| `$info` | `DescriptionVO` | Information descriptive |

**Exemple :**
```php
$repository->addDebugWithStart(
    alias: $alias,
    fqcn: new TaskFqcnVO(MyTask::class),
    status: ExecutionStatus::SUCCEEDED,
    info: new DescriptionVO('Task started')
);
```

---

### `updateDebugWithEnd(TaskAliasVO $alias, TaskFqcnVO $fqcn, ExecutionStatus $status, ?DescriptionVO $error = null, ?MillisecondsVO $duration_ms = null): void`

Met à jour l'enregistrement le plus récent avec la fin de l'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$fqcn` | `TaskFqcnVO` | Classe de la tâche |
| `$status` | `ExecutionStatus` | Statut final d'exécution |
| `$error` | `DescriptionVO|null` | Message d'erreur (optionnel) |
| `$duration_ms` | `MillisecondsVO|null` | Durée d'exécution en millisecondes |

**Comportement :**
- Trouve l'enregistrement le plus récent pour (alias, fqcn) avec limite 1
- Met à jour ended_at avec le timestamp actuel
- Met à jour les données (info, error, duration_ms)

**Exemple :**
```php
$repository->updateDebugWithEnd(
    alias: $alias,
    fqcn: new TaskFqcnVO(MyTask::class),
    status: ExecutionStatus::FAILED,
    error: new DescriptionVO('Connection timeout'),
    duration_ms: new MillisecondsVO(3000)
);
```

---

### `clearByAlias(TaskAliasVO $alias): void`

Supprime tous les enregistrements de débogage pour un alias.

---

### `clearByFqcn(TaskFqcnVO $fqcn): void`

Supprime tous les enregistrements de débogage pour une classe de tâche.

---

### `countByAlias(TaskAliasVO $alias): CounterVO`

Compte les enregistrements pour un alias.

---

### `countByFqcn(TaskFqcnVO $fqcn): CounterVO`

Compte les enregistrements pour une classe de tâche.

---

### `countByStatus(ExecutionStatus $status): CounterVO`

Compte les enregistrements par statut.

---

### `modelToRecord(TaskExecutionDebug $model): TaskExecutionDebugRecord`

Convertit un modèle Eloquent en Record.

## Structure des données

### Modèle `TaskExecutionDebug`

| Propriété | Type | Description |
|-----------|------|-------------|
| `id` | `UuidVO` | Identifiant unique |
| `alias` | `TaskAliasVO` | Alias de la tâche |
| `fqcn` | `TaskFqcnVO` | Classe de la tâche |
| `status` | `ExecutionStatus` | Statut d'exécution |
| `started_at` | `Iso8601DateTimeVO` | Date/heure de début |
| `ended_at` | `Iso8601DateTimeVO|null` | Date/heure de fin (null si en cours) |
| `data` | `StrictDataObject` | Données supplémentaires (info, error, duration_ms) |

### Structure de `data`

```json
{
    "info": "Task executed successfully",
    "error": "Connection timeout",
    "duration_ms": 1500
}
```

## Cas d'utilisation

### Cas 1 : Enregistrement complet d'une exécution

**Problème :** Logger une exécution réussie avec sa durée.

```php
$alias = new TaskAliasVO('unique@abc-123');
$fqcn = new TaskFqcnVO(MyTask::class);

$start = microtime(true);
// ... exécution de la tâche ...
$duration = (microtime(true) - $start) * 1000;

$repository->addDebug(
    alias: $alias,
    fqcn: $fqcn,
    status: ExecutionStatus::SUCCEEDED,
    info: new DescriptionVO('Task completed successfully'),
    duration_ms: new MillisecondsVO((int) $duration)
);
```

---

### Cas 2 : Suivi d'une exécution longue

**Problème :** Suivre une tâche qui peut prendre du temps.

```php
// Début
$repository->addDebugWithStart(
    alias: $alias,
    fqcn: $fqcn,
    status: ExecutionStatus::SUCCEEDED,
    info: new DescriptionVO('Processing started')
);

// ... traitement long ...

// Fin
$repository->updateDebugWithEnd(
    alias: $alias,
    fqcn: $fqcn,
    status: ExecutionStatus::SUCCEEDED,
    duration_ms: new MillisecondsVO(5000)
);
```

---

### Cas 3 : Consultation des échecs

**Problème :** Voir toutes les tâches qui ont échoué.

```php
$failedRecords = $repository->findByStatus(ExecutionStatus::FAILED, new LimitVO(50));

foreach ($failedRecords as $record) {
    echo "Tâche : {$record->getAlias()->getValue()}\n";
    echo "Erreur : {$record->getData()->error}\n";
    echo "Date : {$record->getStartedAt()->getValue()}\n\n";
}
```

---

### Cas 4 : Nettoyage périodique

**Problème :** Supprimer les logs de débogage trop anciens.

```php
// Nettoyer les logs d'une tâche spécifique
$repository->clearByAlias($alias);

// Nettoyer les logs d'un type de tâche
$fqcn = new TaskFqcnVO(DeprecatedTask::class);
$repository->clearByFqcn($fqcn);
```

---

### Cas 5 : Dashboard de monitoring

**Problème :** Afficher les statistiques d'exécution.

```php
$total = $repository->countByFqcn($fqcn);
$success = $repository->countByStatus(ExecutionStatus::SUCCEEDED);
$failed = $repository->countByStatus(ExecutionStatus::FAILED);

echo "Total : {$total->getValue()}\n";
echo "Succès : {$success->getValue()}\n";
echo "Échecs : {$failed->getValue()}\n";
```

## Flux d'exécution

```
addDebug()
    │
    ├── 1. Création des données
    │   ├── info (obligatoire)
    │   ├── error (optionnel)
    │   └── duration_ms (optionnel)
    │
    ├── 2. Création du record
    │   ├── id = UuidVO::generate()
    │   ├── started_at = now
    │   ├── ended_at = now
    │   └── data = données
    │
    └── 3. Sauvegarde via create()

updateDebugWithEnd()
    │
    ├── 1. Recherche du dernier enregistrement
    │   └── findByAliasAndFqcn(alias, fqcn, limit=1)
    │
    ├── 2. Mise à jour des données
    │   ├── info = "Task executed successfully" ou "Task execution failed"
    │   ├── error (si fourni)
    │   └── duration_ms (si fourni)
    │
    └── 3. Sauvegarde via update()
```

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| Enregistrement inexistant pour updateDebugWithEnd | Ne fait rien (retour silencieux) |
| Filtres invalides | Les filtres sont ignorés |
| Données manquantes | Les champs optionnels sont null |

## Intégration

### Dépendances

- `AbstractRepository` : Base du repository
- `TaskExecutionDebug` : Modèle Eloquent
- `TaskExecutionDebugRecord` : Record de données

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `UniqueTaskRunner` | Enregistrement des exécutions |
| `RecurringTaskRunner` | Enregistrement des exécutions |
| `TaskExecutionDebugService` | API de haut niveau |
| `Processors` | Suivi des lots |

## Performance

- **Indexation** : Index sur `alias`, `fqcn`, `status`
- **Limites** : Utiliser `LimitVO` pour les gros volumes
- **Nettoyage** : Suppression en masse optimisée
- **Recommandation** : Nettoyer régulièrement les anciennes données

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\LimitVO;

$repository = new TaskExecutionDebugRepository();

// Alias et FQCN de la tâche
$alias = new TaskAliasVO('unique@abc-123');
$fqcn = new TaskFqcnVO(MyTask::class);

// 1. Enregistrement d'une exécution réussie
$repository->addDebug(
    alias: $alias,
    fqcn: $fqcn,
    status: ExecutionStatus::SUCCEEDED,
    info: new DescriptionVO('Task executed successfully'),
    duration_ms: new MillisecondsVO(2500)
);

// 2. Enregistrement d'une exécution en cours
$repository->addDebugWithStart(
    alias: $alias,
    fqcn: $fqcn,
    status: ExecutionStatus::SUCCEEDED,
    info: new DescriptionVO('Processing started')
);

// 3. Mise à jour de la fin
$repository->updateDebugWithEnd(
    alias: $alias,
    fqcn: $fqcn,
    status: ExecutionStatus::FAILED,
    error: new DescriptionVO('Connection timeout'),
    duration_ms: new MillisecondsVO(3000)
);

// 4. Consultation des enregistrements
$records = $repository->findByAlias($alias, new LimitVO(10));
foreach ($records as $record) {
    echo "Statut : {$record->getStatus()->value}\n";
    echo "Info : {$record->getData()->info}\n";
    if (isset($record->getData()->duration_ms)) {
        echo "Durée : {$record->getData()->duration_ms}ms\n";
    }
    echo "---\n";
}

// 5. Nettoyage
$repository->clearByAlias($alias);
```

## Voir aussi

- `TaskExecutionDebugService` - Service de haut niveau
- `TaskExecutionDebugRecord` - Structure de données
- `ExecutionStatus` - Statuts d'exécution
- `UniqueTaskRunner` - Utilisateur du repository
- `RecurringTaskRunner` - Utilisateur du repository
---