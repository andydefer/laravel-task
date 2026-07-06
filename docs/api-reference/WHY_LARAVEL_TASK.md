# WHY LARAVEL TASK

## Le moteur de tâches persistantes qui fonctionne avec un simple cron

---

## L'histoire qui a donné naissance à Laravel Task

Imaginez la situation suivante :

Un développeur freelance crée un petit SaaS de gestion de leads pour un client. Le client est hébergé sur un hébergement mutualisé classique (OVH, HostGator ou 1&1). Une fonctionnalité simple est demandée : **envoyer un email de relance 30 minutes après chaque inscription**.

Le développeur connaît Laravel Queue. Il sait que c'est la solution adaptée pour les tâches différées. Il configure un job, ajoute un `delay(now()->addMinutes(30))`, et le déploie.

Puis il se rend compte que sur l'hébergement mutualisé de son client, il ne peut pas exécuter `php artisan queue:work` en permanence. Il faudrait un VPS. Il faudrait configurer Supervisor pour maintenir le worker actif. Parfois, il faudrait même installer Redis.

**Une fonctionnalité simple nécessite soudainement une infrastructure complexe.**

Le développeur a alors deux options :

1. **Proposer un VPS au client** → Augmentation du coût et de la complexité
2. **Abandonner la fonctionnalité** → Perte de valeur pour le client

C'est précisément ce problème que Laravel Task résout. Il permet d'exécuter des tâches avancées (planification dynamique, retry, états, pause, reprise) sur n'importe quel hébergement, avec un simple cron.

---

## Mais d'abord, quels sont les outils Laravel natifs ?

### Laravel Scheduler

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('emails:send')->hourly();
    $schedule->command('cache:clear')->daily();
}
```

**Ce qu'il fait bien :**
- ✅ Planification simple et récurrente
- ✅ Fonctionne avec un simple cron
- ✅ Idéal pour les tâches système régulières

**Ses limites :**
- ❌ Pas d'état (on ne sait pas si la tâche a réussi ou échoué)
- ❌ Pas de retry automatique
- ❌ Pas de pause/reprise
- ❌ Pas de planification dynamique ("dans 30 minutes")

### Laravel Queue

```php
// ✅ Dispatch d'un job différé
SendWelcomeEmail::dispatch($user)->delay(now()->addMinutes(5));

// ✅ Retry automatique
class SendWelcomeEmail implements ShouldQueue
{
    public $tries = 3;
    public $backoff = [60, 300];
}
```

**Ce qu'il fait bien :**
- ✅ Tâches différées et asynchrones
- ✅ Retry automatique
- ✅ Parallélisme (via plusieurs workers)
- ✅ Idéal pour les traitements lourds

**Ses limites :**
- ❌ Nécessite un worker permanent (`queue:work`)
- ❌ Nécessite Supervisor ou un outil équivalent
- ❌ Nécessite généralement un VPS
- ❌ Pas de pause/reprise
- ❌ Pas de date de fin automatique

---

## Et Laravel Task dans tout ça ?

**Laravel Task n'est ni un remplacement du Scheduler, ni un remplacement des Queues.** Il répond à un besoin complémentaire : **un moteur de tâches persistantes avec un cycle de vie complet, fonctionnant avec un simple cron.**

### Ce qui le rend différent

Là où le Scheduler exécute des commandes et où les Queues transportent des messages, **Laravel Task gère le cycle de vie complet d'une tâche**.

Chaque tâche devient un **objet persistant** dans la base de données, avec :

- ✅ **Un état** : PENDING, PLAYING, COMPLETED, FAILED, CANCELED
- ✅ **Un historique** : chaque tentative, chaque échec, chaque succès
- ✅ **Un contrôle total** : pause, reprise, annulation, reprogrammation
- ✅ **Une résilience** : retry automatique, grace period, max attempts
- ✅ **Un débogage intégré** : contexte, erreurs, durée d'exécution

---

## La valeur ajoutée de Laravel Task

### 1. Des tâches qui ont une mémoire

Avec le Scheduler, une tâche exécutée est une tâche oubliée. On ne sait pas si elle a réussi ou échoué. Avec Laravel Task, chaque tâche garde une trace complète de son exécution, de ses tentatives, et de son état final.

### 2. Une infrastructure simplifiée

Pas besoin de VPS. Pas besoin de Supervisor. Pas besoin de Redis. Un simple cron (une ligne dans crontab) suffit pour faire fonctionner tout le système.

### 3. Un contrôle sans précédent

```php
// ✅ Mettre en pause une tâche
$taskService->pause($alias);

