# TasksListDirective - Référence Technique

## Description

Directive CLI qui liste les tâches uniques et récurrentes persistées, avec filtres par type, statut et FQCN.

## Hiérarchie

```
AbstractDirective
    └── TasksListDirective
```

## Rôle principal

Fournir un point d'entrée en lecture seule pour inspecter les tâches stockées en base. La directive agrège les résultats de plusieurs requêtes par statut, filtre en mémoire par FQCN, puis affiche le tout dans un tableau unique.

## Prérequis

- Package `andydefer/laravel-task` installé et migrations exécutées.
- Package `andydefer/laravel-directive` installé et kernel configuré.
- Package `andydefer/console-writer` installé.
- Les services `UniqueTaskServiceInterface` et `RecurringTaskServiceInterface` doivent être résolus par le conteneur.

## Signature

```
tasks:list
    {limit=50}#"Maximum number of tasks to display"
    {fqcns*}#"Filter by fully qualified class names (dots instead of backslashes)"
    {kinds*>[unique,recurring]}#"Task kinds to display"
    {unique_statuses*>[pending,completed,in_progress,failed,canceled]}#"Unique task statuses to include"
    {recurring_statuses*>[waiting,playing,paused,finished,canceled]}#"Recurring task statuses to include"
```

| Argument | Type | Défaut | Description |
|----------|------|--------|-------------|
| `limit` | `int` | `50` | Nombre maximum de tâches par statut |
| `fqcns` | `array<string>` | `[]` | Filtre par FQCN (notation pointée : `App.Tasks.MyTask`) |
| `kinds` | `array<string>` | `[unique,recurring]` | Types à afficher |
| `unique_statuses` | `array<string>` | Tous | Statuts de tâches uniques à inclure |
| `recurring_statuses` | `array<string>` | Tous | Statuts de tâches récurrentes à inclure |

### Ordre des arguments positionnels

Les variadics sont **positionnels**. Pour ne pas fournir un variadic, passer explicitement `[]`.

| Position | Argument |
|----------|----------|
| 1 | `limit` |
| 2 | `fqcns` |
| 3 | `kinds` |
| 4 | `unique_statuses` |
| 5 | `recurring_statuses` |

## Alias

- `tasks:ls`
- `t:ls`

## API / Méthodes publiques

### `getSignature(): string`

Retourne la signature CLI complète.

**Retourne :** `string`

### `getDescription(): string`

Retourne la description affichée dans l'aide CLI.

**Retourne :** `string` - `"List persisted unique and recurring tasks"`

### `getAliases(): StringTypedCollection`

Retourne les alias acceptés.

**Retourne :** `StringTypedCollection` - Contient `tasks:ls` et `t:ls`.

### `beforeExecute(): void` (protégée)

Valide que `limit` est supérieur ou égal à 1.

**Exceptions :** `InvalidArgumentException` si `limit < 1` : `"limit must be at least 1."`

### `execute(): ExitCode` (protégée)

Construit et affiche le tableau des tâches.

**Retourne :** `ExitCode::SUCCESS` dans tous les cas traités.

## Comportement

### Ordre d'exécution

```
1. beforeExecute()
   └── limit < 1 → InvalidArgumentException

2. execute()
   ├── Résolution des filtres :
   │     ├── limit
   │     ├── fqcns (dot → backslash)
   │     ├── kinds (défaut : [unique, recurring])
   │     ├── unique_statuses (défaut : tous)
   │     └── recurring_statuses (défaut : tous)
   ├── Si unique dans kinds → appendUniqueRows
   ├── Si recurring dans kinds → appendRecurringRows
   ├── Rendu via TableList::renderWithTitle(...)
   └── Retour SUCCESS
```

### Traitement des statuts

Pour chaque statut demandé, le service est appelé via sa méthode dédiée :

| Kind | Statut | Méthode appelée |
|------|--------|-----------------|
| unique | `pending` | `findPending()` |
| unique | `completed` | `findCompleted()` |
| unique | `in_progress` | `findPending()` (mapping) |
| unique | `failed` | `findFailed()` |
| unique | `canceled` | `findCanceled()` |
| recurring | `waiting` | `findWaiting()` |
| recurring | `playing` | `findPlaying()` |
| recurring | `paused` | `findPaused()` |
| recurring | `finished` | `findFinished()` |
| recurring | `canceled` | `findCanceled()` |

### Filtrage FQCN

Le filtre `fqcns` est appliqué **en mémoire**, après récupération des résultats. Chaque tâche est comparée par égalité stricte de FQCN.

**Conversion :** les points sont convertis en backslashes :
```
App.Tasks.MyTask  →  App\Tasks\MyTask
```

## Cas d'utilisation

### Cas 1 : Lister les 50 premières tâches

```bash
./bin/task tasks:list
```

