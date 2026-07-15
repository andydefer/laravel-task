Merci pour la précision. Voici la version corrigée de la référence technique pour `TasksWatchDirective` avec la bonne syntaxe d'utilisation.

---

# TasksWatchDirective - Référence Technique

## Description

La `TasksWatchDirective` est une directive console qui surveille et exécute les tâches en continu dans une boucle avec un intervalle configurable. Elle orchestre l'exécution parallèle des tâches uniques et récurrentes tout en fournissant un affichage en temps réel de l'avancement.

## Hiérarchie / Implémentations

```
AbstractDirective
    └── TasksWatchDirective (final)
```

**Classe finale :** Ne peut pas être étendue

## Rôle principal

Cette directive agit comme le point d'entrée principal pour la surveillance des tâches :

1. **Exécution en boucle** avec intervalle configurable (minimum 2 secondes)
2. **Orchestration parallèle** via `ParallelExecutor`
3. **Gestion des signaux** (Ctrl+C) pour un arrêt propre
4. **Affichage en temps réel** des tâches restantes
5. **Rapport final** détaillé du statut des tâches
6. **Gestion de session** pour le suivi des exécutions

## API / Méthodes publiques

### `getSignature(): string`

**Retourne :** `string` - Signature de la directive

**Arguments :**
| Argument | Type | Défaut | Description |
|----------|------|--------|-------------|
| `interval` | `int` | 60 | Intervalle entre les cycles (minimum 2s) |
| `duration` | `int` | `?` | Durée totale d'exécution (illimitée si omis) |
| `limit` | `int` | 100 | Nombre maximum de tâches par cycle |
| `parallel` | `int` | 1 | Nombre de workers parallèles |

**Options :**
| Option | Description |
|--------|-------------|
| `--unique-only` | Traiter uniquement les tâches uniques |
| `--recurring-only` | Traiter uniquement les tâches récurrentes |
| `--verbose` | Afficher les logs détaillés |
| `--mute` | Supprimer toute sortie console |

---

### `getDescription(): string`

**Retourne :** `string` - Description de la directive

---

### `getAliases(): StringTypedCollection`

**Retourne :** `StringTypedCollection` - Alias de la directive (`task-watch`, `tw`)

---

### `execute(): ExitCode`

**Retourne :** `ExitCode` - Code de sortie (SUCCESS/FAILURE/RUNTIME_ERROR)

**Comportement :**
1. Génère une session via `SessionHelper`
2. Initialise le journal JSONL via `JsonlResultHelper`
3. Exécute la boucle de surveillance
4. Affiche le statut final
5. Nettoie la session dans le bloc `finally`

**Exemple d'utilisation :**
```bash
./bin/task tasks:watch 5 60 50 4 --verbose
```

---

## Méthodes privées

### `boot(): void`

**Comportement :**
1. Récupère le conteneur Laravel
2. Initialise le `OutputHandler` avec les options `mute` et `verbose`
3. Récupère l'intervalle et la durée
4. Valide que l'intervalle < durée
5. Initialise `SignalHandler`, `CycleCalculator`, `ParallelExecutor`, `ResultAggregator`

**Exceptions :** `RuntimeException` si le conteneur ou le kernel n'est pas disponible

---

### `executeCycle(): array`

**Retourne :** `array` - Tableau des résultats d'exécution

**Comportement :**
1. Récupère les options `unique-only`, `recurring-only`, `verbose`
2. Récupère la limite
3. Délègue l'exécution à `ParallelExecutor`

---

### `displayRemainingTasks(): void`

**Comportement :**
1. Compte les tâches uniques PENDING (scheduled_at <= now)
2. Compte les tâches récurrentes PLAYING
3. Compte les tâches récurrentes WAITING
4. Affiche via `OutputHandler::remainingTasks()`

**Exemple de sortie :**
```
📊 Remaining tasks:
   🔵 Unique pending   : 5
   ▶️  Recurring playing: 3
   ⏳ Recurring waiting: 10
   📦 Total remaining  : 18
```

---

### `displayFinalRemaining(): void`

**Comportement :**
1. Affiche un rapport détaillé du statut final
2. Comptes : total, terminés, en attente pour chaque type
3. Affiche un conseil pour utiliser `--verbose`

**Exemple de sortie :**
```
📊 Final Status

📌 Unique tasks:
   Total      : 25
   ✅ Completed: 20
   ⏳ Pending  : 5

🔄 Recurring tasks:
   Total      : 15
   ✅ Finished : 12
   ▶️  Playing  : 2
   ⏳ Waiting  : 1

📦 Total remaining: 8

💡 Tip: Use --verbose to see detailed execution logs
```

---

### `getInterval(): DurationVO`

**Retourne :** `DurationVO` - Intervalle configuré (minimum 2s)

---

### `getDuration(): ?DurationVO`

**Retourne :** `DurationVO|null` - Durée configurée ou null si illimitée

---

### `getLimit(): ?LimitVO`

**Retourne :** `LimitVO|null` - Limite configurée ou null

---

### `getParallelWorkers(): int`

**Retourne :** `int` - Nombre de workers (minimum 1)

---

### `waitWithSignals(DurationVO $waitTime): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$waitTime` | `DurationVO` | Temps d'attente en secondes |

**Comportement :**
1. Attend par tranches de 0.1s
2. Vérifie les signaux à chaque itération
3. Permet une interruption propre via Ctrl+C

---

### `displayStartMessage(): void`

**Comportement :** Affiche la configuration de la directive :
- Intervalle
- Durée estimée et nombre de cycles
- Nombre de workers
- Limite
- Options actives

