# TasksProcessDirective - Référence Technique

## Description

La `TasksProcessDirective` est une directive console qui traite toutes les tâches en attente en un seul lot, sans boucle ni attente. Elle permet d'exécuter un traitement ponctuel des tâches uniques et/ou récurrentes avec un nombre limité de tâches par exécution.

## Hiérarchie / Implémentations

```
AbstractDirective
    └── TasksProcessDirective (final)
```

**Classe finale :** Ne peut pas être étendue

## Rôle principal

Cette directive agit comme un exécuteur de tâches en lot unique :

1. **Traitement ponctuel** des tâches en attente (pas de boucle)
2. **Exécution sélective** des tâches uniques ou récurrentes
3. **Limitation du nombre** de tâches par exécution
4. **Affichage des résultats** avec détails des erreurs
5. **Stockage des résultats** dans le contexte pour traçabilité
6. **Génération d'identifiant** d'exécution (UUID)

## API / Méthodes publiques

### `getSignature(): string`

**Retourne :** `string` - Signature de la directive

**Arguments :**
| Argument | Type | Défaut | Description |
|----------|------|--------|-------------|
| `limit` | `string` | `infinite` | Nombre maximum de tâches à traiter (`infinite` = illimité) |

**Options :**
| Option | Description |
|--------|-------------|
| `--unique-only` | Traiter uniquement les tâches uniques |
| `--recurring-only` | Traiter uniquement les tâches récurrentes |
| `--verbose` | Afficher les logs détaillés avec les erreurs |
| `--mute` | Supprimer toute sortie console |

---

### `getDescription(): string`

**Retourne :** `string` - Description de la directive

---

### `getAliases(): StringTypedCollection`

**Retourne :** `StringTypedCollection` - Alias de la directive (`task-process`, `tp`)

---

### `execute(): ExitCode`

**Retourne :** `ExitCode` - Code de sortie (SUCCESS/FAILURE/INVALID_ARGUMENT/RUNTIME_ERROR)

**Comportement :**
1. Valide les options et le paramètre `limit`
2. Traite les tâches selon les options (unique, récurrent, ou les deux)
3. Affiche les résultats
4. Stocke les résultats dans le contexte
5. Retourne `FAILURE` si des tâches ont échoué

**Exemple d'utilisation :**
```bash
./bin/task tasks:process 50 --unique-only --verbose
```

---

## Méthodes privées

### `processTasks(TaskType $type): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$type` | `TaskType` | Type de tâches à traiter (UNIQUE ou RECURRING) |

**Retourne :** `bool` - `true` si des échecs ont été rencontrés

**Comportement :**
1. Récupère le service correspondant au type
2. Exécute le traitement avec ou sans limite
3. Affiche les résultats
4. Stocke les résultats dans le contexte

---

### `processBothTypes(): bool`

**Retourne :** `bool` - `true` si des échecs ont été rencontrés

**Comportement :**
1. Traite les tâches uniques
2. Traite les tâches récurrentes
3. Agrège les résultats
4. Affiche les résultats combinés

---

### `processTasksWithoutRendering(TaskType $type): ProcessResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$type` | `TaskType` | Type de tâches à traiter |

**Retourne :** `ProcessResultRecord` - Résultat du traitement

**Comportement :** Traite sans affichage (utilisé pour les résultats combinés)

---

### `getService(TaskType $type): UniqueTaskServiceInterface|RecurringTaskServiceInterface`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$type` | `TaskType` | Type de service à récupérer |

**Retourne :** `UniqueTaskServiceInterface` ou `RecurringTaskServiceInterface`

**Exceptions :** `RuntimeException` si le conteneur n'est pas disponible

---

### `getTaskTypeLabel(TaskType $type): string`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$type` | `TaskType` | Type de tâche |

**Retourne :** `string` - Label du type (`Unique` ou `Recurring`)

---

### `validateOptions(): ExitCode`

**Retourne :** `ExitCode` - SUCCESS ou INVALID_ARGUMENT

**Vérification :** Vérifie que `--unique-only` et `--recurring-only` ne sont pas utilisés ensemble

---

### `validateAndGetLimit(): ?int`

**Retourne :** `int|null` - Limite ou null si illimitée

**Exceptions :** `InvalidArgumentException` si la valeur n'est pas valide

