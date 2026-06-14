# Pourquoi Laravel Task ?

## Le manifeste d'une alternative aux queues Laravel

---

## Introduction : Le constat

Laravel Queues sont un outil remarquable. Redis, Beanstalkd, Database — des drivers puissants, une intégration parfaite. Mais après des années à les utiliser, à les configurer, et à les subir, des fissures apparaissent.

Ce manifeste n'est pas une attaque contre les queues Laravel. C'est une analyse honnête de leurs limites et la présentation d'une alternative qui les adresse.

---

## Les 7 problèmes fondamentaux des queues Laravel

### 1. La dépendance à un service externe

**Le problème :** Pour utiliser les queues Laravel, vous DEVEZ avoir Redis, Beanstalkd, ou une base de données.

```
Redis → obligatoire pour la plupart des drivers performants
Beanstalkd → service externe supplémentaire
Database → acceptable, mais lourde pour des petits projets
```

**Pourquoi c'est un problème :**
- Augmente la complexité de l'infrastructure
- Un service supplémentaire à surveiller, sauvegarder, maintenir
- Impossible d'utiliser les queues sur un hébergement mutualisé basique
- Le "Hello World" d'une tâche asynchrone nécessite 3 services

**La solution Laravel Task :**
```
Fichiers JSON → zéro dépendance externe
storage/tasks/ → le filesystem, c'est tout
```

**Une tâche asynchrone = un fichier.** Pas de Redis. Pas de base de données. Pas de configuration.

---

### 2. La surcharge de configuration

**Le problème :** Mettre en place une queue Laravel demande une configuration lourde.

```php
// .env - plusieurs variables
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

// config/queue.php - parfois des dizaines de lignes
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 90,
    'block_for' => null,
    // ... encore 10 lignes
],

// php artisan queue:work --tries=3 --backoff=5 --memory=128 --timeout=60
// → 5 options juste pour démarrer
```

**Pourquoi c'est un problème :**
- Un projet simple devient complexe rapidement
- Chaque environnement (dev, staging, prod) a ses spécificités
- La documentation officielle fait 30 pages sur les queues seules

**La solution Laravel Task :**
```env
# .env - juste une variable, optionnelle
TASK_STORAGE_PATH=/custom/tasks/path

# Et c'est tout.
```

```bash
# Commande simple
./vendor/bin/directive process-tasks --limit=50
```

**Zéro configuration pour démarrer. La configuration est optionnelle.**

---

### 3. L'absence de récurrence native

**Le problème :** Les queues Laravel n'ont PAS de récurrence intégrée.

```php
// Laravel - Pour exécuter une tâche toutes les heures :
dispatch(new MyJob());  // ← une fois

// Pour la récurrence, il faut utiliser le scheduler :
$schedule->job(new MyJob())->hourly();  // ← système différent

// Et si le job échoue ? Le scheduler ne gère pas les retries.
// Et si on veut un délai personnalisé entre les exécutions ?
// Il faut tout réinventer.
```

**Pourquoi c'est un problème :**
- Deux systèmes distincts : Queues + Scheduler
- Pas de mécanisme unifié
- Les retries et la récurrence ne communiquent pas
- Une tâche récurrente échouée = perdue

**La solution Laravel Task :**
```php
// Une tâche récurrente = un seul paramètre : delaySeconds
$signature = $registry->register(
    taskClass: CleanLogsTask::class,
    payload: $payload,
    delaySeconds: 3600,  // ← Toutes les heures
);

// La même tâche gère :
// - Les retries (maxAttempts)
// - L'expiration (endAt)
// - La période de grâce (grace_period)
// - Tout est cohérent
```

**Un système unifié.** Une tâche récurrente = une tâche unique avec `delaySeconds > 0`. Même API, même comportement.

---

### 4. La complexité des tests

**Le problème :** Tester des jobs Laravel est complexe et lent.

```php
// Laravel - Tester un job
public function test_job_processes_order()
{
    // Il faut soit :
    // 1. Utiliser Fake, mais c'est limité
    Queue::fake();
    dispatch(new ProcessOrderJob());
    Queue::assertPushed(ProcessOrderJob::class);  // ← on teste juste le dispatch
    
    // 2. Tester vraiment le job
    $job = new ProcessOrderJob($order);
    $job->handle();  // ← mais ça dépend de tout Laravel
    // Base de données, cache, services... tout est là
}
```

**Pourquoi c'est un problème :**
- Les tests d'acceptance avec `dispatch()->assertPushed` ne testent pas la logique
- Les tests unitaires sont impossibles (trop de dépendances Laravel)
- Chaque test de job est un test d'intégration → lent
- Le mock des queues ne teste rien de concret

**La solution Laravel Task :**
```php
// Task - Test unitaire pur
public function test_process_deletes_old_orders()
{
    $logger = $this->createMock(Logger::class);
    $task = new CleanOrdersTask();
    $task->setLogger($logger);
    $task->setTaskId('test-123');
    $task->setSignature('clean-orders');
    
    $payload = new TaskPayloadRecord(
        type: 'clean_orders',
        payload: StrictDataObjectCollection::from([
            StrictDataObject::from(['minutes' => 30]),
        ]),
    );
    
    $task->execute($payload);  // ← Test unitaire pur
    
    $this->assertDatabaseCount('orders', 0);
}
```

