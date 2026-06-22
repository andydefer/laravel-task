# ProcessTasksDirective - Référence Technique

## Description

Directive CLI qui exécute en un seul lot toutes les tâches en attente (uniques et/ou récurrentes) avec support des formats de sortie texte et JSON.

## Hiérarchie

```
AbstractDirective
    └── ProcessTasksDirective
```

## Rôle principal

Orchestrer l'exécution des tâches en attente en un seul passage (batch) avec :
- Filtrage par type de tâche (`--unique-only`, `--recurring-only`)
- Limitation du nombre de tâches par cycle (`--limit`)
- Sortie formatée (`--format=text` ou `--format=json`)
- Affichage détaillé en mode verbose (`--verbose`)

## Options disponibles

| Option | Type | Description | Par défaut |
|--------|------|-------------|------------|
| `--unique-only` | `flag` | Traite uniquement les tâches uniques | `false` |
| `--recurring-only` | `flag` | Traite uniquement les tâches récurrentes | `false` |
| `--verbose` | `flag` | Affiche les détails des erreurs | `false` |
| `--limit` | `int` | Nombre maximum de tâches à traiter | `null` (illimité) |
| `--format` | `string` | Format de sortie (`text` ou `json`) | `text` |

## API / Méthodes publiques

### `getSignature(): string`

**Retourne :** `string` - Signature de la directive

**Exemple :**
```php
$signature = $directive->getSignature();
// 'process-tasks {--unique-only} {--recurring-only} {--verbose} {--limit=} {--format=}'
```

---

### `getDescription(): string`

**Retourne :** `string` - Description de la directive

**Exemple :**
```php
$description = $directive->getDescription();
// 'Process all pending tasks in a single batch (no polling, no waiting)'
```

---

### `getAliases(): StringTypedCollection`

**Retourne :** `StringTypedCollection` - Collection des alias

**Exemple :**
```php
$aliases = $directive->getAliases();
// Collection contenant 'task-process' et 'tasks-process'
```

---

### `execute(): ExitCode`

**Retourne :** `ExitCode` - `SUCCESS` (0) ou `FAILURE` (1)

**Description :** Point d'entrée principal de la directive.

## Flux d'exécution

```
execute()
    │
    ├── validateOptions()
    │   ├── --unique-only + --recurring-only → INVALID_ARGUMENT
    │   ├── --limit ≤ 0 → INVALID_ARGUMENT
    │   └── --format invalide → INVALID_ARGUMENT
    │
    ├── Détermination du mode
    │   ├── --unique-only → mode UNIQUE
    │   ├── --recurring-only → mode RECURRING
    │   └── Aucun → mode FULL
    │
    ├── Récupération des services
    │   ├── UniqueTaskServiceInterface
    │   └── RecurringTaskServiceInterface
    │
    └── Exécution selon le mode
        ├── UNIQUE → processUniqueOnly() → displayUniqueResults() ou outputUniqueJsonStruct()
        ├── RECURRING → processRecurringOnly() → displayRecurringResults() ou outputRecurringJsonStruct()
        └── FULL → processFull() → displayFullResults() ou outputFullJsonStruct()
```

## Cas d'utilisation

### Cas 1 : Traiter toutes les tâches en mode texte

```bash
./vendor/bin/directive process-tasks
```

**Sortie :**
```
=== Batch Results ===
  Unique:    ✅ 3, ❌ 0
  Recurring: ✅ 2, ❌ 1
  Total:     ✅ 5, ❌ 1, 📦 6
  Has failures: Yes
```

### Cas 2 : Traiter uniquement les tâches uniques en JSON

```bash
./vendor/bin/directive process-tasks --unique-only --format=json
```

**Sortie :**
```json
{
  "started_at": "2026-06-22T14:30:00+00:00",
  "ended_at": "2026-06-22T14:30:05+00:00",
  "duration_ms": 5000,
  "success": 3,
  "failed": 0,
  "total": 3,
  "errors": [],
  "has_failures": false
}
```

### Cas 3 : Limiter le nombre de tâches

```bash
./vendor/bin/directive process-tasks --limit=10 --verbose
```