**Valeurs acceptées :**
- `'infinite'` → `null` (illimité)
- `'0'` → `null` (illimité)
- `'N'` avec N > 0 → `N`

---

### `renderStart(): void`

**Comportement :** Affiche le message de début avec la limite configurée

---

### `renderResult(ProcessResultRecord $result, string $type): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$result` | `ProcessResultRecord` | Résultat du traitement |
| `$type` | `string` | Type de tâches (`Unique` ou `Recurring`) |

**Comportement :** Affiche les résultats pour un seul type

---

### `renderCombinedResults(ProcessResultRecord $unique, ProcessResultRecord $recurring): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$unique` | `ProcessResultRecord` | Résultat des tâches uniques |
| `$recurring` | `ProcessResultRecord` | Résultat des tâches récurrentes |

**Comportement :** Affiche les résultats combinés pour les deux types

---

### `renderErrors(iterable $errors, string $type): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$errors` | `iterable` | Collection d'erreurs |
| `$type` | `string` | Type de tâches |

**Comportement :** Affiche les erreurs en mode `--verbose`

---

### `renderErrorsFromMultiple(iterable $uniqueErrors, iterable $recurringErrors): void`

**Comportement :** Affiche les erreurs des deux types en mode `--verbose`

---

### `renderError(mixed $error): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$error` | `mixed` | Objet d'erreur à afficher |

**Comportement :** Affiche une erreur formatée avec `KeyValue`

---

### `storeResult(string $uuid, ProcessResultRecord $result, TaskType $type): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$uuid` | `string` | Identifiant d'exécution |
| `$result` | `ProcessResultRecord` | Résultat du traitement |
| `$type` | `TaskType` | Type de tâches |

**Comportement :** Stocke le résultat dans le contexte avec la clé `{type}-{uuid}`

---

### `storeFullResult(string $uuid, ProcessResultRecord $unique, ProcessResultRecord $recurring): void`

**Comportement :** Stocke les résultats des deux types

---

## Cas d'utilisation

### Cas 1 : Traitement de toutes les tâches (uniques et récurrentes)

```bash
./bin/task tasks:process
```

**Comportement :**
- Traite toutes les tâches en attente
- Limite : illimitée (infinite)
- Affiche les résultats combinés

---

### Cas 2 : Traitement limité à 50 tâches uniques

```bash
./bin/task tasks:process 50 --unique-only
```

**Comportement :**
- Traite au maximum 50 tâches uniques
- Ignore les tâches récurrentes

---

### Cas 3 : Traitement des tâches récurrentes avec logs détaillés

```bash
./bin/task tasks:process 100 --recurring-only --verbose
```

**Comportement :**
- Traite jusqu'à 100 tâches récurrentes
- Affiche les erreurs en détail

---

### Cas 4 : Traitement silencieux pour cron

```bash
./bin/task tasks:process infinite --mute
```

**Comportement :**
- Aucune sortie console
- Utile pour les jobs cron

---

### Cas 5 : Utilisation d'un alias

```bash
./bin/task task-process 25 --verbose
```

---

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                     TasksProcessDirective::execute()               │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. Validation                                                     │
│     - validateAndGetLimit() → limit ou null                      │
│     - validateOptions() → unique-only + recurring-only ?        │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. Rendu du message de début                                     │
│     - renderStart()                                               │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. Traitement selon les options                                  │
│     ┌──────────────────────────────────────────────────────────┐  │
│     │ unique-only  → processTasks(UNIQUE)                     │  │
│     │ recurring-only → processTasks(RECURRING)                │  │
│     │ default → processBothTypes()                            │  │
│     └──────────────────────────────────────────────────────────┘  │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. processTasks(TYPE)                                              │
│     - getService(TYPE)                                              │
│     - service->process($limit)                                      │
│     - renderResult()                                                │
│     - renderErrors() (si --verbose)                                 │
│     - storeResult()                                                 │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  5. Retour du code de sortie                                        │
│     - hasFailures → FAILURE                                         │
│     - pas d'échec → SUCCESS                                         │
└─────────────────────────────────────────────────────────────────────┘
```

## Flux détaillé de `processBothTypes()`

```
┌─────────────────────────────────────────────────────────────────────┐
│                    processBothTypes()                               │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. Traitement des tâches uniques                                   │
│     - processTasksWithoutRendering(UNIQUE) → $uniqueResult          │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. Traitement des tâches récurrentes                               │
│     - processTasksWithoutRendering(RECURRING) → $recurringResult    │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. Agrégation des résultats                                        │
│     - hasFailures = unique.failed || recurring.failed               │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. Affichage                                                       │
│     - renderCombinedResults()                                       │
│     - renderErrorsFromMultiple() (si --verbose)                     │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  5. Stockage                                                        │
│     - storeFullResult()                                             │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Comportement | Code retour |
|-----------|--------------|-------------|
| Conteneur non disponible | Message d'erreur | RUNTIME_ERROR |
| Limit invalide | Message d'erreur | INVALID_ARGUMENT |
| `--unique-only` + `--recurring-only` | Message d'erreur | INVALID_ARGUMENT |
| Exception pendant le traitement | Capture → affichage | RUNTIME_ERROR |
| Échecs dans les tâches | `hasFailures = true` | FAILURE |