**Aucun framework. Pas de base de données (sauf si vous voulez).** Votre tâche = une classe PHP testable comme n'importe quelle autre.

---

### 5. L'absence de typage fort

**Le problème :** Les jobs Laravel passent les données sous forme de tableau ou de propriétés publiques.

```php
// Laravel - 3 façons différentes, aucune typée
class ProcessOrderJob implements ShouldQueue
{
    public $orderId;  // ← propriété publique, pas typée
    
    // ou
    public function __construct(array $orderData)  // ← tableau brut
    {
        $this->orderData = $orderData;
    }
    
    // ou avec SerializesModels
    public $order;  // ← Eloquent model, sérialisé/désérialisé magiquement
}
```

**Pourquoi c'est un problème :**
- Impossible de savoir quels sont les paramètres requis
- Le typage est optionnel ou inexistant
- `SerializesModels` fait de la magie opaque
- Les erreurs arrivent à l'exécution, pas à la compilation

**La solution Laravel Task :**
```php
// Task - Payload typé et structuré
$payload = new TaskPayloadRecord(
    type: 'process_order',
    payload: StrictDataObjectCollection::from([
        StrictDataObject::from([
            'order_id' => 123,
            'force' => true,
            'priority' => 'high',
        ]),
    ]),
);

// Dans la tâche
protected function process(): void
{
    $data = $this->payload->data->first();
    $orderId = $data->order_id;     // ← typé : int
    $force = $data->force;          // ← typé : bool
    $priority = $data->priority;    // ← typé : string
    
    // Vous SAVEZ ce que vous manipulez
}
```

**Un payload typé, une seule façon de faire, pas de magie.**

---

### 6. La gestion opaque des échecs

**Le problème :** Les queues Laravel gèrent les échecs, mais la visibilité est limitée.

```php
// Laravel - Si un job échoue
// 1. Il est retenté (retry_after)
// 2. Après X tentatives, il va dans failed_jobs
// 3. La table failed_jobs contient... presque rien

// failed_jobs table :
// - connection (string)
// - queue (string)
// - payload (longtext) → tout le job sérialisé
// - exception (longtext) → la stack trace
// - failed_at (timestamp)

// Pas de compteur de tentatives
// Pas de dernière erreur lisible
// Pas de métadonnées de retry
```

**Pourquoi c'est un problème :**
- Impossible de savoir combien de fois un job a été retenté
- Le payload complet est stocké (peut être énorme)
- Pas d'API simple pour consulter les échecs
- Les retries sont opaques

**La solution Laravel Task :**
```php
// Task - Tout est visible dans le fichier JSON
{
    "id": "uuid",
    "signature": "process-order",
    "attempts": 2,           // ← compteur clair
    "max_attempts": 3,       // ← limite visible
    "last_error": "Connection timeout",  // ← erreur lisible
    "status": "pending"      // ← état actuel
}

// Et le fichier est lisible immédiatement
cat storage/tasks/pending/uuid.json
```

**Pas de table. Pas de payload caché. Un fichier, toutes les informations.**

---

### 7. Le découplage forcé des Workers

**Le problème :** Les workers Laravel doivent tourner en permanence.

```bash
# Pour garder un worker actif :
php artisan queue:work --daemon

# Avec Supervisor (recommandé) :
[program:laravel-worker]
command=php /var/www/artisan queue:work --daemon
process_name=%(program_name)s_%(process_num)02d
numprocs=8
autostart=true
autorestart=true

# 8 processus × RAM par processus = beaucoup de ressources
```

**Pourquoi c'est un problème :**
- Les workers consomment des ressources en permanence (RAM, CPU)
- Sur un petit serveur (1GB RAM), 4-5 workers peuvent saturer
- En hébergement mutualisé, impossible d'avoir des workers persistants
- Overkill pour un petit projet qui a 10 tâches par jour

**La solution Laravel Task :**
```bash
# Pas de worker permanent. Un batch, une exécution.
./vendor/bin/directive process-tasks --limit=50

# Avec cron (une fois par minute)
* * * * * cd /var/www/html && php vendor/bin/directive process-tasks --limit=50

# Avec cron (toutes les 5 minutes pour les tâches récurrentes)
*/5 * * * * cd /var/www/html && php vendor/bin/directive process-tasks --recurring-only --limit=100
```

**Ressources consommées UNIQUEMENT pendant l'exécution.** Pas de processus zombie. Pas de mémoire gaspillée.

---

## Les avantages synthétisés

| Problème Queue Laravel | Solution Laravel Task |
|------------------------|----------------------|
| Dépendance Redis/Beanstalkd | Fichiers JSON → zéro dépendance |
| Configuration complexe | Une variable d'environnement |
| Pas de récurrence native | `delaySeconds` (un système unifié) |
| Tests complexes (intégration lourde) | Tests unitaires purs (mock du logger) |
| Typage faible/inexistant | Payload typé avec `StrictDataObject` |
| Gestion opaque des échecs | `attempts`, `last_error` → fichier lisible |
| Workers permanents (RAM/CPU) | Batch execution (cron friendly) |

