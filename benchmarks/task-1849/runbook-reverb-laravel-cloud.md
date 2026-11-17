# RUNBOOK REVERB — Laravel Cloud

TASK-1849. Audit en lecture seule (CLI Cloud, documentation officielle,
code du dépôt) + préparation d'un plan d'activation. **Aucune ressource
Cloud provisionnée, aucun changement sur `main` ou PROD.** Toute mesure
d'infrastructure ci-dessous est datée et sourcée.

---

## 1. Architecture retenue — recommandation

**Option A : WebSockets Reverb managés Laravel Cloud.** Recommandée.

Raisons : zéro infrastructure à opérer (pas de process `reverb:start` à
superviser, pas de proxy à écrire), protocole Pusher compatible avec le
code DÉJÀ écrit (TASK-1839 à TASK-1847 utilisent déjà `laravel-echo`/
`pusher-js`, driver `reverb` — aucun changement de code requis pour
basculer de l'auto-hébergement local vers le cluster managé), variables
`REVERB_*`/`VITE_REVERB_*` injectées automatiquement à chaque déploiement
(pas de secret à gérer à la main), et un tarif plancher très bas (5 $/mois
pour 100 connexions, voir section 4) pour un pilote à l'échelle visée par
MASTER (10-20 puis 50 membres).

**Option B (auto-hébergement) — écartée pour la PROD**, gardée pour le
développement local uniquement (déjà en place, `ai/scripts/reverb-local.sh`).
Sur Laravel Cloud, l'app tourne sur une instance `flex.g-1vcpu-512mb`
**sans processus d'arrière-plan permanent** (mesuré : voir section 2) —
faire tourner `reverb:start` en continu sur CETTE instance n'est pas
praticable sans un process manager dédié que Laravel Cloud ne fournit pas
pour ce type d'instance. L'auto-hébergement exigerait un serveur
supplémentaire (VPS dédié), plus cher et plus complexe à exploiter que
l'option managée pour notre échelle.

---

## 2. Prérequis — état RÉEL de l'environnement PROD (`main`), mesuré en lecture seule le 17/11/2026

Via `/home/cyril/.config/composer/vendor/bin/cloud environment:get main --json`
et `instance:list main --json` (lecture seule, `--show-sensitive` utilisé
UNIQUEMENT pour alimenter une variable shell jamais affichée, jamais pour
imprimer une valeur sensible — voir section 5).

| Fait | Valeur mesurée 17/11 | Note |
|---|---|---|
| `websocketApplicationId` | **`null`** | Aucun cluster WebSocket provisionné — à créer (section 6) |
| `cacheId` | **`null`** | Aucun cache managé (pas de Redis/Valkey) |
| Instances | **2** (`App`, `notifications`) | Changement depuis la dernière mesure (05-12/11 : 1 seule instance) |
| Instance `App` | `flex.g-1vcpu-512mb`, `usesScheduler: true`, `minReplicas=maxReplicas=1` | **`usesScheduler` est passé à `true`** (était `false` au 05/11 — le CDC alertait sur ce point, résolu depuis sans TASK dédiée identifiée) |
| Instance `notifications` | `type: managed_queue`, `mq.flex.256mb`, `queueStatus: available`, `min=0/max=1` | **Nouvelle** depuis le 12/11 — le runbook T1829/T1830 a été exécuté, la Managed Queue existe réellement en PROD |
| `usesHibernation` | **`false`** | Changement depuis le 05/11 (`true` alors) — à reconfirmer avec Cyril, cette mesure contredit la mémoire de session la plus ancienne |
| `QUEUE_CONNECTION` | **`sync`** (confirmé, valeur jamais affichée dans ce rapport — voir section 5 sur la méthode) | **Fait CRITIQUE pour Reverb**, voir ci-dessous |
| `NOTIFICATIONS_MANAGED_QUEUE_CONNECTION` | présente (valeur masquée) | Sert `SendNotificationEmail`/`SendNotificationWebPush` UNIQUEMENT (grep du code) — PAS la diffusion Reverb |

### Pourquoi `QUEUE_CONNECTION=sync` change tout pour Reverb

Les 4 événements de diffusion (`LoopMessageCreated`, `ConversationMessageCreated`,
`MemberNotificationCreated`, `MessageUnreadCountChanged`) implémentent
`ShouldBroadcast` (PAS `ShouldBroadcastNow`) — Laravel met donc
normalement un job `BroadcastEvent` EN FILE avant de le diffuser. Or
`routes/console.php` planifie `queue:work` avec un **allowlist littéral**
de noms de file (indexation Dossier/Article, compilation de conversation)
qui **exclut délibérément `default`** — le commentaire du code dit
explicitement pourquoi : 207 jobs historiques sur `default` ne sont
JAMAIS consommés.

**Sous un `QUEUE_CONNECTION` asynchrone (`database`/`redis`) sans worker
dédié pour `default`, activer un pilote Reverb aurait mis les 4 jobs de
diffusion en file SANS JAMAIS les consommer — silence total, aucune
erreur, juste zéro diffusion.** Heureusement, **`QUEUE_CONNECTION=sync`
en PROD élimine ce risque** : sous `sync`, "mettre en file" EXÉCUTE le job
immédiatement, dans le même process — exactement le comportement mesuré
localement par TASK-1848 (latence quasi nulle, 0-1ms). **Point à
RECONFIRMER avant toute activation** : si `QUEUE_CONNECTION` change un
jour (ex. passage à `database` pour d'autres raisons), les 4 événements
de diffusion DEVRONT soit passer à `ShouldBroadcastNow`, soit être
explicitement routés vers une file consommée. Ne jamais supposer que
`sync` restera la valeur par défaut.

### Autres éléments déjà examinés (code du dépôt)

- `config/broadcasting.php` / `config/reverb.php` : driver `reverb`,
  `client_options` **vide** pour la connexion `reverb` — aucun timeout
  HTTP explicite configuré pour les appels de diffusion sortants vers
  Reverb. Voir section 5 (risque identifié).
- `resources/js/echo.js` : une connexion `window.Echo` UNIQUE partagée
  par les 4 pilotes (TASK-1843), instanciée seulement si au moins un
  drapeau `VITE_*` vaut `true` ET que `VITE_REVERB_APP_KEY`/`HOST` sont
  renseignées — aucune logique de reconnexion CUSTOM, repose entièrement
  sur la reconnexion automatique native de `pusher-js`. **Lacune déjà
  identifiée par TASK-1840** : la reprise après coupure Reverb a été
  observée tantôt immédiate, tantôt exigeant un rechargement de page —
  non résolu, à traiter avant un rollout large (section 5/9).
- `/broadcasting/auth` : authentification REELLE testée en conditions
  réelles par TASK-1848 (login web + jeton CSRF + session), fonctionne.
- Canaux (`routes/channels.php`) : `loop.{id}` (appartenance + Organization
  vérifiées), `user.{id}` (identité stricte + Organization non nulle),
  `conversation.{id}` (participation + Organization DE LA TRANSACTION,
  jamais `currentOrganization()`) — tous SANS payload sensible dans la
  réponse d'autorisation. **Limite déjà documentée dans le code** : le
  contrôle d'accès au canal `conversation.*` s'exécute à l'ABONNEMENT, pas
  en continu — un socket déjà ouvert ne serait pas révoqué si une
  participation devenait un jour mutable (non applicable aujourd'hui,
  `buyer_id`/`seller_id` sont immuables).
- Session/CSRF/cookies : `SESSION_DRIVER=database`, cookies standards
  Laravel (`httponly`, `secure`, `samesite=lax` mesurés localement) — rien
  de spécifique à Reverb à changer ici.

---

## 3. Stratégie d'activation progressive — préparée, PAS exécutée

### 3.1 Piège du drapeau Vite — déjà documenté, à répéter ici

`VITE_LOOP_CHAT_REALTIME_ENABLED` (et les 3 autres `VITE_*`) sont figées
**au moment du build** (Vite les remplace littéralement dans le bundle
JS). Changer la variable d'environnement SEULE, sans nouveau déploiement
(donc sans nouveau `npm run build`), **n'a AUCUN effet côté navigateur** —
piège déjà vérifié empiriquement par TASK-1848 sur ce même dépôt. Sur
Laravel Cloud, un changement de variable d'environnement NE déclenche PAS
automatiquement un nouveau build : il faut explicitement redéployer après
tout changement de ces 4 drapeaux (ou de l'attachement du cluster
WebSocket — confirmé par la doc officielle : « After you attach the
WebSocket application to the environment, redeploy your application for
the changes to take effect »).

### 3.2 Ordre d'activation recommandé, pilote par pilote

1. **Provisionner le cluster WebSocket** (palier 100 connexions, 5 $/mois
   — section 4), l'attacher à l'environnement `main`, redéployer SANS
   activer un seul des 4 drapeaux — vérifie que `REVERB_*`/`VITE_REVERB_*`
   sont bien injectées, sans changer le comportement visible (tous les
   drapeaux restent `false`).
2. **Un SEUL pilote à la fois**, dans cet ordre (du plus isolé au plus
   transverse) : `MESSAGES_UNREAD_REALTIME_ENABLED` (un seul canal
   `user.*`, déjà partagé avec les notifications, risque de régression le
   plus faible) → `CONVERSATION_REALTIME_ENABLED` → `NOTIFICATIONS_REALTIME_ENABLED`
   → `LOOP_CHAT_REALTIME_ENABLED` (le plus mesuré, voir TASK-1848, mais
   aussi celui qui expose le plus de membres simultanés sur un même canal
   `loop.*`, donc le plus coûteux en messages/jour — section 4).
3. Pour CHAQUE pilote : poser le drapeau serveur ET `VITE_*` à `true`,
   redéployer, puis la checklist ci-dessous AVANT de passer au pilote
   suivant.
4. **Jamais deux pilotes activés le même jour** sans la checklist validée
   sur le précédent — en cas de régression, l'origine doit rester
   évidente.

### 3.3 Checklist par pilote (reprend la structure du mandat)

- [ ] Configuration initiale : drapeau serveur + `VITE_*` posés, build
      vérifié (`grep` du bundle livré pour confirmer la valeur figée)
- [ ] Déploiement effectué, `REVERB_*` et le drapeau visibles dans les
      variables d'environnement Cloud (lecture `environment:get`)
- [ ] Contrôle des canaux privés : un abonnement avec un compte NON
      autorisé est bien refusé (reprendre `TASK1845ConversationChannelAuthorizationTest`
      et équivalents comme modèle, déjà verts en CI)
- [ ] Test avec DEUX membres réels (Cyril + un second compte), sur le
      cluster réel, PAS en local
- [ ] Contrôle CPU/RAM/SQL sur le dashboard Cloud (Logs & métriques) sur
      au moins 24h d'activité réelle
- [ ] Test de coupure Reverb : détacher temporairement le cluster (ou
      attendre une maintenance Cloud annoncée), vérifier qu'aucune
      exception ne remonte à l'utilisateur (le `catch(BroadcastException)`
      déjà en place DOIT absorber la panne — comportement déjà testé en
      local par TASK-1843/1845/1847, à reconfirmer en conditions réelles)
- [ ] Reconnexion : recharger un onglet resté ouvert pendant la coupure,
      vérifier la resynchronisation — **point de vigilance** : TASK-1840 a
      déjà observé une reconnexion automatique NON fiable à 100% ; si le
      même symptôme apparaît ici, NE PAS étendre au pilote suivant avant
      correctif côté `echo.js`
- [ ] Seuils d'alerte : nombre de connexions approchant le palier acheté
      (section 4), erreurs `BroadcastException` dans les logs Cloud (déjà
      journalisées, visibles sans instrumentation supplémentaire)
- [ ] Critère GO : zéro régression fonctionnelle sur 24h, latence perçue
      inférieure au polling natif qu'il remplace
- [ ] Critère NO-GO / rollback : drapeau serveur remis à `false` +
      redéploiement — **aucune perte de message possible**, le compteur et
      les messages existent déjà en base AVANT toute diffusion (tous les
      broadcasters diffusent APRES le commit SQL, jamais avant) ; revenir
      au polling pur ne perd rien, juste la vitesse de rafraîchissement

---

## 4. Estimation financière — tarifs OFFICIELS, mesurés le 17/11/2026 (`laravel.com/cloud/docs/pricing.md`)

### Palier Reverb managé

| Connexions simultanées | Messages/jour max | Tarif |
|---:|---:|---:|
| 100 | 200 000 | **5 $/mois** |
| 200 | 400 000 | **10 $/mois** |
| 500 | 1 000 000 | **25 $/mois** |
| 2 000 | 8 000 000 | **100 $/mois** |
| 5 000 | 16 000 000 | **200 $/mois** |

**Piège de comptage à ne jamais sous-estimer** : « chaque message reçu
d'un client et chaque message envoyé à un client compte séparément —
une diffusion serveur livrée à 100 abonnés compte comme 100 messages ».
Pour `loop.{id}`, si une Boucle populaire a 50 membres simultanément
connectés et que 20 messages y sont échangés en une heure, cela consomme
**50 × 20 = 1000 messages**, pas 20 — un facteur multiplicatif par le
nombre d'abonnés au canal, pas par le nombre d'événements métier. Avant
de choisir un palier, estimer : (nombre moyen de membres simultanés par
canal actif) × (nombre de messages métier/jour), PAS le nombre de
messages métier seul.

### Scénarios (3 paliers demandés par le mandat)

| Scénario | Membres simultanés | Palier Reverb recommandé | Coût Reverb/mois |
|---|---:|---|---:|
| Pilote | 10-20 | 100 connexions | **5 $** |
| Bêta | 50 | 100 connexions (marge) ou 200 si pic > 100 | **5-10 $** |
| Développement | 100-200 | 200 (si ≤200) ou 500 (marge) | **10-25 $** |

**Hypothèses et incertitudes, explicites** :
- Le nombre de **connexions simultanées** n'est PAS le nombre de membres
  inscrits : seuls les onglets réellement ouverts ET les 4 drapeaux
  activés comptent. TASK-1848 a mesuré 100 connexions RÉELLES sans
  problème côté Reverb — aucune raison technique de douter de ces
  paliers, mais le volume RÉEL de membres simultanément actifs sur
  BouclePro n'a pas été mesuré ici (hors périmètre de ce CDC) — **à
  obtenir de Cyril ou des logs d'accès PROD avant de choisir un palier
  définitif.**
- Le volume de **messages/jour** dépend du nombre de Boucles actives
  simultanément ET de leur taille — non mesurable sans donnée PROD
  réelle (le jumeau local n'a que des comptes de test). **NE PAS décider
  du palier sur la seule base des connexions sans vérifier ce chiffre.**

### Ressources auxiliaires et impact sur le coût HTTP existant

- **Pas de nouveau cache/queue requis** pour activer Reverb seul :
  `cacheId: null` aujourd'hui, et le mandat ne demande pas de cache
  partagé pour ces 4 pilotes (aucun verrou distribué utilisé par le code
  actuel).
- **Ne JAMAIS supposer** qu'une baisse de 80% des requêtes HTTP (TASK-1840,
  ChatLoop isolé) réduit le coût Cloud de 80% : le compute Cloud est
  facturé **par seconde d'activité de l'instance**, pas par requête — une
  instance `flex` reste éveillée tant qu'elle reçoit du trafic, et une
  baisse du NOMBRE de requêtes ne réduit le coût que si elle libère des
  fenêtres de sommeil (`scale to zero`) suffisamment longues pour
  compter. TASK-1848 (section 2) a mesuré une réduction RÉELLE mais
  MODESTE (−28 à −31%) sur une population mixte — un effet sur la
  facture Cloud, s'il existe, serait proportionnellement plus petit
  encore, et non garanti du tout si l'instance ne dort jamais (`minReplicas=1`
  en PROD aujourd'hui : l'instance ne dort JAMAIS, donc la réduction de
  requêtes HTTP n'a **aucun effet sur le coût compute actuel** — seul un
  passage futur à `minReplicas=0` rendrait ce gain facturable).