**Sortie :**
```
Processing tasks...
Limit: 10 tasks

=== Batch Results ===
  Unique:    ✅ 7, ❌ 2
  Recurring: ✅ 1, ❌ 0
  Total:     ✅ 8, ❌ 2, 📦 10
  Has failures: Yes

=== Failed Tasks ===
  Unique tasks:
    ❌ failing-task: Task execution failed
```

### Cas 4 : Exécution en mode full avec JSON

```bash
./vendor/bin/directive process-tasks --format=json
```

**Sortie :** Structure JSON plate

```json
{
  "started_at": "...",
  "ended_at": "...",
  "duration_ms": 1234,
  "success": 5,
  "failed": 1,
  "total": 6,
  "errors": [
    {
      "alias": "failing-task",
      "fqcn": "App\\Tasks\\FailingTask",
      "error": "Task execution failed",
      "context": "Unique task failed (attempts: 2/3)"
    }
  ],
  "has_failures": true
}
```

## Structure des données

### Mode UNIQUE / RECURRING

```json
{
  "started_at": "string (ISO8601)",
  "ended_at": "string (ISO8601)",
  "duration_ms": "int",
  "success": "int",
  "failed": "int",
  "total": "int",
  "errors": [
    {
      "alias": "string",
      "fqcn": "string",
      "error": "string",
      "context": "string|null"
    }
  ],
  "has_failures": "bool"
}
```

### Mode FULL

**Même structure que les modes UNIQUE/RECURRING**, mais les valeurs `success`, `failed` et `total` sont la somme des tâches uniques et récurrentes.

## Gestion des erreurs

| Situation | Code de retour | Message |
|-----------|----------------|---------|
| `--unique-only` et `--recurring-only` ensemble | `ExitCode::INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| `--limit ≤ 0` | `ExitCode::INVALID_ARGUMENT` | `Limit must be a positive integer` |
| `--format` invalide | `ExitCode::INVALID_ARGUMENT` | `Format must be "text" or "json"` |
| Échec d'une tâche | `ExitCode::FAILURE` | Affiché dans les erreurs |
| Laravel non disponible | `RuntimeException` | `Laravel container is not available. Task processing requires Laravel.` |

## Intégration

### Dépendances

| Service | Interface | Rôle |
|---------|-----------|------|
| `UniqueTaskService` | `UniqueTaskServiceInterface` | Traitement des tâches uniques |
| `RecurringTaskService` | `RecurringTaskServiceInterface` | Traitement des tâches récurrentes |

### Enregistrement dans le conteneur Laravel

```php
$this->app->singleton(
    abstract: ProcessTasksDirective::class,
    concrete: function (Application $app) {
        return new ProcessTasksDirective(
            context: $app->make(DirectiveContext::class),
            interaction: $app->make(DirectiveInteractionService::class)
        );
    }
);
```

## Performance

| Aspect | Considération |
|--------|---------------|
| **Exécution** | Single batch, pas de polling |
| **Mémoire** | Toutes les tâches sont chargées en mémoire |
| **Temps** | Dépend du nombre de tâches et de leur durée |
| **Limite** | Peut être limitée avec `--limit` pour éviter les surcharges |

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |
| Laravel 12.x | ✅ Complet |
| Laravel 13.x | ✅ Complet |
| Laravel 14.x | ✅ Complet |
| Laravel 15.x | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Task\Directives\ProcessTasksDirective;

// Création de la directive
$context = new DirectiveContext();
$interaction = new DirectiveInteractionService();
$directive = new ProcessTasksDirective($context, $interaction);

// Exécution en mode JSON avec limit
$argv = ['directive', 'process-tasks', '--limit=5', '--format=json', '--unique-only'];
$exitCode = $directive->run($argv);

// Exécution en mode texte avec verbose
$argv = ['directive', 'process-tasks', '--verbose', '--recurring-only'];
$exitCode = $directive->run($argv);
```

## Voir aussi

- `UniqueTaskService` - Service de traitement des tâches uniques
- `RecurringTaskService` - Service de traitement des tâches récurrentes
- `TasksWatchDirective` - Directive de surveillance continue
- `BatchResultStruct` - Structure de données JSON
```