---

## Les inconvénients assumés

Aucune solution n'est parfaite. Laravel Task a aussi ses compromis :

### 1. Moins de performance pour des millions de tâches
- Redis peut traiter des milliers de jobs/seconde
- JSON files → limité par les I/O disque

### 2. Pas de gestion de priorité avancée
- Laravel Queues ont `->onQueue('high')`
- Task : `--unique-only` / `--recurring-only` pour séparer

### 3. Pas de délai entre les exécutions d'une même tâche (backoff)
- Laravel Queues : `backoff(5)` entre deux tentatives
- Task : `delaySeconds` est fixe

### 4. Moins de "batteries included"
- Laravel Queues : Horizon, Redis, gestion des dead letters
- Task : simple, minimaliste

### 5. Pas de compatibilité directe avec les jobs Laravel
- Vous ne pouvez PAS exécuter un Job Laravel avec Task
- C'est un choix délibéré : une philosophie différente

---

## Pour qui est ce package ?

### Vous devriez utiliser Laravel Task si :

- ✅ Vous voulez **zéro dépendance externe** (pas de Redis)
- ✅ Vous avez un **hébergement mutualisé** (pas de workers persistants)
- ✅ Vous voulez des **tests unitaires purs** pour vos tâches
- ✅ Vous avez besoin de **récurrence native** (toutes les X secondes)
- ✅ Vous voulez une **API typée** pour les paramètres
- ✅ Vous voulez **voir l'état d'une tâche** (fichier lisible immédiatement)
- ✅ Vous avez un **petit/moyen volume** (moins de 10 000 tâches/jour)

### Vous devriez rester sur Laravel Queues si :

- ❌ Vous traitez des **millions de tâches par jour**
- ❌ Vous avez besoin de **priorités complexes**
- ❌ Vous utilisez déjà **Horizon** ou un système de queues avancé
- ❌ Vous avez besoin de **backoff personnalisé** entre les tentatives
- ❌ Vous préférez la solution "officielle"

---

## Cas d'usage concrets

### Idéal pour Laravel Task :
- Envoi d'emails de bienvenue (1000/jour)
- Nettoyage de logs (1/heure)
- Génération de rapports (1/jour)
- Synchronisation API externe (toutes les 5 minutes)
- Petits projets (blog, boutique simple, site vitrine)
- Projets en hébergement mutualisé (OVH, 1&1, Hostinger)

### Mieux avec Laravel Queues :
- Traitement de paiements (10 000/heure)
- Pipeline de données temps réel
- Applications avec Horizon (monitoring poussé)
- Systèmes nécessitant un backoff exponentiel

---

## Conclusion : Une question de philosophie

Laravel Queues et Laravel Task ne sont pas en compétition. Ils répondent à des besoins différents.

**Laravel Queues** excelle quand :
- Vous avez un volume massif de jobs
- Vous avez Redis/Beanstalkd déjà en place
- Vous avez besoin de workers permanents
- La complexité est justifiée

**Laravel Task** excelle quand :
- Vous voulez zéro dépendance externe
- Vous voulez des tests unitaires purs
- Vous avez besoin de récurrence native
- Vous voulez contrôler les ressources (cron friendly)
- Vous développez en hébergement mutualisé

**Laravel Task n'est pas un remplacement des Queues. C'est une alternative pour ceux qui veulent une architecture différente : plus simple, plus légère, plus accessible.**

---

## Un dernier mot

Ce package est né de la frustration. La frustration de devoir installer Redis pour envoyer 3 emails par jour. La frustration de ne pas pouvoir tester ses jobs unitairement. La frustration de voir des tâches simples devenir complexes.

Mais cette frustration a donné naissance à une solution. Pas parfaite, mais honnête.

**Laravel Task : pour ceux qui veulent des tâches asynchrones comme ils écrivent le reste de leur code : simple, testable, et découplé.**

---

*Andy Defer*

---

## Annexe : Comparaison côte à côte

### Laravel Queue
```php
// Job
class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function __construct(public Order $order) {}
    
    public function handle(): void
    {
        $this->order->process();
    }
}

// Dispatch
ProcessOrderJob::dispatch($order);

// Worker (permanent)
php artisan queue:work --daemon

// Failed jobs table
// Configuration lourde
// Pas de récurrence native
```

### Laravel Task
```php
// Task
final class ProcessOrderTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'process-order',
            description: 'Process an order',
            maxAttempts: 3,
        );
    }
    
    protected function process(): void
    {
        $data = $this->payload->data->first();
        $order = Order::find($data->order_id);
        $order->process();
    }
}

// Register
$taskId = $registry->register(
    taskClass: ProcessOrderTask::class,
    payload: $payload,
);

// Batch execution
./vendor/bin/directive process-tasks --limit=50

// Récurrence native (toutes les heures)
$signature = $registry->register(
    taskClass: CleanLogsTask::class,
    payload: $payload,
    delaySeconds: 3600,
);
```

La différence ? **La simplicité.** Et c'est toute la philosophie.
```