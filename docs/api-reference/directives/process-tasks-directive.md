# ProcessTasksDirective - Référence Technique

## Description

Console directive permettant de traiter les tâches en attente (uniques et récurrentes) en mode batch. Cette directive exécute un traitement unique sans boucle ni attente.

## Hiérarchie / Implémentations

```
AbstractDirective
    └── ProcessTasksDirective
```

## Rôle principal

Orchestrer l'exécution des tâches en attente en fonction des options fournies, produire des rapports formatés (texte ou JSON) et gérer les erreurs. La directive agit comme un point d'entrée unique pour le traitement batch des tâches.

## API / Méthodes publiques

### `getSignature(): string`

| Élément | Description |
|---------|-------------|
| **Retourne** | `string` - La signature de la commande avec ses options |

**Exemple :**
```php
$signature = $directive->getSignature();
// 'process-tasks {--unique-only} {--recurring-only} {--verbose} {--limit=} {--format=}'
```

---

### `getDescription(): string`

| Élément | Description |
|---------|-------------|
| **Retourne** | `string` - La description de la commande |

**Exemple :**
```php
$description = $directive->getDescription();
// 'Process all pending tasks in a single batch (no polling, no waiting)'
```

---

### `getAliases(): StringTypedCollection`

| Élément | Description |
|---------|-------------|
| **Retourne** | `StringTypedCollection` - Collection des alias de la commande |

**Exemple :**
```php
$aliases = $directive->getAliases();
// ['task-process', 'tasks-process']
```

---

### `execute(): ExitCode`

| Élément | Description |
|---------|-------------|
| **Retourne** | `ExitCode` - SUCCESS (0) ou FAILURE (1) |
| **Exceptions** | `RuntimeException` - Si le conteneur Laravel n'est pas disponible |

**Exemple :**
```php
$exitCode = $directive->execute();
// ExitCode::SUCCESS ou ExitCode::FAILURE
```

## Options de la commande

| Option | Type | Description | Valeur par défaut |
|--------|------|-------------|------------------|
| `--unique-only` | Flag | Traite uniquement les tâches uniques | `false` |
| `--recurring-only` | Flag | Traite uniquement les tâches récurrentes | `false` |
| `--verbose` | Flag | Affiche les détails des erreurs | `false` |
| `--limit=N` | Integer | Nombre maximum de tâches à traiter | `null` (illimité) |
| `--format=text\|json` | String | Format de sortie | `text` |

## Cas d'utilisation

### Cas 1 : Traitement standard

**Problème :** Traiter toutes les tâches en attente avec un rapport texte.

```bash
php directive process-tasks
```

**Sortie :**
```
Processing tasks...

=== Batch Results ===
  Unique:    ✅ 5, ❌ 0
  Recurring: ✅ 3, ❌ 1
  Total:     ✅ 8, ❌ 1, 📦 9
  Has failures: Yes
```

---

### Cas 2 : Traitement JSON pour intégration

**Problème :** Intégrer le résultat dans un pipeline CI/CD.

```bash
php directive process-tasks --format=json --limit=10
```

**Sortie :**
```json
{
  "started_at": "2026-01-01T12:00:00+00:00",
  "ended_at": "2026-01-01T12:00:05+00:00",
  "duration_ms": 5000,
  "total_success": 8,
  "total_failed": 2,
  "total": 10,
  "has_failures": true,
  "unique": { "success": 5, "failed": 1, "errors": [...] },
  "recurring": { "success": 3, "failed": 1, "errors": [...] }
}
```

---

### Cas 3 : Traitement filtré avec débogage

**Problème :** Traiter uniquement les tâches uniques qui ont échoué pour investigation.

```bash
php directive process-tasks --unique-only --verbose --limit=5
```

**Sortie :**
```
Processing tasks...
Limit: 5 tasks

=== Unique Batch Results ===
  Success: 3
  Failed: 2
  Total: 5

=== Failed Unique Tasks ===
    ❌ unique@abc-123: Task execution failed: Connection timeout
    ❌ unique@def-456: Validation failed: Invalid payload structure
```

---

### Cas 4 : Sans tâches en attente

**Problème :** Aucune tâche à traiter, la commande doit rester propre.

```bash
php directive process-tasks
```

**Sortie :**
```
Processing tasks...

=== Batch Results ===
  Unique:    ✅ 0, ❌ 0
  Recurring: ✅ 0, ❌ 0
  Total:     ✅ 0, ❌ 0, 📦 0
  Has failures: No
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Conteneur Laravel indisponible | `RuntimeException` | `Laravel container is not available` |
| Service non disponible | `RuntimeException` | `Laravel container is not available. Task processing requires Laravel.` |
| Options mutuellement exclusives | `ExitCode::INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| Limite invalide (≤ 0) | `ExitCode::INVALID_ARGUMENT` | `Limit must be a positive integer` |
| Format invalide | `ExitCode::INVALID_ARGUMENT` | `Format must be "text" or "json"` |

## Flux d'exécution

```
execute()
    ├── Récupération du conteneur Laravel
    │   └── Échec → RuntimeException
    ├── Validation des options
    │   └── Échec → ExitCode::INVALID_ARGUMENT
    ├── Récupération des services
    ├── Branchement sur les options
    │   ├── --unique-only → ProcessUniqueOnly
    │   ├── --recurring-only → ProcessRecurringOnly
    │   └── Aucun filtre → ProcessUniqueOnly + ProcessRecurringOnly
    ├── Traitement
    ├── Affichage (texte ou JSON)
    └── Retour ExitCode::SUCCESS / FAILURE
```

## Intégration

### Dépendances injectées

- `UniqueTaskServiceInterface` : Service de traitement des tâches uniques
- `RecurringTaskServiceInterface` : Service de traitement des tâches récurrentes
- `Console` : Service d'affichage console

### Points d'extension

- Les services `UniqueTaskServiceInterface` et `RecurringTaskServiceInterface` peuvent être substitués par des implémentations personnalisées via le conteneur Laravel.

## Performance

- **Complexité** : O(n) où n est le nombre de tâches traitées
- **Mémoire** : Les résultats sont chargés en mémoire, mais les collections sont optimisées
- **Recommandation** : Utiliser `--limit` pour les volumes importants (> 1000 tâches)

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |
| PHP 8.0 | ⚠️ Nécessite PHP 8.1+ pour les enums |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Directive\Enums\ExitCode;

// Création de l'application Laravel
$app = require __DIR__ . '/bootstrap/app.php';

// Exécution de la directive
$directive = $app->make(ProcessTasksDirective::class);

// Traitement standard
$exitCode = $directive->execute();

if ($exitCode === ExitCode::SUCCESS) {
    echo "Tâches traitées avec succès.\n";
} else {
    echo "Des erreurs sont survenues.\n";
}
```

## Voir aussi

- `TasksWatchDirective` - Surveillance continue des tâches
- `UniqueTaskServiceInterface` - Service de tâches uniques
- `RecurringTaskServiceInterface` - Service de tâches récurrentes
- `ProcessResultRecord` - Structure de résultat
---