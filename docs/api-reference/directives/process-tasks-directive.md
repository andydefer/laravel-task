# ProcessTasksDirective - Référence Technique

## Description

Directive console pour exécuter un lot de tâches en une seule opération. Elle orchestre le traitement des tâches uniques et récurrentes avec des options de filtrage et de limitation.

## Hiérarchie

```
AbstractDirective
    └── ProcessTasksDirective
```

## Rôle principal

Cette directive sert de point d'entrée CLI pour le traitement par lots des tâches. Elle coordonne l'exécution des services `UniqueTaskService` et `RecurringTaskService`, agrège les résultats et les présente à l'utilisateur.

## API

### `getSignature(): string`

Retourne la signature de la directive pour la console.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `string` - La signature de la directive

**Exemple :**
```php
$directive = new ProcessTasksDirective($context, $interaction);
echo $directive->getSignature();
// 'process-tasks {--unique-only} {--recurring-only} {--verbose} {--limit=}'
```

---

### `shouldBootLaravel(): bool`

Indique si Laravel doit être booté avant l'exécution.

**Retourne :** `bool` - Toujours `true` car la directive dépend des services Laravel

**Exemple :**
```php
if ($directive->shouldBootLaravel()) {
    // Laravel sera booté avant l'exécution
}
```

---

### `getDescription(): string`

Retourne la description de la directive.

**Retourne :** `string` - Description lisible par l'utilisateur

**Exemple :**
```php
echo $directive->getDescription();
// 'Process all pending tasks in a single batch (no polling, no waiting)'
```

---

### `getAliases(): StringTypedCollection`

Retourne les alias de la directive.

**Retourne :** `StringTypedCollection` - Collection des alias

**Exemple :**
```php
$aliases = $directive->getAliases();
echo $aliases->first(); // 'task:process'
echo $aliases->last();  // 'tasks:process'
```

---

### `execute(): ExitCode`

Point d'entrée principal de la directive. Orchestre la validation, le traitement et l'affichage des résultats.

| Étape | Action |
|-------|--------|
| 1 | Valide les options |
| 2 | Récupère les services |
| 3 | Exécute le traitement par lots |
| 4 | Affiche les résultats |
| 5 | Retourne le code de sortie |

**Retourne :** `ExitCode` - Code de sortie (SUCCESS ou FAILURE)

**Exceptions :** `RuntimeException` - Si Laravel n'est pas disponible

**Exemple :**
```bash
./vendor/bin/directive process-tasks --limit=10 --verbose
```

---

### Méthodes privées

#### `getUniqueTaskService(): UniqueTaskServiceInterface`

Récupère le service des tâches uniques depuis le conteneur Laravel.

#### `getRecurringTaskService(): RecurringTaskServiceInterface`

Récupère le service des tâches récurrentes depuis le conteneur Laravel.

#### `validateOptions(): ?ExitCode`

Valide les options de la ligne de commande.

#### `getValidatedLimit(): ?int`

Récupère et valide la limite.

#### `displayProcessingStart(?int $limit): void`

Affiche le message de début de traitement.

#### `executeBatchProcessing(...): BatchResultRecord`

Orchestre l'exécution des tâches selon les options sélectionnées.

#### `processUniqueOnly(...): BatchResultRecord`

Traite uniquement les tâches uniques.

#### `processRecurringOnly(...): BatchResultRecord`

Traite uniquement les tâches récurrentes.

#### `processFull(...): BatchResultRecord`

Traite tous les types de tâches.

#### `displayResultsSummary(BatchResultRecord $record): void`

Affiche le résumé des résultats.

#### `displayTaskTypeSummary(...): void`

Affiche le résumé par type de tâche.

#### `displayErrorsIfVerbose(bool $verbose, BatchResultRecord $record): void`

Affiche les erreurs en mode verbose.

#### `getDurationMilliseconds(BatchResultRecord $record): int`

Calcule la durée d'exécution en millisecondes.

## Cas d'utilisation

### Cas 1 : Traitement standard de toutes les tâches

```bash
./vendor/bin/directive process-tasks
```

Exécute toutes les tâches prêtes (uniques et récurrentes) sans limite.

---

### Cas 2 : Traitement avec limite

