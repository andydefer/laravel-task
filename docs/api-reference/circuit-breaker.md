# CircuitBreaker - Référence Technique

## Description

Implémentation concrète d'un circuit breaker basé sur le cache Laravel, gérant les états `CLOSED`, `OPEN` et `HALF_OPEN` pour éviter de sur-solliciter une ressource en panne.

## Hiérarchie

```
CircuitBreakerInterface
    └── CircuitBreaker
```

## Rôle principal

Protéger une opération dont l'échec répété est coûteux (appel API, base de données, stockage, etc.). Le breaker compte les échecs, ouvre le circuit après un seuil, puis autorise une phase de test (`HALF_OPEN`) avant de refermer le circuit si les succès s'enchaînent.

## Prérequis

- Un `Illuminate\Contracts\Cache\Repository` disponible (n'importe quel store : `array`, `file`, `redis`, `database`).
- Le helper Laravel `now()` disponible.

## États du circuit

```
CLOSED ──── (≥ failureThreshold échecs) ────▶ OPEN
OPEN   ──── (≥ openSeconds écoulées) ──────▶ HALF_OPEN
HALF_OPEN ─ (≥ successThreshold succès) ───▶ CLOSED
HALF_OPEN ─ (1 échec) ─────────────────────▶ OPEN
```

## API / Méthodes publiques

### `create(CircuitBreakerKeyVO $key, CacheRepository $cache, int $failureThreshold, int $successThreshold, int $openSeconds): self` (statique)

Construit une instance. Les trois seuils sont automatiquement **clampés à un minimum de 1**.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$key` | `CircuitBreakerKeyVO` | Clé métier identifiant le breaker |
| `$cache` | `CacheRepository` | Backend de stockage |
| `$failureThreshold` | `int` | Nombre d'échecs consécutifs avant ouverture |
| `$successThreshold` | `int` | Nombre de succès consécutifs requis en `HALF_OPEN` pour fermer |
| `$openSeconds` | `int` | Durée minimale en `OPEN` avant tentative de `HALF_OPEN` |

**Retourne :** `self`

**Exemple :**
```php
$breaker = CircuitBreaker::create(
    new CircuitBreakerKeyVO('firebase.fcm'),
    $cache,
    failureThreshold: 5,
    successThreshold: 2,
    openSeconds: 60,
);
```

### `key(): string`

Retourne la clé métier du breaker.

**Retourne :** `string` - La clé, par exemple `"firebase.fcm"`.

**Exemple :**
```php
$breaker->key(); // 'firebase.fcm'
```

### `state(): CircuitBreakerState`

Lit l'état courant depuis le cache.

**Retourne :** `CircuitBreakerState` - L'un des trois états. En cas de valeur corrompue ou absente, retourne `CLOSED`.

**Exemple :**
```php
if ($breaker->state() === CircuitBreakerState::OPEN) {
    // Circuit ouvert, ne pas appeler la ressource
}
```

### `execute(callable $callback): mixed`

Exécute le callback sous la protection du breaker. En cas de succès, enregistre un succès. En cas d'échec, enregistre un échec puis propage l'exception.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$callback` | `callable(): T` | Fonction à exécuter |

**Retourne :** `mixed` - La valeur retournée par le callback.

**Exceptions :**
- `CircuitOpenException` — le circuit est `OPEN` et refuse l'exécution.
- Toute exception levée par le callback est propagée après enregistrement de l'échec.

**Exemple :**
```php
$result = $breaker->execute(function (): string {
    return Http::get('https://api.example.com')->body();
});
```

### `recordSuccess(): void`

Enregistre un succès manuellement. Si l'état est `HALF_OPEN`, incrémente le compteur de succès et ferme le circuit une fois `successThreshold` atteint. Sinon, efface le compteur d'échecs.

**Exemple :**
```php
if ($ok) {
    $breaker->recordSuccess();
}
```

### `recordFailure(): void`

Enregistre un échec manuellement. Incrémente le compteur d'échecs et ouvre le circuit si `failureThreshold` est atteint.

**Exemple :**
```php
if (! $ok) {
    $breaker->recordFailure();
}
```

### `reset(): void`

Réinitialise complètement le breaker : supprime compteurs d'échecs, de succès, date d'ouverture, et force l'état à `CLOSED`.

**Exemple :**
```php
$breaker->reset();
```

## Comportement interne

### Clés de cache

Toutes les clés sont préfixées par `task:circuit:` :

| Suffixe | Rôle |
|---------|------|
| `:state` | État courant (`closed`, `open`, `half_open`) |
| `:failures` | Compteur d'échecs consécutifs |
| `:failures:at` | Date ISO 8601 du dernier échec |
| `:successes` | Compteur de succès en `HALF_OPEN` |
| `:opened_at` | Date ISO 8601 d'ouverture |

### Tolérance aux états incohérents

- État inconnu → retour à `CLOSED`.
- Circuit `OPEN` sans `:opened_at` → appel à `reset()`.
- Circuit `OPEN` avec `:opened_at` expiré → passage en `HALF_OPEN`.

## Cas d'utilisation

### Cas 1 : Protéger un appel API HTTP

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\CircuitBreaker\CircuitBreaker;
use AndyDefer\Task\CircuitBreaker\ValueObjects\CircuitBreakerKeyVO;

$breaker = CircuitBreaker::create(
    new CircuitBreakerKeyVO('sendgrid.api'),
    app(\Illuminate\Contracts\Cache\Repository::class),
    failureThreshold: 5,
    successThreshold: 2,
    openSeconds: 60,
);

$body = $breaker->execute(function (): string {
    return file_get_contents('https://api.sendgrid.com/v3/mail/send');
});
```

### Cas 2 : Protéger un accès base de données

```php
$breaker = CircuitBreaker::create(
    new CircuitBreakerKeyVO('db.replica-eu'),
    $cache,
    failureThreshold: 3,
    successThreshold: 1,
    openSeconds: 30,
);

$rows = $breaker->execute(function (): array {
    return DB::connection('replica_eu')->table('analytics')->limit(100)->get()->toArray();
});
```

### Cas 3 : Gérer manuellement les résultats

```php
try {
    $response = Http::get('https://api.example.com');
} finally {
    if (isset($response) && $response->successful()) {
        $breaker->recordSuccess();
    } else {
        $breaker->recordFailure();
    }
}
```

## Flux d'exécution

```
execute($callback)
    ↓
assertAllowsExecution()
    ├── CLOSED → vérifie shouldHalfOpen()
    ├── HALF_OPEN → autorise
    └── OPEN → vérifie openSeconds → HALF_OPEN ou CircuitOpenException
    ↓
$callback()
    ├── succès → recordSuccess()
    │       ├── HALF_OPEN → incrémente successes → reset() si seuil atteint
    │       └── sinon → efface les failures
    └── échec → recordFailure()
            ├── incrémente failures
            └── open() si seuil atteint
    ↓
retourne le résultat ou propage l'exception
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Circuit `OPEN` | `CircuitOpenException` | `Circuit breaker [<key>] is open. Retry after <n> seconds.` |
| Callback échoue | Exception d'origine | Propage le message après `recordFailure()` |

## Intégration

| Composant | Rôle |
|-----------|------|
| `CircuitBreakerInterface` | Contrat implémenté |
| `CircuitBreakerKeyVO` | Validation et normalisation de la clé |
| `CircuitBreakerState` | Enum d'état |
| `CircuitOpenException` | Signalement du refus d'exécution |
| `Illuminate\Contracts\Cache\Repository` | Backend de stockage |
| `WithCircuitBreaker` | Trait d'aide pour l'usage dans les tâches |

## Performance

- **Coût par `execute()`** : 1 lecture `state` + 1 lecture `failures` + 1 écriture si échec + 1 écriture éventuelle `opened_at`.
- **Complexité** : O(1).
- **Atomicité** : dépend du backend. `redis` et `database` fournissent `increment()` atomique. `array` est local au processus.
- **Concurrence** : deux workers peuvent incrémenter simultanément ; le seuil peut être franchi avec une tolérance de quelques unités, ce qui reste acceptable.
- **Aucun verrou** : le breaker est conçu pour être tolérant aux races bénignes.

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.2+ | ✅ |
| Laravel 12+ | ✅ |
| `andydefer/laravel-task` | ✅ Requis |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\CircuitBreaker\CircuitBreaker;
use AndyDefer\Task\CircuitBreaker\Enums\CircuitBreakerState;
use AndyDefer\Task\CircuitBreaker\Exceptions\CircuitOpenException;
use AndyDefer\Task\CircuitBreaker\ValueObjects\CircuitBreakerKeyVO;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

$cache = app(CacheRepository::class);

$breaker = CircuitBreaker::create(
    new CircuitBreakerKeyVO('webhook.partner-x'),
    $cache,
    failureThreshold: 3,
    successThreshold: 2,
    openSeconds: 30,
);

try {
    $payload = $breaker->execute(function (): array {
        return Http::timeout(5)->get('https://partner.example.com/hook')->json();
    });

    echo "Payload: ".json_encode($payload);
} catch (CircuitOpenException $e) {
    echo "Circuit ouvert : ".$e->getMessage();
} catch (\Throwable $e) {
    echo "Erreur : ".$e->getMessage();
}

echo "État final : ".$breaker->state()->value; // closed | open | half_open
```

## Voir aussi

- `CircuitBreakerInterface` - Contrat implémenté
- `CircuitBreakerState` - Enum d'état
- `CircuitOpenException` - Exception dédiée
- `CircuitBreakerKeyVO` - Value Object de clé
- `WithCircuitBreaker` - Trait d'usage dans les tâches