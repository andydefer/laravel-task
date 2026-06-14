# ProcessTasksDirective - Référence Technique

## Description

Directive console qui exécute un lot de tâches planifiées en une seule opération, sans polling ni attente. Sert d'interface utilisateur entre la ligne de commande et le service `TaskBatchService`.

## Hiérarchie

```
AbstractDirective
    └── ProcessTasksDirective
```

## Rôle principal

Orchestrer l'exécution d'un lot de tâches avec des options de filtrage (uniques/récurrentes) et de limitation. La directive récupère le `TaskBatchService` via le container Laravel (`shouldBootLaravel() = true`), affiche les résultats formatés et retourne un code de sortie approprié.

## API / Méthodes publiques

### `__construct(DirectiveContext $context, DirectiveInteractionService $interaction): void`

Injecte les dépendances nécessaires à l'exécution de la directive.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$context` | `DirectiveContext` | Contexte de la directive (accès au container Laravel) |
| `$interaction` | `DirectiveInteractionService` | Service d'interaction console (entrée/sortie) |

### `getSignature(): string`

Retourne la signature de la commande avec toutes ses options.

| Option | Description |
|--------|-------------|
| `--unique-only` | Traite uniquement les tâches uniques (non récurrentes) |
| `--recurring-only` | Traite uniquement les tâches récurrentes |
| `--verbose` | Affiche les détails des erreurs |
| `--limit=` | Nombre maximum de tâches à traiter (entier positif) |

**Retourne :** `string` - Signature formatée pour le routeur console

### `shouldBootLaravel(): bool`

Indique si Laravel doit être démarré avant l'exécution.

**Retourne :** `bool` - `true` (nécessaire pour `TaskBatchService`)

### `getDescription(): string`

Retourne la description de la commande affichée dans l'aide.

**Retourne :** `string` - Description lisible

### `getAliases(): StringTypedCollection`

Retourne les noms alternatifs pour cette commande.

**Retourne :** `StringTypedCollection` - Collection contenant `'task:process'` et `'tasks:process'`

### `execute(): ExitCode`

Point d'entrée principal qui exécute la logique de traitement.

**Retourne :** `ExitCode` - Code de sortie :
- `ExitCode::SUCCESS` : aucune tâche échouée
- `ExitCode::FAILURE` : au moins une tâche a échoué
- `ExitCode::INVALID_ARGUMENT` : options invalides

## Flux d'exécution

```
execute()
    │
    ├─→ validateOptions()
    │   ├─→ Vérifie --unique-only et --recurring-only mutuellement exclusifs
    │   └─→ Valide --limit > 0
    │
    ├─→ getValidatedLimit()
    │   └─→ Convertit --limit en entier (ou null)
    │
    ├─→ displayProcessingStart()
    │   ├─→ Affiche "Processing tasks..."
    │   └─→ Si limit défini → "Limit: X tasks"
    │
    ├─→ getBatchService()
    │   ├─→ getLaravel() (via DirectiveContext)
    │   └─→ $laravel->make(TaskBatchService::class)
    │
    ├─→ executeBatchProcessing()
    │   ├─→ Si --unique-only → batch->processUniqueOnly($limit)
    │   ├─→ Si --recurring-only → batch->processRecurringOnly($limit)
    │   └─→ Sinon → batch->process($limit)
    │
    ├─→ displayResultsSummary()
    │   ├─→ Affiche "=== Batch Results ==="
    │   ├─→ Affiche résumé des tâches uniques (succès/échecs)
    │   ├─→ Affiche résumé des tâches récurrentes (succès/échecs)
    │   └─→ Affiche total et durée en ms
    │
    ├─→ displayErrorsIfVerbose()
    │   └─→ Si --verbose et des erreurs
    │       ├─→ Affiche "=== Failed Tasks ==="
    │       ├─→ Pour chaque erreur unique : task_id + details
    │       └─→ Pour chaque erreur récurrente : signature + details
    │
    └─→ Retourne FAILURE si unique_failed > 0 OU recurring_failed > 0
```

## Cas d'utilisation

### Cas 1 : Traitement standard de toutes les tâches

```bash
./vendor/bin/directive process-tasks
```

Sortie typique :
```
Processing tasks...

=== Batch Results ===
  Unique tasks:    5 processed (✅ 3, ❌ 2)
  Recurring tasks: 3 processed (✅ 3, ❌ 0)
  Total:           8 tasks in 234 ms
```

### Cas 2 : Traitement avec limite

```bash
./vendor/bin/directive process-tasks --limit=50
```

Sortie :
```
Processing tasks...
Limit: 50 tasks

=== Batch Results ===
  Unique tasks:    50 processed (✅ 48, ❌ 2)
  Recurring tasks: 0 processed (✅ 0, ❌ 0)
  Total:           50 tasks in 1250 ms
```

