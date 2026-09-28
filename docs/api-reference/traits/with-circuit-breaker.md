# WithCircuitBreaker - Référence Technique

## Description

Trait optionnel fournissant un helper `withBreaker()` aux tâches qui souhaitent protéger un appel via un circuit breaker.

## Hiérarchie

```
WithCircuitBreaker (trait)
    └── Utilisé par AbstractUniqueTask / AbstractRecurringTask (via opt-in)
```

## Rôle principal

Exposer une API minimale (`withBreaker()`) aux tâches qui optent pour cette fonctionnalité, sans modifier `TaskInterface` ni les classes abstraites. Les tâches qui n'utilisent pas le trait ne sont pas affectées : aucune signature, aucun constructeur, aucun contrat d'interface n'est modifié.

## Prérequis

- L'implémentation de `CircuitBreakerFactoryInterface` doit être liée dans le conteneur.
- Le helper `app()` doit être disponible (contexte Laravel).

## API / Méthodes publiques

### `withBreaker(string $key, callable $callback): mixed`

Exécute le callback sous la protection du circuit breaker identifié par `$key`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$key` | `string` | Identifiant du circuit breaker (par ex. `firebase.fcm`, `webpush.mozilla`) |
| `$callback` | `callable(): T` | Fonction à exécuter protégée par le breaker |

**Retourne :** `mixed` - La valeur retournée par `$callback`

**Exceptions :**
- `CircuitOpenException` — le circuit est ouvert et refuse l'exécution.
- Toute exception levée par `$callback` est propagée après enregistrement de l'échec.

**Exemple :**
```php
$this->withBreaker('firebase.fcm', function (): void {
    // Appel à l'API FCM
});
```

### `circuitBreaker(string $key): CircuitBreakerInterface` (protégée)

Résout une instance de circuit breaker à partir du conteneur.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$key` | `string` | Identifiant du circuit breaker |

**Retourne :** `CircuitBreakerInterface` - L'instance résolue

**Exceptions :** Aucune n'est levée directement. Le comportement dépend de la factory liée.

**Exemple :**
```php
$breaker = $this->circuitBreaker('webpush.mozilla');
$state = $breaker->state();
```

## Cas d'utilisation

### Cas 1 : Protéger un appel à l'API FCM

```php
<?php

declare(strict_types=1);

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\CircuitBreaker\Concerns\WithCircuitBreaker;
use AndyDefer\Task\ValueObjects\DescriptionVO;

final class SendFcmNotificationTask extends AbstractUniqueTask
{
    use WithCircuitBreaker;

    protected function process(): void
    {
        $this->withBreaker('firebase.fcm', function (): void {
            // Appel à l'API HTTP v1 de FCM
        });

        $this->info(new DescriptionVO('FCM notification sent.'));
    }
}
```

### Cas 2 : Protéger un accès à une réplique DB

```php
<?php

declare(strict_types=1);

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\CircuitBreaker\Concerns\WithCircuitBreaker;

final class SyncAnalyticsTask extends AbstractRecurringTask
{
    use WithCircuitBreaker;

    protected function process(): void
    {
        $rows = $this->withBreaker('db.replica-eu', function (): array {
            return DB::connection('replica_eu')
                ->table('analytics')
                ->limit(1000)
                ->get()
                ->toArray();
        });

        // Traitement…
    }
}
```

### Cas 3 : Enchaîner plusieurs breakers

```php
protected function process(): void
{
    $result = $this->withBreaker('storage.s3', function () {
        return $this->withBreaker('internal.cdn', function () {
            // Récupération d'un fichier depuis S3 puis invalidation CDN
            return 'ok';
        });
    });
}
```

## Flux d'exécution

```
withBreaker($key, $callback)
    ↓
circuitBreaker($key)
    ↓
app(CircuitBreakerFactoryInterface::class)->for($key)
    ↓
CircuitBreakerInterface::execute($callback)
    ├── assertAllowsExecution()
    │       └── OPEN → CircuitOpenException
    ├── $callback()
    │       ├── succès → recordSuccess()
    │       └── échec  → recordFailure() puis propagation
    ↓
retourne le résultat de $callback
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Circuit ouvert | `CircuitOpenException` | `Circuit breaker [<key>] is open. Retry after <n> seconds.` |
| Callback échoue (circuit fermé) | Exception d'origine | Le message de l'exception est propagé après incrément du compteur d'échecs |
| Factory non liée dans le conteneur | `Illuminate\Contracts\Container\BindingResolutionException` | Dépend du conteneur |

## Intégration

| Composant | Rôle |
|-----------|------|
| `CircuitBreakerFactoryInterface` | Résolution du breaker par clé |
| `CircuitBreakerInterface` | Exécution protégée du callback |
| `CircuitOpenException` | Signal qu'un circuit est ouvert |
| `AbstractUniqueTask` / `AbstractRecurringTask` | Classes opt-in |

## Performance

- **Coût par appel** : 1 résolution conteneur + 1 à 3 accès cache selon l'état.
- **Complexité** : O(1).
- **Aucune écriture** lorsque le circuit est `CLOSED` et que l'appel réussit.
- **Cache backend** : dépend de l'implémentation (`array`, `file`, `redis`, `database`).

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

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\CircuitBreaker\Concerns\WithCircuitBreaker;
use AndyDefer\Task\CircuitBreaker\Exceptions\CircuitOpenException;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use Throwable;

final class NotifyPartnerTask extends AbstractUniqueTask
{
    use WithCircuitBreaker;

    protected function process(): void
    {
        try {
            $this->withBreaker('webhook.partner-x', function (): void {
                // Envoi du webhook au partenaire
            });

            $this->info(new DescriptionVO('Webhook envoyé au partenaire.'));
        } catch (CircuitOpenException $e) {
            $this->error(new DescriptionVO($e->getMessage()));
        } catch (Throwable $e) {
            $this->error(new DescriptionVO($e->getMessage()));
            throw $e;
        }
    }
}
```

## Voir aussi

- `CircuitBreaker` - Implémentation concrète du breaker
- `CircuitBreakerFactoryInterface` - Contrat de résolution par clé
- `CircuitOpenException` - Exception dédiée