```bash
./vendor/bin/directive process-tasks --limit=50 --verbose
```

Traite un maximum de 50 tâches avec affichage détaillé.

---

### Cas 3 : Traitement unique uniquement

```bash
./vendor/bin/directive process-tasks --unique-only --limit=10
```

Traite uniquement les 10 premières tâches uniques prêtes.

---

### Cas 4 : Traitement récurrent uniquement

```bash
./vendor/bin/directive process-tasks --recurring-only --verbose
```

Traite toutes les tâches récurrentes prêtes avec affichage détaillé.

---

## Flux d'exécution

```
execute()
    ├── validateOptions()
    │   ├── Vérifie que --unique-only et --recurring-only ne sont pas ensemble
    │   └── Vérifie que limit > 0
    │
    ├── displayProcessingStart($limit)
    │
    ├── getUniqueTaskService()
    ├── getRecurringTaskService()
    │
    ├── executeBatchProcessing()
    │   ├── [--unique-only] → processUniqueOnly()
    │   │   └── UniqueTaskService::process($limit) → BatchResultRecord
    │   │
    │   ├── [--recurring-only] → processRecurringOnly()
    │   │   └── RecurringTaskService::process($limit) → BatchResultRecord
    │   │
    │   └── [default] → processFull()
    │       ├── UniqueTaskService::process($limit)
    │       └── RecurringTaskService::process($limit)
    │
    ├── displayResultsSummary($record)
    │
    ├── displayErrorsIfVerbose($verbose, $record)
    │
    └── return ExitCode
        ├── FAILURE si unique_failed > 0 ou recurring_failed > 0
        └── SUCCESS sinon
```

## Options disponibles

| Option | Type | Description |
|--------|------|-------------|
| `--unique-only` | Flag | Traite uniquement les tâches uniques |
| `--recurring-only` | Flag | Traite uniquement les tâches récurrentes |
| `--verbose` | Flag | Affiche les détails et les erreurs |
| `--limit=N` | int | Limite le nombre de tâches à N |

## Résultat attendu

### Mode normal
```
Processing tasks...

=== Batch Results ===
  Unique tasks: 15 processed (✅ 12, ❌ 3)
  Recurring tasks: 8 processed (✅ 8, ❌ 0)
  Total:          23 tasks in 1245 ms
```

### Mode verbose avec erreurs
```
Processing tasks...

=== Batch Results ===
  Unique tasks: 15 processed (✅ 12, ❌ 3)
  Recurring tasks: 8 processed (✅ 8, ❌ 0)
  Total:          23 tasks in 1245 ms

=== Failed Tasks ===
  Unique tasks:
    ❌ 550e8400-e29b-41d4-a716-446655440000: Task execution failed
    ❌ 550e8400-e29b-41d4-a716-446655440001: Validation failed
    ❌ 550e8400-e29b-41d4-a716-446655440002: Task expired
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Laravel non disponible | `RuntimeException` | `Laravel container is not available. Task processing requires Laravel.` |
| Options incompatibles | `ExitCode::INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| Limite invalide (≤ 0) | `ExitCode::INVALID_ARGUMENT` | `Limit must be a positive integer` |

## Performance

- **Temps d'exécution** : Variable selon le nombre de tâches
- **Mémoire** : Les collections sont limitées par l'option `--limit`
- **Affichage** : Les messages sont envoyés directement dans la console

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```bash
# 1. Exécution standard
./vendor/bin/directive process-tasks

# 2. Tâches uniques uniquement avec limite
./vendor/bin/directive process-tasks --unique-only --limit=5

# 3. Tâches récurrentes avec affichage détaillé
./vendor/bin/directive process-tasks --recurring-only --verbose

# 4. Toutes les tâches avec limite et détails
./vendor/bin/directive process-tasks --limit=20 --verbose

# 5. Utilisation d'un alias
./vendor/bin/directive task:process --limit=10
```

## Voir aussi

- `UniqueTaskService` - Service d'exécution des tâches uniques
- `RecurringTaskService` - Service d'exécution des tâches récurrentes
- `BatchResultRecord` - Structure des résultats de batch
- `DirectiveTestingService` - Service de test des directives