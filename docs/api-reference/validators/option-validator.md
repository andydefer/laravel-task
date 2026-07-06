# OptionValidator - Référence Technique

## Description

Validateur des options de ligne de commande pour les directives de tâches. Vérifie la cohérence et la validité des paramètres fournis par l'utilisateur.

## Rôle principal

Assurer que les options de ligne de commande sont valides avant l'exécution des directives en vérifiant :
- L'exclusivité des options (`--unique-only` et `--recurring-only`)
- La validité des valeurs numériques (durée, intervalle, limite)
- Le respect des contraintes minimales (intervalle ≥ 3 secondes)

## API / Méthodes publiques

### `validate(bool $uniqueOnly, bool $recurringOnly, ?string $duration, ?string $interval, ?string $limit, Console $console): ?ExitCode`

Valide les options de la commande.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$uniqueOnly` | `bool` | Option `--unique-only` active |
| `$recurringOnly` | `bool` | Option `--recurring-only` active |
| `$duration` | `string|null` | Valeur de `--duration` (secondes) |
| `$interval` | `string|null` | Valeur de `--interval` (secondes) |
| `$limit` | `string|null` | Valeur de `--limit` (nombre de tâches) |
| `$console` | `Console` | Instance pour afficher les erreurs |

**Retourne :** `ExitCode|null` - `ExitCode::INVALID_ARGUMENT` en cas d'erreur, `null` si tout est valide

**Exemple :**
```php
$validator = new OptionValidator();
$result = $validator->validate(
    uniqueOnly: $this->hasOption('unique-only'),
    recurringOnly: $this->hasOption('recurring-only'),
    duration: $this->option('duration'),
    interval: $this->option('interval'),
    limit: $this->option('limit'),
    console: $console
);

if ($result !== null) {
    return $result; // ExitCode::INVALID_ARGUMENT
}
```

## Règles de validation

### 1. Options mutuellement exclusives

| Condition | Erreur |
|-----------|--------|
| `$uniqueOnly === true` ET `$recurringOnly === true` | `Cannot use both --unique-only and --recurring-only` |

### 2. Durée (--duration)

| Condition | Erreur |
|-----------|--------|
| `$duration !== null` ET `(int) $duration <= 0` | `Duration must be a positive integer (in seconds)` |

### 3. Intervalle (--interval)

| Condition | Erreur |
|-----------|--------|
| `$interval !== null` ET `(int) $interval < 3` | `Interval must be at least 3 seconds` |

**Constante :** `self::MIN_INTERVAL_SECONDS = 3`

### 4. Limite (--limit)

| Condition | Erreur |
|-----------|--------|
| `$limit !== null` ET `(int) $limit <= 0` | `Limit must be a positive integer` |

## Cas d'utilisation

### Cas 1 : Validation d'une commande standard

**Problème :** Vérifier les options d'une commande avant exécution.

```php
$result = $validator->validate(
    uniqueOnly: false,
    recurringOnly: false,
    duration: null,
    interval: '60',
    limit: null,
    console: $console
);

if ($result === null) {
    echo "✅ Options valides\n";
    // Exécuter la commande
}
```

---

### Cas 2 : Options mutuellement exclusives

**Problème :** L'utilisateur essaie d'utiliser les deux options exclusives.

```bash
php directive tasks-watch --unique-only --recurring-only
```

```php
$result = $validator->validate(
    uniqueOnly: true,
    recurringOnly: true,
    duration: null,
    interval: null,
    limit: null,
    console: $console
);

// $result === ExitCode::INVALID_ARGUMENT
// Message : "Cannot use both --unique-only and --recurring-only"
```

---

### Cas 3 : Intervalle trop court

**Problème :** L'utilisateur définit un intervalle inférieur à 3 secondes.

```bash
php directive tasks-watch --interval=1
```

```php
$result = $validator->validate(
    uniqueOnly: false,
    recurringOnly: false,
    duration: null,
    interval: '1',
    limit: null,
    console: $console
);

