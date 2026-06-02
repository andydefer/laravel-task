# ProcessTasksDirective - Référence Technique

## Description

Commande console qui exécute un lot de tâches planifiées en une seule opération, sans polling ni attente.

## Hiérarchie

```
AbstractDirective
    └── ProcessTasksDirective
```

## Rôle principal

Orchestrer l'exécution d'un lot de tâches avec des options de filtrage (uniques/récurrentes) et de limitation. La directive sert d'interface utilisateur entre la ligne de commande et le service `TaskBatchService`.

## API / Méthodes publiques

### `__construct(DirectiveInteractionService $interaction, TaskBatchService $batch): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$interaction` | `DirectiveInteractionService` | Service d'interaction console (entrée/sortie) |
| `$batch` | `TaskBatchService` | Service de traitement par lots de tâches |

**Retourne :** `void`

**Exemple :**
```php
$directive = new ProcessTasksDirective($interaction, $taskBatch);
```

### `getSignature(): string`

Retourne la signature de la commande avec toutes ses options.

| Option | Description |
|--------|-------------|
| `--unique-only` | Traite uniquement les tâches uniques (non récurrentes) |
| `--recurring-only` | Traite uniquement les tâches récurrentes |
| `--verbose` | Affiche les détails des erreurs |
| `--limit=` | Nombre maximum de tâches à traiter (entier positif) |

**Retourne :** `string` - Signature formatée pour le routeur console

**Exemple :**
```php
$signature = $directive->getSignature();
// 'process-tasks {--unique-only : ...} {--recurring-only : ...} ...'
```

### `getDescription(): string`

Retourne la description de la commande affichée dans l'aide.

**Retourne :** `string` - Description lisible

**Exemple :**
```php
$description = $directive->getDescription();
// 'Process all pending tasks in a single batch (no polling, no waiting)'
```

### `getAliases(): StringTypedCollection`

Retourne les noms alternatifs pour cette commande.

**Retourne :** `StringTypedCollection` - Collection typée de chaînes

**Exemple :**
```php
$aliases = $directive->getAliases();
// Collection contenant 'task:process' et 'tasks:process'
```

### `execute(): ExitCode`

Point d'entrée principal qui exécute la logique de traitement.

**Retourne :** `ExitCode` - Code de sortie (SUCCESS, FAILURE, INVALID_ARGUMENT)

## Flux d'exécution

```
execute()
    │
    ├─→ validateOptions()
    │   ├─→ Vérifie flags mutuellement exclusifs
    │   └─→ Valide limit > 0
    │
    ├─→ getValidatedLimit()
    │   └─→ Convertit limit en entier
    │
    ├─→ displayProcessingStart()
    │   └─→ Affiche "Processing tasks..." + limite si définie
    │
    ├─→ executeBatchProcessing()
    │   ├─→ Si unique-only → batch->processUniqueOnly($limit)
    │   ├─→ Si recurring-only → batch->processRecurringOnly($limit)
    │   └─→ Sinon → batch->process($limit)
    │
    ├─→ displayResultsSummary()
    │   ├─→ Affiche succès/échecs uniques
    │   ├─→ Affiche succès/échecs récurrents
    │   └─→ Affiche total et durée
    │
    ├─→ displayErrorsIfVerbose()
    │   └─→ Si verbose → affiche chaque erreur via each()
    │
    └─→ Retourne FAILURE si des tâches ont échoué, SUCCESS sinon
```

## Cas d'utilisation

### Cas 1 : Traitement standard de toutes les tâches

```php
// Ligne de commande
// ./vendor/bin/directive process-tasks

// En code
$directive = new ProcessTasksDirective($interaction, $taskBatch);
$exitCode = $directive->execute(); // ExitCode::SUCCESS
```

### Cas 2 : Traitement avec limite

```php
// Ligne de commande
// ./vendor/bin/directive process-tasks --limit=50

// La directive limite le traitement à 50 tâches maximum
```

### Cas 3 : Filtrage par type de tâche

```php
// Uniquement les tâches uniques
// ./vendor/bin/directive process-tasks --unique-only

// Uniquement les tâches récurrentes
// ./vendor/bin/directive process-tasks --recurring-only

// Les deux flags sont mutuellement exclusifs
// ./vendor/bin/directive process-tasks --unique-only --recurring-only
// Erreur : "Cannot use both --unique-only and --recurring-only"
```

## Gestion des erreurs

| Situation | Code de sortie | Message |
|-----------|----------------|---------|
| Flags `--unique-only` et `--recurring-only` ensemble | `INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| Limit = 0 ou négative | `INVALID_ARGUMENT` | `Limit must be a positive integer` |
| Limit non numérique (ex: `--limit=abc`) | `INVALID_ARGUMENT` | `Limit must be a positive integer` |
| Échec d'au moins une tâche | `FAILURE` | (dépend des erreurs individuelles) |
| Toutes les tâches réussies | `SUCCESS` | (message de succès via l'affichage) |

## Intégration

### Dépendances

```
DirectiveInteractionService ← hérité de AbstractDirective
TaskBatchService → injecté dans le constructeur
BatchResultRecord → retourné par TaskBatchService, utilisé pour l'affichage
TaskErrorRecord → utilisé pour itérer sur les erreurs
Iso8601DateTime → Value Object pour les dates
```

### Avec Laravel Directive

La directive s'utilise via le binaire `directive` :

```bash
./vendor/bin/directive process-tasks --limit=10 --verbose
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| Validation des options | O(1) | Constante, pas d'allocation |
| Exécution du batch | O(n) | Linéaire par rapport au nombre de tâches |
| Affichage des résultats | O(k) | k = nombre de types de tâches (2) |
| Affichage verbose | O(e) | e = nombre d'erreurs |

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

use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Services\TaskBatchService;

// Configuration
$interaction = new DirectiveInteractionService();
$taskBatch = app(TaskBatchService::class);

// Création de la directive
$directive = new ProcessTasksDirective($interaction, $taskBatch);

// Exécution avec options
$exitCode = $directive->execute();

// Sortie console typique :
// Processing tasks...
// Limit: 10 tasks
//
// === Batch Results ===
//   Unique tasks:   5 processed (✅ 3, ❌ 2)
//   Recurring tasks: 5 processed (✅ 5, ❌ 0)
//   Total:          10 tasks in 234 ms
//
// === Failed Tasks ===
//   ❌ task-123: Connection timeout
//   ❌ task-456: Invalid payload data
```

## Voir aussi

- `AbstractDirective` - Classe parente pour toutes les directives
- `TaskBatchService` - Service de traitement par lots
- `BatchResultRecord` - Record contenant les résultats
- `TaskErrorRecord` - Record d'erreur pour une tâche échouée
- `Iso8601DateTime` - Value Object pour les dates ISO 8601

---