**Exemples d'erreurs :**

```bash
# Limit invalide
./bin/task tasks:process -5
# ❌ Limit must be a positive integer, "infinite", or 0 (no limit)

# Options mutuellement exclusives
./bin/task tasks:process --unique-only --recurring-only
# ❌ Cannot use both --unique-only and --recurring-only
```

## Performance

| Opération | Complexité | Description |
|-----------|-----------|-------------|
| `execute()` | O(n) | n = nombre de tâches traitées |
| `processTasks()` | O(n) | Délègue au service |
| `renderResult()` | O(1) | Affichage |
| `storeResult()` | O(1) | Stockage dans le contexte |

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.0 | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 9 | ✅ Complet |
| Tous environnements | ✅ |

## Exemple complet

```bash
# 1. Traitement de base (illimité)
./bin/task tasks:process

# 2. Traitement limité à 25 tâches
./bin/task tasks:process 25

# 3. Traitement des tâches uniques uniquement
./bin/task tasks:process 50 --unique-only

# 4. Traitement des tâches récurrentes avec logs
./bin/task tasks:process 100 --recurring-only --verbose

# 5. Traitement silencieux pour cron
./bin/task tasks:process infinite --mute

# 6. Utilisation d'un alias
./bin/task task-process 10 --verbose
```

## Intégration avec les cron jobs

```bash
# Exécution toutes les minutes avec limite de 50 tâches
* * * * * cd /var/www/project && ./bin/task tasks:process 50 --mute >> /var/log/tasks-process.log 2>&1

# Exécution des tâches uniques toutes les 5 minutes
*/5 * * * * cd /var/www/project && ./bin/task tasks:process 100 --unique-only --mute >> /var/log/tasks-unique.log 2>&1

# Exécution des tâches récurrentes toutes les heures
0 * * * * cd /var/www/project && ./bin/task tasks:process --recurring-only --verbose >> /var/log/tasks-recurring.log 2>&1
```

## Exemple de sortie

```bash
$ ./bin/task tasks:process 10 --verbose

Processing tasks...
  Limit: 10

=== Unique Batch Results ===
  ✅ Success: 8
  ❌ Failed: 2
  📦 Total: 10

=== Failed Unique Tasks ===
  Alias: unique@550e8400-e29b-41d4-a716-446655440000
  Description: Connection timeout
  Context: attempts: 3/3

  Alias: unique@550e8400-e29b-41d4-a716-446655440001
  Description: Invalid payload
  Context: attempts: 1/3

=== Recurring Batch Results ===
  ✅ Success: 15
  ❌ Failed: 0
  📦 Total: 15

=== Batch Results ===
  ✅ Unique Success: 8
  ❌ Unique Failed: 2
  ✅ Recurring Success: 15
  ❌ Recurring Failed: 0
  📦 Total Success: 23
  📦 Total Failed: 2
  📊 Total Processed: 25
```

## Voir aussi
- `UniqueTaskService` - Service de tâches uniques
- `RecurringTaskService` - Service de tâches récurrentes
- `ProcessResultRecord` - Résultat du traitement
- `TaskType` - Énumération des types de tâches
- `TasksWatchDirective` - Directive de surveillance continue
- `KeyValue` - Composant d'affichage clé-valeur