### Cas 3 : Filtrage par type de tâche (verbose)

```bash
./vendor/bin/directive process-tasks --unique-only --verbose --limit=10
```

Sortie :
```
Processing tasks...
Limit: 10 tasks

=== Batch Results ===
  Unique tasks:    10 processed (✅ 7, ❌ 3)
  Recurring tasks: 0 processed (✅ 0, ❌ 0)
  Total:           10 tasks in 567 ms

=== Failed Tasks ===
  Unique tasks:
    ❌ 550e8400-e29b-41d4-a716-446655440000: Connection timeout
    ❌ 660e8400-e29b-41d4-a716-446655440001: Invalid payload data
    ❌ 770e8400-e29b-41d4-a716-446655440002: Task validation failed
```

### Cas 4 : Options mutuellement exclusives (erreur)

```bash
./vendor/bin/directive process-tasks --unique-only --recurring-only
```

Sortie :
```
Cannot use both --unique-only and --recurring-only
```

### Cas 5 : Limite invalide (erreur)

```bash
./vendor/bin/directive process-tasks --limit=0
```

Sortie :
```
Limit must be a positive integer
```

## Gestion des erreurs

| Situation | Code de sortie | Message |
|-----------|----------------|---------|
| Flags `--unique-only` et `--recurring-only` ensemble | `INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| Limit = 0 | `INVALID_ARGUMENT` | `Limit must be a positive integer` |
| Limit négative (ex: `--limit=-5`) | `INVALID_ARGUMENT` | `Limit must be a positive integer` |
| Limit non numérique (ex: `--limit=abc`) | `INVALID_ARGUMENT` | `Limit must be a positive integer` |
| Container Laravel non disponible | `RuntimeException` | `Laravel container is not available. Task processing requires Laravel.` |
| Au moins une tâche échouée (`unique_failed > 0` ou `recurring_failed > 0`) | `FAILURE` | (dépend des erreurs individuelles) |
| Toutes les tâches réussies | `SUCCESS` | (message de succès via l'affichage) |

## Intégration

### Dépendances

```
ProcessTasksDirective
    ├── DirectiveContext (accès au container Laravel)
    ├── DirectiveInteractionService (entrées/sorties console)
    └── TaskBatchService (via container Laravel)

TaskBatchService
    └── retourne BatchResultRecord avec CounterVO
```

### Avec Laravel Directive

La directive s'utilise via le binaire `directive` :

```bash
./vendor/bin/directive process-tasks --limit=10 --verbose
```

### Alias disponibles

| Alias | Commande équivalente |
|-------|---------------------|
| `task:process` | `process-tasks` |
| `tasks:process` | `process-tasks` |

```bash
./vendor/bin/directive task:process --limit=5
./vendor/bin/directive tasks:process --recurring-only
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `validateOptions()` | O(1) | Constante |
| `getValidatedLimit()` | O(1) | Cast simple |
| `getBatchService()` | O(1) | Résolution via container Laravel |
| `executeBatchProcessing()` | O(n) | Délégation à `TaskBatchService` |
| `displayResultsSummary()` | O(1) | Affichage constant |
| `displayErrorsIfVerbose()` | O(e) | e = nombre d'erreurs (affichage uniquement) |

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.2+ | ✅ Requis (readonly properties) |
| Laravel 10.x | ✅ (via laravel-directive) |
| Laravel 11.x | ✅ |
| Laravel 12.x | ✅ |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Task\Directives\ProcessTasksDirective;

// 1. Création des dépendances
$context = new DirectiveContext();
$interaction = new DirectiveInteractionService();

// 2. Création de la directive
$directive = new ProcessTasksDirective($context, $interaction);

// 3. Exécution avec différentes options

// Cas standard
$exitCode = $directive->execute();
// $exitCode === ExitCode::SUCCESS (0) ou ExitCode::FAILURE (1)

// Simulation d'appel via la ligne de commande
// ./vendor/bin/directive process-tasks --unique-only --limit=25 --verbose
```

## Voir aussi

- `AbstractDirective` - Classe parente pour toutes les directives
- `TaskBatchService` - Service de traitement par lots
- `BatchResultRecord` - Record contenant les résultats (avec CounterVO)
- `TaskErrorRecord` - Record d'erreur pour une tâche unique échouée
- `RecurringTaskErrorRecord` - Record d'erreur pour une tâche récurrente échouée
- `CounterVO` - Value Object pour les compteurs (utilisé dans le résultat)
- `Iso8601DateTimeVO` - Value Object pour le calcul de la durée
- `DirectiveContext` - Contexte donnant accès au container Laravel
- `DirectiveTestingService` - Service de test pour les directives
---