// $result === ExitCode::INVALID_ARGUMENT
// Message : "Interval must be at least 3 seconds"
```

---

### Cas 4 : Durée négative ou nulle

**Problème :** L'utilisateur définit une durée invalide.

```bash
php directive tasks-watch --duration=0
```

```php
$result = $validator->validate(
    uniqueOnly: false,
    recurringOnly: false,
    duration: '0',
    interval: null,
    limit: null,
    console: $console
);

// $result === ExitCode::INVALID_ARGUMENT
// Message : "Duration must be a positive integer (in seconds)"
```

---

### Cas 5 : Limite négative ou nulle

**Problème :** L'utilisateur définit une limite invalide.

```bash
php directive tasks-watch --limit=-5
```

```php
$result = $validator->validate(
    uniqueOnly: false,
    recurringOnly: false,
    duration: null,
    interval: null,
    limit: '-5',
    console: $console
);

// $result === ExitCode::INVALID_ARGUMENT
// Message : "Limit must be a positive integer"
```

---

### Cas 6 : Validation combinée

**Problème :** Valider plusieurs options simultanément.

```bash
php directive tasks-watch --duration=10 --interval=5 --limit=100
```

```php
$result = $validator->validate(
    uniqueOnly: false,
    recurringOnly: false,
    duration: '10',
    interval: '5',
    limit: '100',
    console: $console
);

// $result === null (tout est valide)
```

## Schéma de validation

```
validate()
    │
    ├── Vérification des options exclusives
    │   └── uniqueOnly && recurringOnly → INVALID_ARGUMENT
    │
    ├── Validation de la durée
    │   └── duration !== null && duration <= 0 → INVALID_ARGUMENT
    │
    ├── Validation de l'intervalle
    │   └── interval !== null && interval < 3 → INVALID_ARGUMENT
    │
    ├── Validation de la limite
    │   └── limit !== null && limit <= 0 → INVALID_ARGUMENT
    │
    └── Tout est valide → null
```

## Intégration

### Utilisé par

| Directive | Méthode d'appel |
|-----------|----------------|
| `TasksWatchDirective` | `validateOptions()` |
| `ProcessTasksDirective` | `validateOptions()` (partiellement) |

### Dépendances

- `Console` : Pour afficher les messages d'erreur
- `ExitCode` : Pour retourner le code d'erreur approprié

## Performance

- **Complexité** : O(1) - vérifications simples
- **Mémoire** : Aucune allocation mémoire significative
- **Recommandation** : Appel unique au début de l'exécution

## Constantes

| Constante | Valeur | Description |
|-----------|--------|-------------|
| `MIN_INTERVAL_SECONDS` | `3` | Intervalle minimum autorisé (secondes) |

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Messages d'erreur

| Situation | Message |
|-----------|---------|
| Options exclusives | `Cannot use both --unique-only and --recurring-only` |
| Durée invalide | `Duration must be a positive integer (in seconds)` |
| Intervalle trop court | `Interval must be at least 3 seconds` |
| Limite invalide | `Limit must be a positive integer` |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Validators\OptionValidator;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Enums\ExitCode;

$console = app(Console::class);
$validator = new OptionValidator();

// Scénario 1 : Validation réussie
$result = $validator->validate(
    uniqueOnly: false,
    recurringOnly: false,
    duration: '300',
    interval: '10',
    limit: '50',
    console: $console
);

if ($result === null) {
    echo "✅ Toutes les options sont valides\n";
}

// Scénario 2 : Erreur d'intervalle
$result = $validator->validate(
    uniqueOnly: false,
    recurringOnly: false,
    duration: null,
    interval: '2',
    limit: null,
    console: $console
);

if ($result === ExitCode::INVALID_ARGUMENT) {
    echo "❌ Option invalide détectée\n";
    // Le message d'erreur a été affiché par $console
}
```

## Voir aussi

- `TasksWatchDirective` - Directive utilisant le validateur
- `ProcessTasksDirective` - Directive utilisant le validateur
- `ExitCode` - Codes de sortie standard
- `Console` - Service d'affichage console
---