- **Coût Reverb lui-même** : isolé, additif, 5-25 $/mois selon le palier
  (tableau ci-dessus) — n'affecte aucune autre ligne de facturation.

---

## 5. Sécurité et exploitation

| Point du mandat | État | Détail |
|---|---|---|
| Isolation Organization | **OK**, vérifié | Les 3 canaux (`loop`, `user`, `conversation`) vérifient `organization_id` explicitement, jamais `currentOrganization()` pour `conversation.*` (voir section 2) |
| Contrôle d'accès aux canaux privés | **OK**, testé en CI | `TASK1843UserChannelAuthorizationTest`, `TASK1845ConversationChannelAuthorizationTest`, tests Boucle existants — tous verts |
| Sockets ouverts après révocation | **LIMITE DOCUMENTÉE dans le code**, non corrigée | `conversation.*` : contrôle à l'abonnement seulement, pas en continu — sans impact aujourd'hui (participants immuables), à revoir SI cette contrainte change |
| Protection des identifiants/secrets | **OK** | `REVERB_APP_SECRET` jamais exposé navigateur ; ce rapport n'affiche AUCUNE valeur sensible — `QUEUE_CONNECTION` lu via `--show-sensitive` UNIQUEMENT capturé dans une variable shell, jamais imprimé (seul le fait « = sync » est rapporté) |
| Connexions abandonnées | **Non testé en conditions PROD réelles** | Reverb gère nativement les timeouts de connexion (ReactPHP) ; aucune mesure spécifique BouclePro faite ici — à couvrir par la checklist section 3.3 |
| Robustesse des événements après commit | **OK, testé** | Les 4 broadcasters diffusent strictement après commit SQL (TASK-1839 `$afterCommit`, TASK-1845/1847 `DB::afterCommit()` manuel) — jamais après rollback, vérifié par tests dédiés |
| Reconnexion | **LACUNE CONNUE, non corrigée** | TASK-1840 : reprise automatique observée non fiable à 100% — AVANT un rollout large, reproduire et corriger si confirmé (micro-TASK proposée, section 9) |
| Absence de payload sensible | **OK, vérifié** | Les 4 événements ont un payload VIDE ou minimal (`recipientId`/`id` déjà connu du destinataire) — jamais de contenu de message/notification dans le canal |
| Procédure d'urgence (désactiver un pilote) | **Documentée (section 3.3)** | Remettre le drapeau serveur à `false` + redéployer — zéro perte de donnée, repli automatique sur le polling natif déjà en place |

