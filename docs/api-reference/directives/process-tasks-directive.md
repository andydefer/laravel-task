# ProcessTasksDirective - Référence Technique

## Description

Directive console pour le traitement par lots des tâches en attente. Permet d'exécuter des tâches uniques et/ou récurrentes en une seule opération, avec des options de filtrage et de limitation.

## Hiérarchie / Implémentations

```
AbstractDirective
    └── ProcessTasksDirective
```

## Rôle principal

Cette directive est le point d'entrée CLI pour le traitement des tâches. Elle orchestre :

1. **Validation** des options de la ligne de commande
2. **Traitement** des tâches uniques et/ou récurrentes
3. **Affichage** des résultats et des erreurs
4. **Gestion** des codes de retour

## Signature

```bash
./vendor/bin/directive process-tasks {--unique-only} {--recurring-only} {--verbose} {--limit=}
```

## Options

| Option | Type | Description |
|--------|------|-------------|
| `--unique-only` | Flag | Traite uniquement les tâches uniques |
| `--recurring-only` | Flag | Traite uniquement les tâches récurrentes |
| `--verbose` | Flag | Affiche les détails des erreurs |
| `--limit=` | `int` | Nombre maximum de tâches à traiter |

## Aliases

| Alias | Description |
|-------|-------------|
| `task:process` | Alias court pour la directive |
| `tasks:process` | Alias pluriel |

## Comportement

### Ordre de traitement

1. **Validation des options** : Vérifie que les options sont valides
2. **Récupération des services** : Obtient les services via le conteneur Laravel
3. **Traitement** : Exécute les tâches selon les options
4. **Affichage** : Montre les résultats et les erreurs
5. **Retour** : Retourne un code de sortie approprié

### Modes de traitement

| Mode | Comportement |
|------|--------------|
| **Normal** | Traite toutes les tâches (uniques + récurrentes) |
| **--unique-only** | Traite uniquement les tâches uniques |
| **--recurring-only** | Traite uniquement les tâches récurrentes |

## Exemples d'utilisation

### Traitement standard

```bash
# Traiter toutes les tâches
./vendor/bin/directive process-tasks

# Traiter avec une limite de 10 tâches
./vendor/bin/directive process-tasks --limit=10

# Traiter avec affichage détaillé
./vendor/bin/directive process-tasks --verbose
```

### Filtrage

```bash
# Traiter uniquement les tâches uniques
./vendor/bin/directive process-tasks --unique-only

# Traiter uniquement les tâches récurrentes
./vendor/bin/directive process-tasks --recurring-only

# Traiter 5 tâches uniques avec détails
./vendor/bin/directive process-tasks --unique-only --limit=5 --verbose
```

### Utilisation des alias

```bash
# Via l'alias task:process
./vendor/bin/directive task:process --limit=20

# Via l'alias tasks:process
./vendor/bin/directive tasks:process --unique-only
```

## Sortie console

### Exemple de sortie standard

```
Processing tasks...
Limit: 10 tasks

=== Batch Results ===
  Unique tasks: 8 processed (✅ 7, ❌ 1)
  Recurring tasks: 2 processed (✅ 2, ❌ 0)
  Total:          10 tasks in 1245 ms
```

### Exemple de sortie verbose

```
Processing tasks...
Limit: 10 tasks

=== Batch Results ===
  Unique tasks: 8 processed (✅ 7, ❌ 1)
  Recurring tasks: 2 processed (✅ 2, ❌ 0)
  Total:          10 tasks in 1245 ms

=== Failed Tasks ===
  Unique tasks:
    ❌ 550e8400-e29b-41d4-a716-446655440000: Connection timeout
  Recurring tasks:
    ❌ email-newsletter: API rate limit exceeded
```

## Codes de retour

| Code | Description |
|------|-------------|
| `ExitCode::SUCCESS` | Toutes les tâches ont réussi (aucun échec) |
| `ExitCode::FAILURE` | Au moins une tâche a échoué |
| `ExitCode::INVALID_ARGUMENT` | Options invalides (ex: --unique-only et --recurring-only ensemble) |

## Validation des options

| Condition | Résultat |
|-----------|----------|
| `--unique-only` et `--recurring-only` ensemble | `INVALID_ARGUMENT` |
| `--limit=0` ou négatif | `INVALID_ARGUMENT` |
| `--limit` non numérique | `INVALID_ARGUMENT` |
| Options valides | Traitement normal |

## Architecture interne

```
┌─────────────────────────────────────────────────────────────────────┐
│                    ProcessTasksDirective                         │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  execute()                                                         │
│  ├── validateOptions() → ExitCode|null                            │
│  ├── getUniqueTaskService() → UniqueTaskServiceInterface         │
│  ├── getRecurringTaskService() → RecurringTaskServiceInterface   │
│  ├── executeBatchProcessing() → BatchResultRecord                │
│  │   ├── processUniqueOnly() → BatchResultRecord                 │
│  │   ├── processRecurringOnly() → BatchResultRecord              │
│  │   └── processFull() → BatchResultRecord                       │
│  ├── displayResultsSummary()                                     │
│  ├── displayErrorsIfVerbose()                                    │
│  └── return ExitCode                                             │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cas d'utilisation

### Cas 1 : Traitement régulier

```bash
# Planifier dans crontab
* * * * * cd /path/to/project && ./vendor/bin/directive process-tasks --limit=50 >> /dev/null 2>&1
```

### Cas 2 : Traitement des tâches prioritaires

```bash
# Traiter les tâches uniques prioritaires
./vendor/bin/directive process-tasks --unique-only --verbose

# Puis les tâches récurrentes
./vendor/bin/directive process-tasks --recurring-only
```

### Cas 3 : Débogage

```bash
# Exécuter avec affichage détaillé pour identifier les problèmes
./vendor/bin/directive process-tasks --verbose --limit=5

# Tester un type spécifique
./vendor/bin/directive process-tasks --recurring-only --verbose
```

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskServiceInterface` | Traitement des tâches uniques |
| `RecurringTaskServiceInterface` | Traitement des tâches récurrentes |
| `AbstractDirective` | Classe de base des directives |
| `DirectiveContext` | Contexte d'exécution |
| `DirectiveInteractionService` | Services d'interaction |

## Performance

- **Complexité** : O(n) où n est le nombre de tâches à traiter
- **Limite** : Configurable via `--limit`
- **Temps** : Dépend du nombre de tâches et de leur complexité
- **Mémoire** : Les résultats sont chargés en mémoire

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 12.x, 13.x, 14.x, 15.x | ✅ Complet |

## Exemple complet

```bash
# 1. Traitement standard avec limite
./vendor/bin/directive process-tasks --limit=20

# 2. Traitement des tâches uniques uniquement
./vendor/bin/directive process-tasks --unique-only

# 3. Traitement avec débogage
./vendor/bin/directive process-tasks --recurring-only --verbose --limit=10

# 4. Utilisation d'un alias
./vendor/bin/directive task:process

# 5. Planification cron
* * * * * cd /var/www/project && ./vendor/bin/directive process-tasks --limit=50
```

## Voir aussi

- `AbstractDirective` - Classe de base des directives
- `UniqueTaskService` - Service des tâches uniques
- `RecurringTaskService` - Service des tâches récurrentes
- `BatchResultRecord` - DTO des résultats de traitement
- `DirectiveContext` - Contexte des directives