Sortie :
```
🗂️ Tasks
┌───────────┬────────────────────┬────────────────┬──────────┬──────────────────────┬──────────┐
│ Kind      │ Alias              │ FQCN           │ Status   │ Next / Last run      │ Attempts │
├───────────┼────────────────────┼────────────────┼──────────┼──────────────────────┼──────────┤
│ unique    │ unique@0192f3a1-...│ App\FooTask    │ pending  │ 2026-06-23T10:00:00Z │ 0        │
│ recurring │ recurring@0192f3a2-│ App\BarTask    │ playing  │ 2026-06-23T11:00:00Z │ 0        │
└───────────┴────────────────────┴────────────────┴──────────┴──────────────────────┴──────────┘
```

### Cas 2 : Lister uniquement les tâches récurrentes

```bash
./bin/task tasks:list 50 [] [recurring]
```

### Cas 3 : Lister les tâches uniques en échec

```bash
./bin/task tasks:list 50 [] [unique] [failed]
```

### Cas 4 : Filtrer par FQCN

```bash
./bin/task tasks:list 50 [App.Tasks.MyUniqueTask]
```

### Cas 5 : Filtrer par FQCN et statut

```bash
./bin/task tasks:list 50 [App.Tasks.MyUniqueTask] [unique] [pending]
```

### Cas 6 : Limiter à 10 tâches

```bash
./bin/task tasks:list 10
```

### Cas 7 : Lister les tâches récurrentes en playing

```bash
./bin/task tasks:list 50 [] [recurring] [] [playing]
```

## Flux d'exécution

```
tasks:list [limit] [fqcns] [kinds] [unique_statuses] [recurring_statuses]
    ↓
beforeExecute() — validation limit ≥ 1
    ↓
execute()
    ├── appendUniqueRows() (si kinds contient unique)
    │     ├── findPending / findCompleted / findFailed / findCanceled
    │     └── Filtrage FQCN en mémoire
    ├── appendRecurringRows() (si kinds contient recurring)
    │     ├── findWaiting / findPlaying / findPaused / findFinished / findCanceled
    │     └── Filtrage FQCN en mémoire
    ├── TableList::renderWithTitle(...)
    ↓
ExitCode::SUCCESS
```

## Colonnes de sortie

| Colonne | Source unique | Source récurrente |
|---------|---------------|-------------------|
| `Kind` | `unique` | `recurring` |
| `Alias` | `alias` | `alias` |
| `FQCN` | `fqcn` | `fqcn` |
| `Status` | `status` | `status` |
| `Next / Last run` | `scheduled_at` | `last_run_at` sinon `start_at` |
| `Attempts` | `attempts` | `failed_attempts` |

## Gestion des erreurs

| Situation | Exception / ExitCode | Message |
|-----------|---------------------|---------|
| `limit < 1` | `InvalidArgumentException` | `limit must be at least 1.` |
| Statut inconnu | `ValueError` (via `Enum::from`) | Levée par le parser de variadic |
| Aucune tâche | `ExitCode::SUCCESS` | `⚠️ No data to display` |

## Intégration

| Composant | Rôle |
|-----------|------|
| `AbstractDirective` | Contrat parent |
| `UniqueTaskServiceInterface` | Source des tâches uniques |
| `RecurringTaskServiceInterface` | Source des tâches récurrentes |
| `TaskFqcnVOCollection` | Collection de FQCN |
| `LimitVO` | Limite de résultats |
| `TableList` | Rendu du tableau |
| `ListCollection` | Construction des lignes |

## Performance

- **Requêtes** : N requêtes, où N = nombre de statuts demandés × nombre de kinds.
- **Filtrage FQCN** : en mémoire, O(M) où M = résultats par statut.
- **Limite** : appliquée **par statut**, pas globalement. Avec 3 statuts et `limit=50`, jusqu'à 150 lignes peuvent être affichées.
- **Aucune écriture** en base.

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.2+ | ✅ |
| Laravel 12+ | ✅ |
| `andydefer/laravel-task` | ✅ Requis |
| `andydefer/laravel-directive` | ✅ Requis |
| `andydefer/console-writer` | ✅ Requis |

## Exemple complet

```bash
# 1. Lister les 50 premières tâches
./bin/task tasks:list

# 2. Lister uniquement les tâches récurrentes
./bin/task tasks:list 50 [] [recurring]

# 3. Lister les tâches uniques en échec
./bin/task tasks:list 50 [] [unique] [failed]

# 4. Filtrer par FQCN
./bin/task tasks:list 50 [App.Tasks.MyUniqueTask]

# 5. Combiner tous les filtres
./bin/task tasks:list 20 [App.Tasks.MyUniqueTask] [unique] [pending]

# 6. Limiter à 10 résultats
./bin/task tasks:list 10
```