### Risque identifié, à corriger AVANT activation (TASK-1850+), documenté ici sans être masqué

**`client_options` vide pour la connexion `reverb`** (`config/broadcasting.php`) :
aucun timeout HTTP explicite sur les appels sortants de diffusion. Sous
`QUEUE_CONNECTION=sync` (confirmé en PROD, section 2), un appel de
diffusion bloque la requête HTTP de l'utilisateur jusqu'à sa résolution —
si le cluster Reverb devient indisponible, le `catch(BroadcastException)`
absorbe l'ERREUR, mais seulement APRÈS que le client HTTP (Guzzle/cURL)
ait atteint son propre timeout, qui peut être long par défaut (plusieurs
secondes). **Recommandation pour TASK-1850** : poser un `timeout` court
(2-3s) dans `client_options` de la connexion `reverb`, pour qu'une panne
Reverb ralentisse l'envoi d'un message de quelques secondes au plus,
jamais plus.

---

## 6. Procédure d'installation (préparée, à exécuter par Cyril au tableau de bord — jamais en autonomie, même principe que la Managed Queue T1829/T1830)

1. Dashboard Cloud → organisation → **Resources → WebSockets → + New
   WebSocket cluster**.
2. Région : conserver `us-east-2` (Ohio) — même région que l'application
   et la base PostgreSQL actuelles (latence minimale entre composants ;
   changer de région déplacerait le goulot réseau ailleurs sans bénéfice
   mesuré).