// ✅ Reprendre une tâche mise en pause
$taskService->resume($alias);

// ✅ Annuler définitivement
$taskService->cancel($alias, new DescriptionVO('Problème technique'));

// ✅ Reprogrammer à une autre date
$taskService->reschedule($alias, now()->addDays(2));

// ✅ Voir tout l'historique d'une tâche
$debug = $taskService->getDebug($alias);
```

### 4. Une flexibilité maximale

- Planification à date fixe ou dynamique
- Tâches uniques ou récurrentes
- Date de début et de fin (pour les récurrentes)
- Tentatives configurables avec grace period
- Exécution parallèle intégrée

---

## En une phrase

> **Laravel Queue transporte des messages. Laravel Task gère le cycle de vie complet d'une tâche.**

---

## Quand utiliser quoi ?

| Besoin | Scheduler | Queue | Laravel Task |
|--------|-----------|-------|--------------|
| "Nettoyer le cache toutes les heures" | ✅ | ❌ | ✅ |
| "Envoyer un email 5 min après l'inscription" (sur VPS) | ❌ | ✅ | ✅ |
| "Envoyer un email 5 min après l'inscription" (sur hébergement SHARED) | ❌ | ❌ | ✅ |
| "Tâche avec état et suivi" | ❌ | ❌ | ✅ |
| "Pause / Reprise d'une tâche" | ❌ | ❌ | ✅ |
| "Tâche qui s'arrête après 10 exécutions" | ❌ | ❌ | ✅ |
| "Retry automatique" | ❌ | ✅ | ✅ |
| "Exécution parallèle" | ❌ | ✅ | ✅ |
| "Exécution sans infrastructure lourde" | ✅ | ❌ | ✅ |

---

## Cas d'usage concrets

### 1. SaaS et abonnements

```php
// Envoyer un rappel J-1 avant expiration
$taskService->register(RenewalReminderTask::class, $payload, [
    'scheduled_at' => $user->subscription_end_at->subDay(),
    'max_attempts' => 2,
]);

// Désactiver à la date d'expiration
$taskService->register(ExpireSubscriptionTask::class, $payload, [
    'scheduled_at' => $user->subscription_end_at,
    'max_attempts' => 3,
]);
```

### 2. E-commerce et paniers abandonnés

```php
// Email de relance 30 min après abandon
$taskService->register(AbandonedCartReminder::class, $payload, [
    'scheduled_at' => now()->addMinutes(30),
    'max_attempts' => 2,
]);

// Email de suivi J+3
$taskService->register(FollowUpEmail::class, $payload, [
    'scheduled_at' => now()->addDays(3),
    'max_attempts' => 2,
]);
```

### 3. Intégrations API et Webhooks

```php
// Appel API avec retry automatique
$taskService->register(ApiCallTask::class, $payload, [
    'scheduled_at' => now()->addSeconds(5),
    'max_attempts' => 3,
    'grace_period' => 3600, // 1h pour réessayer
]);

// Visualisation des tentatives
$debug = $taskService->getDebug($alias);
// [
//   { attempt: 1, status: 'failed', error: 'Timeout' },
//   { attempt: 2, status: 'failed', error: 'Rate limit' },
//   { attempt: 3, status: 'succeeded', duration: 1200 },
// ]
```

### 4. Campagnes marketing temporaires

```php
// Newsletter hebdomadaire sur 4 semaines
$campaign = $taskService->register(NewsletterTask::class, $payload, [
    'interval_seconds' => 604800, // 7 jours
    'end_at' => now()->addWeeks(4),
    'max_attempts' => 2,
]);

// Pause si désabonnement
$taskService->pause($campaign);

