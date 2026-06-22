# UniqueTaskValidator - Référence Technique

## Description

Validateur des tâches uniques. Fournit des méthodes pour vérifier si une tâche peut être exécutée, si elle est prête, expirée, ou si elle a atteint le nombre maximum de tentatives.

## Hiérarchie / Implémentations

```
UniqueTaskValidatorInterface
    └── UniqueTaskValidator
```

## Rôle principal

Ce validateur est le gardien de l'intégrité des tâches uniques. Il :

1. **Valide** l'intégrité de la classe de tâche
2. **Vérifie** les conditions d'exécution (`canRun`)
3. **Détermine** si une tâche est prête à être exécutée (`isReadyToRun`)
4. **Détecte** les tâches expirées (`isExpired`)
5. **Vérifie** les tentatives (`hasReachedMaxAttempts`)
6. **Rapporte** les erreurs de validation (`getValidationErrors`)

## API

### `canRun(UniqueTaskRecord $record): bool`

Vérifie si une tâche peut être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la tâche peut être exécutée

**Conditions :**
- Classe valide (existe et étend `AbstractUniqueTask`)
- Statut = `PENDING`
- `scheduled_at <= now`
- `attempts < max_attempts`
- Non expirée (`now <= scheduled_at + grace_period`)

**Exemple :**
```php
$validator = new UniqueTaskValidator();
if ($validator->canRun($record)) {
    // Exécuter la tâche
}
```

---

### `isReadyToRun(UniqueTaskRecord $record): bool`

Vérifie si une tâche est prête à être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est prête

**Conditions :**
- Classe valide
- Statut = `PENDING`
- `scheduled_at <= now`

**Exemple :**
```php
if ($validator->isReadyToRun($record)) {
    $runner->run($record);
}
```

---

### `isExpired(UniqueTaskRecord $record): bool`

Vérifie si une tâche est expirée (période de grâce dépassée).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche est expirée

**Calcul :** `now > scheduled_at + grace_period_seconds`

**Exemple :**
```php
if ($validator->isExpired($record)) {
    $repository->moveToFailed($record);
}
```

---

### `hasReachedMaxAttempts(UniqueTaskRecord $record): bool`

Vérifie si la tâche a atteint le nombre maximum de tentatives.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si `attempts >= max_attempts`

**Exemple :**
```php
if ($validator->hasReachedMaxAttempts($record)) {
    $repository->moveToFailed($record);
}
```

---

### `getValidationErrors(UniqueTaskRecord $record): StringTypedCollection`

Retourne toutes les erreurs de validation.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à valider |

**Retourne :** `StringTypedCollection` - Collection des messages d'erreur

**Erreurs possibles :**
- Classe invalide
- Statut ≠ PENDING
- `attempts >= max_attempts`
- Tâche expirée
- `scheduled_at > now`

**Exemple :**
```php
$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    echo "Erreurs: " . $errors->join(', ');
}
```

---

### `isValidTaskClass(UniqueTaskRecord $record): bool` (privée)

Vérifie que la classe de la tâche existe et étend `AbstractUniqueTask`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à valider |

**Retourne :** `bool` - `true` si la classe est valide

**Conditions :**
- `class_exists($record->fqcn)`
- `is_subclass_of($record->fqcn, AbstractUniqueTask::class)`

## Cas d'utilisation

### Cas 1 : Validation avant exécution

```php
$validator = new UniqueTaskValidator();

if (!$validator->canRun($record)) {
    $errors = $validator->getValidationErrors($record);
    throw new RuntimeException('Task cannot run: ' . $errors->join(', '));
}

// Exécuter la tâche
$runner->run($record);
```

### Cas 2 : Vérification de l'expiration

```php
$validator = new UniqueTaskValidator();

if ($validator->isExpired($record)) {
    $repository->moveToFailed($record);
    echo "Tâche expirée (scheduled_at + grace_period dépassé)";
}
```

### Cas 3 : Gestion des tentatives

```php
$validator = new UniqueTaskValidator();

if ($validator->hasReachedMaxAttempts($record)) {
    // Plus de tentatives disponibles
    $repository->moveToFailed($record);
} elseif ($validator->isReadyToRun($record)) {
    // Exécuter la tâche
    $runner->run($record);
}
```

### Cas 4 : Détection des erreurs de validation

```php
$validator = new UniqueTaskValidator();

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
│                    UniqueTaskValidator                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  canRun()                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PENDING                            │   │
│  │  ✅ $record->scheduled_at <= now                           │   │
│  │  ✅ $record->attempts < $record->max_attempts              │   │
│  │  ✅ !$this->isExpired($record)                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isReadyToRun()                                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ $record->status === PENDING                            │   │
│  │  ✅ $record->scheduled_at <= now                           │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  isExpired()                                                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ isValidTaskClass($record)                              │   │
│  │  ✅ now > scheduled_at + grace_period_seconds              │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  hasReachedMaxAttempts()                                           │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ✅ $record->attempts >= $record->max_attempts             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Messages d'erreur

| Situation | Message |
|-----------|---------|
| Classe invalide | `Invalid task class: {fqcn} does not exist or does not extend AbstractUniqueTask` |
| Statut ≠ PENDING | `Task is not in PENDING state` |
| Max attempts atteint | `Maximum attempts reached` |
| Tâche expirée | `Task has expired` |
| scheduled_at > now | `Task is not ready to run (scheduled_at in the future)` |

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

use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$validator = new UniqueTaskValidator();

// 1. Tâche valide en PENDING
$validRecord = new UniqueTaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    alias: new TaskSignatureVO('test'),
    fqcn: TestUniqueTask::class,
    payload: StrictDataObject::from([]),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

echo "canRun: " . ($validator->canRun($validRecord) ? '✅' : '❌') . "\n";
echo "isReadyToRun: " . ($validator->isReadyToRun($validRecord) ? '✅' : '❌') . "\n";
echo "isExpired: " . ($validator->isExpired($validRecord) ? '✅' : '❌') . "\n";

// 2. Tâche invalide (classe inexistante)
$invalidRecord = new UniqueTaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440001'),
    alias: new TaskSignatureVO('test'),
    fqcn: 'NonExistentClass',
    payload: StrictDataObject::from([]),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// Récupérer les erreurs
$errors = $validator->getValidationErrors($invalidRecord);
echo "Erreurs: " . $errors->join(', ') . "\n";
// Output: Invalid task class: NonExistentClass does not exist or does not extend AbstractUniqueTask

// 3. Tâche avec max attempts atteint
$maxAttemptsRecord = new UniqueTaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440002'),
    alias: new TaskSignatureVO('test'),
    fqcn: TestUniqueTask::class,
    payload: StrictDataObject::from([]),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(3),
    max_attempts: new CounterVO(3),
);

echo "hasReachedMaxAttempts: " . ($validator->hasReachedMaxAttempts($maxAttemptsRecord) ? '✅' : '❌') . "\n";
echo "canRun: " . ($validator->canRun($maxAttemptsRecord) ? '✅' : '❌') . "\n";
```

## Voir aussi

- `UniqueTaskValidatorInterface` - Interface du validateur
- `RecurringTaskValidator` - Validateur des tâches récurrentes
- `UniqueTaskRecord` - DTO des tâches uniques
- `UniqueTaskStatus` - Énumération des statuts