3. Palier : **100 connexions (5 $/mois)** pour démarrer (pilote 10-20,
   marge large) — ajustable sans recréer le cluster (« Edit settings »).
4. Application canvas de l'environnement `main` → **Add resource →
   WebSockets** → sélectionner le cluster créé.
5. **NE PAS redéployer immédiatement** : vérifier d'abord dans
   `environment:get` que `REVERB_*`/`VITE_REVERB_*` apparaissent dans les
   variables injectées.
6. Redéployer — confirme l'injection ET construit les assets avec les
   `VITE_REVERB_*` réels (les 4 drapeaux `*_REALTIME_ENABLED` restent
   `false` à cette étape : ce déploiement ne change AUCUN comportement
   visible, seulement la disponibilité de la connexion).

## 7. Procédure d'activation

Voir section 3.2/3.3 (ordre des pilotes + checklist). Répéter pour chacun
des 4 drapeaux, un par un, jamais en parallèle.

## 8. Recette

Reprendre très exactement le protocole déjà validé par TASK-1848 (deux
comptes réels, un onglet qui reste ouvert ailleurs, mesure
latence/erreurs) — cette fois sur l'URL Cloud réelle, pas en local. Script
`benchmarks/task-1848/ws-clients.mjs` réutilisable tel quel en pointant
`--reverbKey`/`baseUrl` sur l'environnement Cloud (credentials Cloud à
fournir par Cyril, jamais à coder en dur).