// Reprise si réabonnement
$taskService->resume($campaign);
```

### 5. Maintenance et nettoyage

```php
// Nettoyage nocturne uniquement
$taskService->register(CacheCleanTask::class, $payload, [
    'interval_seconds' => 3600,
    'start_at' => Carbon::now()->setTime(23, 0),
    'end_at' => Carbon::now()->setTime(6, 0),
]);
```

### 6. Workflows métier complexes

```php
// Orchestrer un workflow en plusieurs étapes
$steps = [
    Step1Task::class => now()->addMinutes(1),
    Step2Task::class => now()->addMinutes(5),
    Step3Task::class => now()->addMinutes(10),
    FinalizeTask::class => now()->addMinutes(15),
];

foreach ($steps as $class => $scheduledAt) {
    $taskService->register($class, $payload, [
        'scheduled_at' => $scheduledAt,
        'max_attempts' => 2,
    ]);
}
```

---

## Ce que le développeur gagne en confort

### 1. Une API claire et intuitive

```php
// Enregistrement d'une tâche
$alias = $taskService->register(MyTask::class, $payload, $config);

// Gestion
$taskService->pause($alias);
$taskService->resume($alias);
$taskService->cancel($alias);
$taskService->reschedule($alias, new Date);

// Monitoring
$debug = $taskService->getDebug($alias);
$count = $taskService->countPending();
```

### 2. Un débogage facilité

```bash
./vendor/bin/directive process-tasks --verbose
```

```
=== Failed Tasks ===
  ❌ unique@abc-123: Connection timeout (attempts: 2/3)
  ❌ unique@def-456: API returned 500 (attempts: 1/3)
  ❌ recurring@ghi-789: Database connection lost (attempts: 3/5)
```

### 3. Une persistance complète

Chaque tâche est stockée en base de données avec toutes ses informations :
- État actuel
- Historique des tentatives
- Dates clés (création, planification, exécution, fin)
- Payload (données de la tâche)
- Erreurs (le cas échéant)
- Durée d'exécution

### 4. Une exécution parallèle intégrée

```bash
# Un simple paramètre suffit
./vendor/bin/directive tasks-watch --parallel=4
```

---

## Fonctionne sur tous les hébergements

| Type d'hébergement | Scheduler | Queue | Laravel Task |
|--------------------|-----------|-------|--------------|
| Hébergement SHARED (OVH, HostGator, 1&1) | ✅ | ❌ | ✅ |
| VPS | ✅ | ✅ | ✅ |
| Serveur dédié | ✅ | ✅ | ✅ |
| Cloud (AWS, GCP, Azure) | ✅ | ✅ | ✅ |

Laravel Task fonctionne partout où Laravel fonctionne, sans configuration complexe.

---

## Installation et mise en route

```bash
# 1. Installation
composer require andydefer/laravel-task

# 2. Migrations
php artisan vendor:publish --tag=task-migrations
php artisan migrate

# 3. Ajout du cron (une seule ligne)
* * * * * cd /chemin/projet && ./vendor/bin/directive tasks-watch

# 4. Création d'une tâche
# La classe doit étendre AbstractUniqueTask ou AbstractRecurringTask
# et implémenter la méthode process()
```

---

## Conclusion

Laravel Task n'est pas une réécriture des Jobs Laravel. Ce n'est pas un remplacement du Scheduler.

**C'est un complément à l'écosystème Laravel.**

Il répond à un besoin précis : **gérer des tâches persistantes avec un cycle de vie complet, sans infrastructure lourde.**

- ✅ Quand vous avez besoin d'état, de suivi, de contrôle
- ✅ Quand vous n'avez pas de VPS
- ✅ Quand vous voulez simplifier votre infrastructure
- ✅ Quand vous voulez une API claire et des tâches faciles à gérer

**Laravel Task apporte une solution élégante à un problème concret.**

---

## Liens utiles

- [📦 Documentation complète](https://github.com/andydefer/laravel-task)
- [🐛 Signaler un bug](https://github.com/andydefer/laravel-task/issues)
- [💡 Proposer une fonctionnalité](https://github.com/andydefer/laravel-task/issues)

---

**Le moteur de tâches persistantes pour Laravel.** 🚀