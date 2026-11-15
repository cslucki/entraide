# Addendum — runbook de bascule Managed Queue (TASK-1830)

**Complete** `Report/261114-21h22-Task1829-runbook1-bascule-managed-queue.md`
(TASK-1829, ne pas le reecrire). Trois corrections, toutes verifiees
contre la documentation OFFICIELLE Laravel Cloud
(`laravel.com/cloud/docs/queues`, `laravel.com/cloud/docs/scheduled-tasks`)
le 14/11/2026 — aucune n'etait devinee dans le runbook original.

---

## 1. La Managed Queue doit porter le nom `notifications` (ou celui configure)

Le plan **Starter n'offre qu'UNE SEULE Managed Queue par environnement**
(« Each managed queue handles one queue name »). `SendNotificationEmail` et
`SendNotificationWebPush` convergent desormais sur UNE file PARTAGEE des
que `NOTIFICATIONS_MANAGED_QUEUE_CONNECTION=cloud` — leurs noms dedies
actuels (`notifications-email`, `notifications-web-push`) ne servent plus
qu'en local/`database`/`sync`.

**A l'etape 1 du runbook original (creation de la file)** : nommer la
Managed Queue **EXACTEMENT** `notifications` — ou, si un autre nom est
prefere, poser `NOTIFICATIONS_MANAGED_QUEUE_NAME=<ce nom>` dans
l'environnement AVANT de deployer. Les deux doivent correspondre a l'octet
pres ; un ecart silencieux enverrait les jobs vers une file qui n'existe
pas.

## 2. Aucune variable d'environnement a inventer pour la connexion `cloud`

Le runbook T1829 supposait `CLOUD_QUEUE_PREFIX`/`CLOUD_QUEUE_NAME`/`CLOUD_QUEUE_SUFFIX`
— **aucun des trois n'est documente par Laravel Cloud**. La doc officielle
est explicite : « Laravel Cloud automatically sets queue credentials and
connection » — l'injection de la connexion `cloud` est geree ENTIEREMENT
par la plateforme, hors de `config/queue.php`. Notre bloc local `cloud`
dans `config/queue.php` n'est qu'un PLACEHOLDER qui permet au NOM de
connexion de resoudre sans erreur avant que la file existe ; il n'y a
**rien a configurer manuellement** pour les identifiants cote notre code —
Laravel Cloud s'en charge au deploiement, des que la Managed Queue existe
dans l'environnement.

**Etape 2 du runbook original, simplifiee** : il n'y a plus de variables
`CLOUD_QUEUE_*` a verifier ou renommer. Poser uniquement
`NOTIFICATIONS_MANAGED_QUEUE_CONNECTION=cloud` (et
`NOTIFICATIONS_MANAGED_QUEUE_NAME` si le nom de file differe de
`notifications`), puis deployer.

## 3. Le Scheduler Laravel Cloud est DESACTIVE PAR DEFAUT

`notifications:recover-stale-deliveries` (TASK-1829) suppose qu'un
`schedule:run` tourne chaque minute. **Ce n'est PAS le cas par defaut sur
Laravel Cloud** : contrairement a un serveur classique ou le cron est deja
en place, Laravel Cloud exige d'activer explicitement un bascule
« Scheduler » sur l'App cluster, PUIS de redeployer.

### Ce que cela change dans l'ordre des etapes

1. Ouvrir l'App cluster de l'environnement cible dans le tableau de bord.
2. Activer la bascule **Scheduler**.
3. **Sauvegarder ET redeployer** — Laravel Cloud ne capture la sortie de
   `php artisan schedule:list` (donc `notifications:recover-stale-deliveries`,
   planifiee toutes les 5 minutes) **qu'AU MOMENT DU DEPLOIEMENT**. Un
   changement de planification sans redeploiement ne prend PAS effet,
   silencieusement.
4. **Cet ordre est important** : activer le Scheduler AVANT ou EN MEME
   TEMPS que le drapeau `NOTIFICATIONS_MANAGED_QUEUE_CONNECTION=cloud` —
   jamais apres. Sans le Scheduler actif, une livraison orpheline
   (`sending` bloquee par un worker tue) ne serait JAMAIS recuperee en
   `ambiguous`, et resterait invisible indefiniment.

### Multi-replicas (anticipation, pas l'etat actuel)

La doc Cloud est explicite : « make sure you are using Laravel's
`onOneServer` method... Otherwise, your tasks will run on every replica ».
`notifications:recover-stale-deliveries` porte desormais `->onOneServer()`
(TASK-1830) — sans effet tant que l'App cluster tourne sur une seule
instance (mesure T1778 : 1vCPU/512Mo, PROD actuelle), mais necessaire
avant toute augmentation du nombre de replicas.

---

## STOP

Comme le runbook original : **aucune de ces etapes n'a ete executee par
cet agent.** Elles necessitent une action humaine sur le tableau de bord
Laravel Cloud et l'autorisation explicite de Cyril, conformement au mandat
« aucune creation de ressource Cloud », « STOP avant bascule PROD ».
