# Runbook — bascule vers la Managed Queue Laravel Cloud (notifications)

**TASK-1829**, P0 notifications asynchrones. Base : `develop`, VERSION 1.829.

**Ce document decrit une etape qui N'A PAS ETE EXECUTEE par cet agent.**
Elle est la SEULE des trois du decoupage officiel
(`Report/261107-13h52-Task1778-report2.md` §4) qui ecrit sur Laravel Cloud —
creation de ressource, modification d'environnement — et le mandat de
TASK-1829 l'exclut explicitement : « aucune creation de ressource Cloud »,
« STOP avant bascule PROD ». A executer **avec Cyril, au tableau de bord**,
jamais seul, jamais en autonomie.

---

## 0. Ce que TASK-1829 a deja livre (prealable a cette etape)

| # | prerequis (audit T1778 §4) | etat avant 1829 | etat apres 1829 |
|---|---|---|---|
| 1 | Laravel >= 13.19.0 | 13.7.0 | **13.42.0** |
| 2 | `aws/aws-sdk-php` en `require` direct | absent (transitif seulement) | **present, `^3.380`** |
| 3 | `timeout` du push <= 90 s (plafond Flex Starter) | 120 s | **90 s** |
| 4 | `$tries = 1` sur les deux jobs | deja le cas | inchange — **ne JAMAIS relever** (voir §4 point 4 du rapport : relever `$tries` ne cree aucune reprise utile, `prendre()` n'accepte que `pending`) |
| 5 | nom de file UNIQUE par canal | deja le cas (T1740) | inchange |
| 6 | try/catch symetrique email/push | deja le cas (T1779) | inchange |
| 7 | recuperation des livraisons `sending` orphelines | **absente** | **`notifications:recover-stale-deliveries`, planifiee toutes les 5 min** |
| 8 | connexion `cloud` + drapeau de routage | absents | **`config/queue.php` + `NOTIFICATIONS_MANAGED_QUEUE_CONNECTION`, vide par defaut — AUCUN effet tant que cette etape n'est pas franchie** |

Avant de commencer cette etape : `develop` doit contenir le merge de
TASK-1829, CI verte, et les 20 tests de
`TASK1829NotificationsQueueRoutingAndRecoveryTest` + les 4 de
`TASK1829ManagedQueuePrerequisitesTest` verts sur la branche de release.

---

## 1. Creer la Managed Queue (tableau de bord Laravel Cloud, avec Cyril)

1. Ouvrir l'environnement PROD (ou un environnement de recette Cloud
   ISOLE — recommande pour la premiere fois, voir §4) dans le tableau de
   bord Laravel Cloud.
2. Section **Queues** → **Create Managed Queue**.
3. Plan **Starter** (1 file par environnement, 3 workers maximum, calcul
   **Flex uniquement** — Pro indisponible sur Starter, memoire 256 Mio a
   2 Gio). Nommer la file explicitement (ex. `notifications`).
4. Cout attendu, calcule dans l'audit : **~0,20 $/mois** a l'echelle
   actuelle (5 Organizations, 100 publications/mois, 100 destinataires,
   2 canaux) — domine par le polling SQS, pas par le calcul.

### ⚠️ LE PIEGE, confirme par la documentation Cloud elle-meme

> « When you deploy a managed queue, Laravel Cloud sets
> `QUEUE_CONNECTION=cloud` for the environment. Any job dispatched without
> an explicit connection routes to a managed queue once one exists. »

**Immediatement apres la creation**, Laravel Cloud repose
`QUEUE_CONNECTION=cloud` sur TOUT l'environnement. Sans intervention :

- les **6 jobs non-notification** (dont les 3 queues `database` deja
  consommees chaque minute par `Schedule::command('queue:work', ...)`
  dans `routes/console.php` — indexation Dossiers/Articles, derivation des
  connaissances) basculeraient d'un coup sur `cloud`, OU resteraient
  non consommes si leur connexion explicite `database` n'est pas
  reaffirmee quelque part ;
- les **4 files orphelines** deja identifiees par TASK-1777 (jamais
  consommees, en quarantaine) basculeraient aussi sur `cloud` sans que
  personne ne l'ait decide.

5. **Immediatement apres la creation**, dans les variables d'environnement
   de ce MEME environnement Cloud : re-epingler explicitement
   `QUEUE_CONNECTION=sync` (ou la valeur deja en vigueur avant la
   creation — a verifier dans le tableau de bord avant de changer quoi que
   ce soit). Redeployer pour que la variable prenne effet.
6. Verifier, apres redeploiement, que `queue:work --queue=<queues RAG>`
   planifie (le `Schedule::command` existant) tourne toujours sans erreur
   (logs Laravel Cloud du cron `schedule:run`).

---

## 2. Activer le routage des deux jobs de notification

