# RecurringTaskValidator - Référence Technique

## Description

Validateur des tâches récurrentes. Fournit des méthodes pour vérifier si une tâche peut être exécutée, si elle est prête, expirée, ou si elle doit être ré-exécutée selon son intervalle.

## Hiérarchie / Implémentations

```
RecurringTaskValidatorInterface
    └── RecurringTaskValidator
```

## Rôle principal

Ce validateur est le gardien de l'intégrité des tâches récurrentes. Il :

1. **Valide** l'intégrité de la classe de tâche
2. **Vérifie** les conditions d'exécution (`canRun`)
3. **Détermine** si une tâche est prête à démarrer (`isReadyToRun`)
4. **Détecte** les tâches expirées (`isExpired`, `shouldMoveToFinished`)
5. **Calcule** si une tâche doit être ré-exécutée (`shouldRunAgain`)
6. **Rapporte** les erreurs de validation (`getValidationErrors`)

## API

### `canRun(RecurringTaskRecord $record): bool`

Vérifie si une tâche peut être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la tâche peut être exécutée

**Conditions :**
- Classe valide (existe et étend `AbstractRecurringTask`)
- Statut = `PLAYING`
- `end_at` non dépassé

**Exemple :**
```php
$validator = new RecurringTaskValidator();
if ($validator->canRun($record)) {
    // Exécuter la tâche
}
```

---

### `isReadyToRun(RecurringTaskRecord $record): bool`

Vérifie si une tâche en `WAITING` est prête à passer en `PLAYING`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est prête

**Conditions :**
- Classe valide
- Statut = `WAITING`
- `start_at` non null
- `start_at <= now`

**Exemple :**
```php
if ($validator->isReadyToRun($record)) {
    $repository->moveToPlaying($record);
}
```

---

### `isExpired(RecurringTaskRecord $record): bool`

Vérifie si une tâche est expirée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est expirée

**Conditions :**
- `end_at` non null
- `end_at < now`

**Exemple :**
```php
if ($validator->isExpired($record)) {
    $repository->moveToFinished($record);
}
```

---

### `shouldMoveToFinished(RecurringTaskRecord $record): bool`

Vérifie si une tâche doit être terminée (alias de `isExpired`).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être terminée

---

### `shouldRunAgain(RecurringTaskRecord $record): bool`

Vérifie si une tâche en `PLAYING` doit être exécutée à nouveau selon son intervalle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être ré-exécutée

**Conditions :**
- Classe valide
- Statut = `PLAYING`
- Non expirée
- `last_run_at` null OU `(now - last_run_at) >= interval_seconds`

**Exemple :**
```php
if ($validator->shouldRunAgain($record)) {
    // Exécuter la tâche
} else {
    // Attendre le prochain cycle
}
```

---

### `getValidationErrors(RecurringTaskRecord $record): StringTypedCollection`

Retourne toutes les erreurs de validation.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à valider |

**Retourne :** `StringTypedCollection` - Collection des messages d'erreur

**Erreurs possibles :**
- Classe invalide
- Statut incorrect
- Tâche expirée
- Intervalle non atteint

**Exemple :**
```php
$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    echo "Erreurs: " . $errors->join(', ');
}
```

---

### `isValidTaskClass(RecurringTaskRecord $record): bool` (privée)

Vérifie que la classe de la tâche existe et étend `AbstractRecurringTask`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la classe est valide

**Conditions :**
- `class_exists($record->fqcn)`
- `is_subclass_of($record->fqcn, AbstractRecurringTask::class)`

## Cas d'utilisation

### Cas 1 : Validation avant exécution

```php
$validator = new RecurringTaskValidator();

if (!$validator->canRun($record)) {
    $errors = $validator->getValidationErrors($record);
    throw new RuntimeException('Task cannot run: ' . $errors->join(', '));
}

// Exécuter la tâche
```

### Cas 2 : Vérification de l'intervalle