## 9. Supervision

- Dashboard Cloud → **Resources → WebSockets → cluster → Metrics** :
  connexions et débit de messages, natif, sans configuration.
- Logs applicatifs : `Log::warning` déjà posé par chaque broadcaster sur
  `BroadcastException` (TASK-1843/1845/1847) — visible dans les logs
  Cloud sans instrumentation nouvelle.
- Pulse (Reverb) : NON installé dans ce dépôt — optionnel, demanderait
  l'installation du package `laravel/pulse` (absent de `composer.json`
  aujourd'hui) ; hors périmètre de TASK-1849, à arbitrer séparément si la
  supervision native Cloud s'avère insuffisante.

## 10. Rollback

Remettre le drapeau serveur concerné à `false` dans les variables
d'environnement Cloud, redéployer. Le cluster WebSocket peut rester
attaché (pas de perte si un AUTRE pilote reste actif) ou être détaché —
**attention, détacher NE supprime PAS le cluster et n'arrête PAS la
facturation** (confirmé par la documentation officielle) : pour arrêter
réellement les frais, supprimer le cluster depuis **Resources →
WebSockets → … → Delete cluster**, geste IRRÉVERSIBLE à ne jamais faire
sans confirmation explicite de Cyril.

## Checklist GO production

- [ ] Cluster WebSocket provisionné et attaché (section 6), vérifié SANS
      changer de comportement visible