1. Relever, dans le tableau de bord, les variables REELLEMENT injectees
   pour la connexion `cloud` (prefixe de file, nom, region — Laravel Cloud
   les pose lui-meme ; `config/queue.php` lit `AWS_ACCESS_KEY_ID`,
   `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `CLOUD_QUEUE_PREFIX`,
   `CLOUD_QUEUE_NAME`, `CLOUD_QUEUE_SUFFIX` — **a confronter aux noms
   reels** que Cloud expose, et ajuster `config/queue.php` dans une PR
   SUIVANTE si les noms different).
2. Poser `NOTIFICATIONS_MANAGED_QUEUE_CONNECTION=cloud` — et UNIQUEMENT
   cette variable. `SendNotificationEmail` et `SendNotificationWebPush`
   la lisent (`config('queue.notifications_managed_queue_connection')`)
   et appellent `onConnection('cloud')` des qu'elle est posee — voir leurs
   docblocks, TASK-1829.
3. Redeployer.
4. **Smoke test minimal**, avant toute recette fonctionnelle : depuis
   `php artisan tinker` sur l'environnement Cloud,
   `SendNotificationEmail::dispatch('00000000-0000-0000-0000-000000000000')`
   (un UUID qui ne correspond a AUCUNE notification — le job trouvera
   `null` et se terminera sans effet, `NotificationEmailDeliverer::deliver()`
   rend `return` immediatement). Verifier dans le tableau de bord Cloud que
   le message apparait puis disparait de la file `notifications` — preuve
   que le transport SQS fonctionne, sans toucher a une vraie notification.

---

## 3. Recette fonctionnelle (avec de vrais destinataires de recette)

1. Boucle de recette, 10 a 20 membres de test (comptes QA, jamais des
   comptes reels).
2. Publier un message `@tous`.
3. Verifier, dans le tableau de bord Cloud : le job apparait dans la file
   `notifications`, est consomme par un worker, disparait.
4. Verifier, cote application : les emails arrivent (boite de reception de
   recette), les statuts de livraison (`/admin/...` inspecteur de push,
   deja existant T1740) passent `pending` → `sending` → `sent` — **jamais
   bloques en `sending`** (sinon, c'est que `notifications:recover-stale-deliveries`
   doit intervenir — attendre son prochain passage planifie, 5 minutes au
   plus, et verifier qu'il les recupere bien en `ambiguous`).
5. Mesurer le temps de reponse HTTP de la publication : il doit devenir
   **quasi instantane** (la planification seule, plus aucun aller-retour
   SMTP/FCM dans la requete) — c'est la preuve finale que le defaut P0 est
   ferme.

---

## 4. Preuve d'integration sur environnement Cloud ISOLE — PROCEDURE A SUIVRE, NON EXECUTEE ICI

Le mandat demande une preuve **distincte des tests fake**. Les tests de
TASK-1829 (`Http::fake()`, `Queue::fake()`, `QUEUE_CONNECTION=sync` de test)
prouvent le COMPORTEMENT du code ; ils ne prouvent PAS que SQS/Laravel Cloud
transporte reellement un job. Cette preuve ne peut se faire que sur une
vraie ressource Cloud, et c'est pourquoi le mandat l'exclut du travail
autonome :

1. Environnement Laravel Cloud **separe** de PROD (un environnement de
   recette/staging dedie, jamais l'environnement de production).
2. Repeter les etapes 1 a 3 de ce runbook sur CET environnement seul.
3. Couper l'acces reseau sortant vers SQS (ou retirer temporairement les
   identifiants AWS de l'environnement) pour verifier que l'absence de
   transport produit un `failed`/`ambiguous`, jamais une perte silencieuse
   — meme esprit que le test `push_injoignable` de TASK-1829, mais contre
   le VRAI transport SQS cette fois.
4. Documenter le resultat (capture du tableau de bord, extrait de
   `member_notification_deliveries`) dans un rapport `Report/` dedie avant
   toute proposition de bascule PROD.

---

## 5. Rollback

**A tout moment**, retirer `NOTIFICATIONS_MANAGED_QUEUE_CONNECTION` (ou la
vider) et redeployer : les deux jobs cessent d'appeler `onConnection()`,
`QUEUE_CONNECTION=sync` (ou la valeur en vigueur) reprend seule la main —
c'est EXACTEMENT le comportement d'avant cette etape, zero risque de perte
puisque aucune livraison n'est jamais marquee par sa connexion de transport.

La file managee elle-meme se vide des que plus rien n'y est dispatche ; elle
peut ensuite etre supprimee depuis le tableau de bord (Cyril, jamais en
autonomie). Supprimer la file AVANT de retirer le drapeau laisserait les
deux jobs tenter de publier vers une file qui n'existe plus — toujours
retirer le drapeau en PREMIER.

---

## STOP

**Aucune etape de ce runbook n'a ete executee par cet agent.** Elle
necessite une action humaine sur le tableau de bord Laravel Cloud (creation
de ressource, variables d'environnement) et l'autorisation explicite de
Cyril a chaque etape, conformement au mandat P0 : « aucune creation de
ressource Cloud », « aucune ecriture base PROD sans GO explicite », « STOP
avant bascule PROD ».