---

## Cas d'utilisation

### Cas 1 : Surveillance avec intervalle de 5 secondes

```bash
./bin/task tasks:watch 5
```

**Comportement :**
- Exécute un cycle toutes les 5 secondes
- Traite jusqu'à 100 tâches par cycle
- Continue indéfiniment jusqu'à Ctrl+C

---

### Cas 2 : Surveillance avec durée limitée

```bash
./bin/task tasks:watch 10 300 50 2 --verbose
```

**Comportement :**
- Intervalle : 10s
- Durée totale : 300s (5 minutes)
- Limite : 50 tâches/cycle
- Workers : 2 en parallèle
- Logs détaillés

---

### Cas 3 : Traitement uniquement des tâches uniques

```bash
./bin/task tasks:watch 15 --unique-only --verbose
```

**Comportement :**
- Ignore les tâches récurrentes
- Traite uniquement les tâches uniques

---

### Cas 4 : Exécution silencieuse

```bash
./bin/task tasks:watch 30 600 100 4 --mute
```

**Comportement :**
- Aucune sortie console (sauf erreurs critiques)
- Utile pour les environnements de production

---

### Cas 5 : Exécution en parallèle avec 8 workers

```bash
./bin/task tasks:watch 2 120 200 8 --recurring-only --verbose
```

**Comportement :**
- 8 workers exécutent les tâches en parallèle
- Traitement uniquement des tâches récurrentes
- Affichage détaillé

---

### Cas 6 : Utilisation d'un alias

```bash
./bin/task task-watch 5 60 100 2 --verbose
```

---

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                     TasksWatchDirective::execute()                  │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. Génération de session                                           │
│     - SessionHelper::generate()                                     │
│     - JsonlResultHelper::init($sessionId)                           │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. boot()                                                          │
│     - Initialisation des composants                                 │
│     - Validation de l'intervalle < durée                            │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. Boucle principale                                               │
│     while (shouldContinue && !shouldStop) {                         │ 
│       - startNewCycle()                                             │
│       - executeCycle() → ParallelExecutor                           │
│       - addResults() → ResultAggregator                             │
│       - displayRemainingTasks()                                     │
│       - waitWithSignals()                                           │
│     }                                                               │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. Affichage final                                                 │
│     - displayFinalRemaining()                                       │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  5. Nettoyage                                                       │
│     - SessionHelper::delete() (dans finally)                        │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Comportement | Code retour |
|-----------|--------------|-------------|
| Conteneur non disponible | `RuntimeException` | RUNTIME_ERROR |
| Kernel non disponible | `RuntimeException` | RUNTIME_ERROR |
| Intervalle ≥ Durée | `RuntimeException` avec message | RUNTIME_ERROR |
| Exception dans la boucle | Capture → addProblem() → affichage | RUNTIME_ERROR |
| Signaux d'interruption | Arrêt propre de la boucle | SUCCESS ou FAILURE |
| Échecs dans les tâches | `hasFailures = true` | FAILURE |

**Exemple d'erreur :**
```
RuntimeException: Interval (60s) must be less than duration (30s)
```

## Performance

| Opération | Complexité | Description |
|-----------|-----------|-------------|
| `execute()` | O(n * m) | n = cycles, m = tâches/cycle |
| `executeCycle()` | O(workers) | Dépend du nombre de workers |
| `displayRemainingTasks()` | O(1) | 3 requêtes COUNT |
| `displayFinalRemaining()` | O(1) | 8 requêtes COUNT |
| `waitWithSignals()` | O(1) | Attente avec vérification |

**Recommandations :**
- Intervalle minimum : 2s (éviter la surcharge CPU)
- Workers : `cpu_cores * 1.5` à `cpu_cores * 2`
- Limit : adapter au volume de tâches (100-500)
- Utiliser `--mute` en production

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.0 | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 9 | ✅ Complet |
| Linux/macOS | ✅ (signaux PCNTL) |
| Windows | ⚠️ (signaux limités) |

## Exemple complet

```bash
# 1. Surveillance de base (toutes les 60s)
./bin/task tasks:watch

# 2. Surveillance rapide (toutes les 5s) pendant 2 minutes
./bin/task tasks:watch 5 120 50 2 --verbose

# 3. Surveillance des tâches uniques avec 4 workers en parallèle
./bin/task tasks:watch 10 300 100 4 --unique-only

# 4. Surveillance des tâches récurrentes en silence
./bin/task tasks:watch 30 600 200 8 --recurring-only --mute

# 5. Surveillance avec logs détaillés et durée illimitée
./bin/task tasks:watch 15 --verbose

# 6. Utilisation d'un alias
./bin/task task-watch 5 60 100 2 --verbose
```

## Intégration avec les cron jobs

```bash
# Exécution toutes les heures avec limite de 30 min
0 * * * * cd /var/www/project && ./bin/task tasks:watch 30 1800 100 4 --mute >> /var/log/tasks-watch.log 2>&1
```

## Voir aussi
- `ParallelExecutor` - Exécuteur de tâches parallèles
- `OutputHandler` - Gestionnaire de sortie console
- `SignalHandler` - Gestionnaire de signaux (Ctrl+C)
- `CycleCalculator` - Calcul des cycles d'exécution
- `ResultAggregator` - Agrégation des résultats
- `SessionHelper` - Gestion des sessions
- `JsonlResultHelper` - Journalisation au format JSONL
- `UniqueTaskService` - Service de tâches uniques
- `RecurringTaskService` - Service de tâches récurrentes