- [ ] `client_options` timeout posé (section 5) — **bloquant avant tout
      pilote sous `QUEUE_CONNECTION=sync`**
- [ ] Reconnexion `echo.js` fiabilisée OU risque accepté explicitement par
      Cyril (lacune connue, section 5/9)
- [ ] Chaque pilote validé individuellement par la checklist section 3.3
      avant le suivant
- [ ] Palier Reverb choisi sur la base d'un VRAI volume de membres
      simultanés PROD (pas seulement l'estimation section 4)
- [ ] Procédure de rollback testée au moins une fois (désactivation
      effective d'un pilote, vérifiée)

---

## Actions déjà réalisées vs à exécuter (TASK-1850/1851/1852)

**Déjà fait (TASK-1849)** : audit technique complet, lecture read-only de
l'état PROD réel, tarifs officiels vérifiés, plan d'activation et
checklist rédigés, risque `client_options` identifié et documenté.

**Reste à faire, PAS dans cette TASK** :
- TASK-1850 (proposée) : poser le timeout `client_options`, fiabiliser la
  reconnexion `echo.js` si confirmée défaillante — changements de code,
  tests ciblés, PR dédiée.
- TASK-1851 (proposée) : provisionnement réel du cluster WebSocket, **avec
  Cyril au tableau de bord Cloud**, jamais en autonomie (même principe que
  la Managed Queue).
- TASK-1852 (proposée) : activation progressive pilote par pilote en PROD,
  une TASK par pilote ou une TASK unique séquencée selon la checklist
  section 3.3.