```php
$validator = new RecurringTaskValidator();

if ($validator->shouldRunAgain($record)) {
    // L'intervalle est atteint, exécuter
    $runner->run($record);
} else {
    // L'intervalle n'est pas atteint, ne pas exécuter
    echo "Prochaine exécution dans " . $this->getNextRunDelay($record) . " secondes";
}
```

### Cas 3 : Gestion des tâches en WAITING

```php
$validator = new RecurringTaskValidator();

if ($validator->isReadyToRun($record)) {
    // La tâche est prête à démarrer
    $repository->moveToPlaying($record);
} elseif ($validator->shouldMoveToFinished($record)) {
    // La tâche est expirée avant d'avoir démarré
    $repository->moveToFinished($record);
}
```

### Cas 4 : Détection des erreurs de validation

```php
$validator = new RecurringTaskValidator();

$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    foreach ($errors as $error) {
        echo "❌ $error\n";
    }
} else {
    echo "✅ Tâche valide\n";
}
```

## Flux de validation

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskValidator                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  canRun()                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PLAYING                            │   │
│  │  ✅ !$this->isExpired($record)                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isReadyToRun()                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === WAITING                            │   │
│  │  ✅ $record->start_at !== null                             │   │
│  │  ✅ strtotime($record->start_at) <= now                   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isExpired()                                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ $record->end_at !== null                               │   │
│  │  ✅ strtotime($record->end_at) < now                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  shouldRunAgain()                                                  │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PLAYING                            │   │
│  │  ✅ !$this->isExpired($record)                             │   │
│  │  ✅ $record->last_run_at === null                          │   │
│  │     OU (now - last_run_at) >= interval                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Messages d'erreur

| Situation | Message |
|-----------|---------|
| Classe invalide | `Invalid task class: {fqcn} does not exist or does not extend AbstractRecurringTask` |
| Statut WAITING | `Task is in WAITING state, not PLAYING` |
| Statut PAUSED | `Task is in PAUSED state` |
| Statut FINISHED | `Task is already FINISHED` |
| Statut invalide | `Task is not in PLAYING or WAITING state` |
| Expirée | `Task has expired (end_at reached)` |
| Pas prête | `Task is not ready to run (start_at not reached)` |
| Intervalle non atteint | `Interval not reached (next run in X seconds)` |

## Performance

- **Complexité** : O(1) - toutes les opérations sont constantes
- **Mémoire** : Aucune allocation mémoire significative
- **Validation** : Utilise `class_exists` et `is_subclass_of` (rapides)
- **Dates** : Utilise `strtotime` pour les comparaisons

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$validator = new RecurringTaskValidator();

// 1. Tâche valide en PLAYING
$validRecord = new RecurringTaskRecord(
    alias: new TaskSignatureVO('test'),
    fqcn: TestRecurringTask::class,
    payload: StrictDataObject::from([]),
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    end_at: new Iso8601DateTimeVO(now()->addDays(1)->toIso8601String()),
    last_run_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    status: RecurringTaskStatus::PLAYING,
);

echo "canRun: " . ($validator->canRun($validRecord) ? '✅' : '❌') . "\n";
echo "shouldRunAgain: " . ($validator->shouldRunAgain($validRecord) ? '✅' : '❌') . "\n";

// 2. Tâche invalide (classe inexistante)
$invalidRecord = new RecurringTaskRecord(
    alias: new TaskSignatureVO('test'),
    fqcn: 'NonExistentClass',
    payload: StrictDataObject::from([]),
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    end_at: new Iso8601DateTimeVO(now()->addDays(1)->toIso8601String()),
    last_run_at: new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String()),
    status: RecurringTaskStatus::PLAYING,
);

// Récupérer les erreurs
$errors = $validator->getValidationErrors($invalidRecord);
echo "Erreurs: " . $errors->join(', ') . "\n";
// Output: Invalid task class: NonExistentClass does not exist or does not extend AbstractRecurringTask
```

## Voir aussi

- `RecurringTaskValidatorInterface` - Interface du validateur
- `UniqueTaskValidator` - Validateur des tâches uniques
- `RecurringTaskRecord` - DTO des tâches récurrentes
- `RecurringTaskStatus` - Énumération des statuts