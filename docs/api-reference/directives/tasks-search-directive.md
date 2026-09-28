```markdown
# TasksSearchDirective - Référence Technique

## Description

Directive CLI qui recherche des tâches uniques et récurrentes par leur alias, et affiche les résultats dans un tableau.

## Hiérarchie

```
AbstractDirective
    └── TasksSearchDirective
```

## Rôle principal

Fournir un point d'entrée en lecture seule pour retrouver une ou plusieurs tâches persistées par leur alias. La directive interroge séquentiellement le service des tâches uniques, puis celui des tâches récurrentes, et rapporte les alias introuvables sans interrompre la recherche.

## Prérequis

- Package `andydefer/laravel-task` installé et migrations exécutées.
- Package `andydefer/laravel-directive` installé et kernel configuré.
- Package `andydefer/console-writer` installé.
- Les services `UniqueTaskServiceInterface` et `RecurringTaskServiceInterface` doivent être résolus par le conteneur.

## Signature

```
tasks:search {aliases*}#"Task aliases to search"
```

| Argument | Type | Obligatoire | Description |
|----------|------|-------------|-------------|
| `aliases` | `array<string>` | ✅ | Liste d'alias à rechercher |

Les alias sont fournis sous forme de variadic : `[alias1, alias2, ...]`.

## Alias

- `tasks:find`
- `t:find`

## API / Méthodes publiques

### `getSignature(): string`

Retourne la signature CLI complète.

**Retourne :** `string`

### `getDescription(): string`

Retourne la description affichée dans l'aide CLI.

**Retourne :** `string` - `"Search unique and recurring tasks by alias"`

### `getAliases(): StringTypedCollection`

Retourne les alias acceptés.

**Retourne :** `StringTypedCollection` - Contient `tasks:find` et `t:find`.

### `beforeExecute(): void` (protégée)

Valide la présence d'au moins un alias.

**Exceptions :** `InvalidArgumentException` si aucun alias n'est fourni : `"At least one alias is required."`

### `execute(): ExitCode` (protégée)

Recherche chaque alias auprès des services unique et récurrent.

**Retourne :**
- `ExitCode::SUCCESS` — toutes les alias ont été trouvées.
- `ExitCode::FAILURE` — au moins une alias est introuvable, ou aucune n'a été trouvée.

## Comportement

### Ordre d'exécution

```
1. beforeExecute()
   └── aliases vide → InvalidArgumentException

2. execute()
   ├── Pour chaque alias :
   │     ├── Recherche dans UniqueTaskServiceInterface::find(alias)
   │     │     └── Trouvé → ajout à la table (kind=unique), continue
   │     ├── Recherche dans RecurringTaskServiceInterface::find(alias)
   │     │     └── Trouvé → ajout à la table (kind=recurring), continue
   │     └── Non trouvé → ajout à $notFound
   ├── Si aucune ligne trouvée :
   │     ├── Log erreur "No matching task found."
   │     ├── Log chaque alias introuvable
   │     └── Retour FAILURE
   ├── Rendu du tableau
   ├── Log chaque alias introuvable (si partiel)
   └── Retour SUCCESS ou FAILURE
```

### Ordre de recherche

| Priorité | Service | Condition |
|----------|---------|-----------|
| 1 | `UniqueTaskServiceInterface::find()` | Si retour non-null, l'alias est considéré trouvé |
| 2 | `RecurringTaskServiceInterface::find()` | Consulté uniquement si le précédent retourne `null` |

Un alias n'est jamais recherché deux fois : la première correspondance gagne.

## Cas d'utilisation

### Cas 1 : Rechercher une tâche unique

```bash
./bin/task tasks:search [unique@0192f3a1-...]
```

Sortie :
```
🔎 Tasks
┌────────┬─────────────────────────────┬───────────────┬──────────┬──────────────────────┬──────────┐
│ Kind   │ Alias                       │ FQCN          │ Status   │ Next / Last run      │ Attempts │
├────────┼─────────────────────────────┼───────────────┼──────────┼──────────────────────┼──────────┤
│ unique │ unique@0192f3a1-...         │ App\FooTask   │ pending  │ 2026-06-23T10:00:00Z │ 0        │
└────────┴─────────────────────────────┴───────────────┴──────────┴──────────────────────┴──────────┘
```

### Cas 2 : Rechercher plusieurs tâches de types différents

```bash
./bin/task tasks:search [unique@0192f3a1-..., recurring@0192f3a2-...]
```

### Cas 3 : Rechercher un alias introuvable

```bash
./bin/task tasks:search [unique@00000000-0000-0000-0000-000000000000]
```

Sortie :
```
No matching task found.
Task not found: unique@00000000-0000-0000-0000-000000000000
```

Exit code : `FAILURE`.

### Cas 4 : Recherche partielle

```bash
./bin/task tasks:search [unique@0192f3a1-..., unique@00000000-...]
```

Sortie : tableau avec la tâche trouvée, suivie d'un avertissement `Task not found: unique@00000000-...`.

Exit code : `FAILURE`.

## Flux d'exécution

```
tasks:search [alias1, alias2, ...]
    ↓
beforeExecute() — validation aliases non vides
    ↓
execute()
    ├── UniqueTaskServiceInterface::find(alias1)
    ├── RecurringTaskServiceInterface::find(alias1) (si unique absent)
    ├── ... (idem pour alias2)
    ├── TableList::renderWithTitle(...)
    └── Warnings pour alias introuvables
    ↓
ExitCode::SUCCESS ou FAILURE
```

## Gestion des erreurs

| Situation | Exception / ExitCode | Message |
|-----------|---------------------|---------|
| Aucun alias fourni | `InvalidArgumentException` | `At least one alias is required.` |
| Aucune tâche trouvée | `ExitCode::FAILURE` | `No matching task found.` |
| Alias introuvable (partiel) | `ExitCode::FAILURE` | `Task not found: <alias>` |
| Alias introuvable (total) | `ExitCode::FAILURE` | `No matching task found.` + `Task not found: <alias>` |

## Colonnes de sortie

| Colonne | Unique | Recurring |
|---------|--------|-----------|
| `Kind` | `unique` | `recurring` |
| `Alias` | `alias` | `alias` |
| `FQCN` | `fqcn` | `fqcn` |
| `Status` | `status` | `status` |
| `Next / Last run` | `scheduled_at` | `last_run_at` sinon `start_at` |
| `Attempts` | `attempts` | `failed_attempts` |

## Intégration

| Composant | Rôle |
|-----------|------|
| `AbstractDirective` | Contrat parent |
| `UniqueTaskServiceInterface` | Recherche des tâches uniques |
| `RecurringTaskServiceInterface` | Recherche des tâches récurrentes |
| `TaskAliasVO` | Value Object d'alias |
| `TableList` | Rendu du tableau |
| `ListCollection` | Construction des lignes |

## Performance

- **Coût** : 2 appels `find()` maximum par alias (unique puis récurrent).
- **Latence** : dominée par les requêtes base de données.
- **Complexité** : O(n) où n = nombre d'alias fournis.
- **Idempotent** : aucune écriture en base.

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
# 1. Rechercher une tâche unique
./bin/task tasks:search [unique@0192f3a1-605a-7208-8b40-11d0888080f6]

# 2. Rechercher plusieurs tâches
./bin/task tasks:search [unique@0192f3a1-..., recurring@0192f3a2-...]

# 3. Utiliser l'alias court
./bin/task t:find [unique@0192f3a1-...]

# 4. Rechercher un alias introuvable (retourne FAILURE)
./bin/task tasks:search [unknown@00000000-0000-0000-0000-000000000000]
```
```