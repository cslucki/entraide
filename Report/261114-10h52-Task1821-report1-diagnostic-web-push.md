# T1821 — Diagnostic Web Push : ce qui était un faux signal, et ce qui est un vrai défaut

**Date** : 14/11/2026, 10h52 (Paris) · **Branche** : `TASK-1821-corriger-la-recette-mes-appareils-push` · **Niveau** : SENSITIVE

---

## 1. La conclusion en une phrase

Les cinq abonnements `production` affichés « VAPID DIVERGENTE » le 14/11 **ne sont pas défectueux** : ils étaient comparés à la clé VAPID **locale**, qui n'est pas la leur. Mais l'audit demandé a trouvé, à côté, **un vrai défaut qui affecte les utilisateurs réels en production** — et qui n'est pas corrigé ici (arbitrage MASTER : T1822).

---

## 2. Le faux signal — corrigé dans T1821

`PushSubscription::etatCleVapid()` compare `vapid_key_hash` à la clé du serveur **courant**. Or chaque environnement a sa propre paire VAPID (règle posée dans `config/webpush.php`). Lue depuis une base locale, une ligne stampée en production est donc **toujours** « divergente » — la comparaison ne mesure rien.

**Correctif T1821** : un quatrième état, `VAPID_NON_COMPARABLE`
(`PushSubscription::etatCleVapidContextualise()`), rendu dès que
`created_env !== config('app.env')`. `etatCleVapid()` est inchangé : il reste
la seule autorité sur la comparaison elle-même, et le livreur continue de
l'employer tel quel — en production, où elle est valide.

Vérifié en navigateur réel : les cinq lignes affichent désormais « Non comparable depuis cet environnement », plus aucune « DIVERGENTE ».

---

## 3. Audit ciblé — les trois points demandés

### 3.1 `PushSubscriptionStore::revendiquer()` — réécriture des métadonnées ⚠️ DÉFAUT RÉEL

`$colonnesAMettreAJour` contient **`created_env` ET `vapid_key_hash`**. À chaque réconciliation :

- `created_env` n'est donc **pas** une origine : il est réécrit avec l'environnement courant. Son docblock affirme pourtant l'inverse (« elle voyage avec la ligne : un abonnement importé d'un dump de production portera `production` »). La documentation et le code se contredisent.
- `vapid_key_hash` enregistre **la clé du dernier contact**, pas celle de la création. Le nom de la colonne et le docblock affirment la création.

**Conséquence en production** : un abonnement créé sous une clé périmée voit son empreinte re-stampée avec la clé courante dès qu'une réconciliation passe. Le livreur le juge alors éligible, le fournisseur répond `2xx`, et le navigateur **jette le message en silence** — exactement le mode de panne que T1740 avait fermé, redevenu invisible.

**Mesuré** : test `test_DEFAUT_CONNU_la_reconciliation_reecrit_l_environnement_et_l_empreinte_vapid` — il épingle le défaut plutôt que de le raconter, et donnera un rouge franc à T1822.

**Aggravant trouvé** : `push-device.js` se protège (il s'abstient de reconfirmer quand `cleDiverge()` détecte la divergence côté client). Mais **`push-first-run.js:190` appelle `confirmer()` sans cette garde** — c'est le chemin du premier lancement de la PWA **installée**, donc celui de l'Android de recette. C'est par là que le re-stampage aveugle se produit.

**Atténuation réelle** : les lignes du jumeau portent encore `created_env = production`, ce qui **prouve** qu'aucune réconciliation locale ne les a touchées.

### 3.2 `NotificationWebPushDeliverer::deposer()` — 2xx porteur d'une erreur fournisseur ⚠️ DÉFAUT RÉEL

Le docblock dit explicitement (lignes 474-481) : « un 2xx n'est PAS une livraison », et décrit l'endpoint FCM historique `/fcm/send/` qui rend ses échecs **dans le corps** sous un statut 2xx (`failure: 1`, `results[].error: NotRegistered`).

**Le code ne l'implémente pas.** Le corps est lu, rangé dans `$this->dernierMotif`… et `return 'ok'` est rendu quoi qu'il arrive. Un échec applicatif est donc compté comme une réussite : `$reussites++`, livraison marquée `sent`, diagnostic `devices:N`, et l'endpoint révoqué signalé dans le corps n'est jamais nettoyé.

**Portée réelle mesurée** : 4 des 6 abonnements utilisent l'endpoint legacy `/fcm/send/`, dont **3 en production**.

| Hôte | Forme | `created_env` | n |
|---|---|---|---|
| fcm.googleapis.com | legacy `/fcm/send/` | local | 1 |
| fcm.googleapis.com | legacy `/fcm/send/` | **production** | **3** |
| web.push.apple.com | autre | production | 2 |

**Non corrigé dans T1821** (hors périmètre : « ne pas reconstruire le moteur Push »). À verser au périmètre T1822 ou à une TASK dédiée.

### 3.3 Valeur du contrôle de divergence en lecture inter-environnement

Nulle, et c'est désormais dit à l'écran (§2). Le contrôle garde toute sa valeur **dans** l'environnement qui a stampé la ligne — c'est là que le livreur l'applique.

---

## 4. État réel des livraisons Web Push (jumeau PROD, lecture seule)

`member_notification_deliveries`, canal `web_push` :

| Statut | Diagnostic | n | Période |
|---|---|---|---|
| `skipped_unreachable` | `no_device_subscribed` | **67** | 04/11 → 13/11 |
| `sent` | `devices:1 revoked:1` | 5 | 13/11 |
| `skipped_preference` | `channel_disabled_by_member` | 5 | 04/11 → 05/11 |
| `sent` | `devices:1` | 4 | 07/11 → 10/11 |
| `sent` | `devices:1 revoked:2` | 1 | 13/11 |

**Le chiffre à surveiller** : 67 `no_device_subscribed`. Ce diagnostic **confond deux causes** — « aucune ligne en base » et « des lignes existent mais le filtre VAPID les a toutes écartées ». Si la clé PROD changeait, tous les abonnements deviendraient silencieusement « pas d'appareil ».

**Contre-mesure du jour** : sur les 67, **62 concernent des comptes qui n'ont aucun abonnement aujourd'hui** — la cause bénigne. Seuls 5 concernent des comptes qui en ont un (créé possiblement après). **Pas d'indice de filtrage VAPID massif dans l'historique du jumeau.**

Total `sent` = 10 — et « sent » ne signifie ici que « 2xx reçu », avec la réserve du §3.2.

---

## 5. Ce qui manque pour tracer par APPAREIL

`member_notification_deliveries` ne porte **aucun identifiant d'abonnement** : sa clé est `(notification_id, channel)`, et `diagnostic` ne contient qu'un agrégat texte (`devices:2 revoked:1`). **Aucune livraison ne peut être attribuée à un appareil.** La console l'affiche donc au niveau du COMPTE, avec l'avertissement dans le panneau lui-même.

**Champs/événements manquants, pour une TASK d'instrumentation ultérieure** :

1. `push_subscription_id` (FK nullable vers `push_subscriptions`) sur une table de tentatives par appareil — la clé actuelle `(notification_id, channel)` interdit plusieurs lignes par livraison.
2. `provider_status_code` (le code HTTP brut par appareil) et `provider_body_excerpt` borné — aujourd'hui perdus hors du cas test.
3. `endpoint_hash` figé au moment de la tentative : l'abonnement peut être supprimé après coup (404/410), et la trace devient alors orpheline.
4. `vapid_key_hash_utilise` au moment de l'envoi — seul moyen de distinguer après coup un échec de clé d'un échec de transport.
5. Un événement `push_delivery_attempted` distinct de `push_delivery_accepted` : les deux sont aujourd'hui confondus dans `sent`.
6. Rien ne permettra jamais, côté serveur, de constater la **réception** : elle exigerait un accusé depuis le Service Worker (hors périmètre, et à arbitrer pour sa valeur vie privée).

---

## 6. Autorisations de la console — vérifiées

- Garde `can:superadmin-push-all-devices` (`SuperAdminPushAccessPolicy`) **en plus** de `['auth','admin']`, déclarée sur les deux routes.
- **Hors local**, tout compte dont l'email finit en `.test` est refusé, même porteur de `is_admin=true` — TLD réservé RFC 2606, même discriminant que `PushTestPolicy` (T1740). Refus par défaut (utilisateur absent, email vide → refus).
- **En local**, l'exclusion ne s'applique pas : la recette s'y fait sous `admin@bouclepro.test`, et tout `/admin` lui est déjà ouvert.
- Mesuré en base locale : 3 comptes `is_admin=true`, dont 2 de démonstration (`admin@bouclepro.test`, `launchpals.member1@bouclepro.test`). **En production, ces deux comptes n'auraient pas accès.**
- Le lien de navigation disparaît pour un compte refusé, plutôt que de mener à un 403.

---

## 7. Ce que je n'ai PAS pu faire

- **Lire la base PROD** : refusé par le classifieur (constat établi, aucun contournement tenté). Toutes les mesures ci-dessus viennent du **jumeau** `bouclepro_prod_work` — fidèle au dump, pas à l'instant présent.
- **Lire `VAPID_PUBLIC_KEY` de PROD** : `cloud env:vars --show-sensitive` bloqué par le classifieur. **Conséquence** : impossible de confirmer que l'empreinte `2ef871449c37…` portée par les deux abonnements Android d'`ofsh@teletravailleurs.net` est bien celle de la clé PROD **en vigueur**. Les deux lignes portent en revanche **une seule et même empreinte** (vérifié : `count(DISTINCT vapid_key_hash) = 1`) — donc **pas deux clés différentes**.
- **Lire `applicationServerKey` du navigateur** : non observable depuis le serveur, par construction. Seul l'appareil le connaît.

---

## 8. Reste à Cyril

1. Confirmer l'empreinte VAPID PROD (commande fournie en session, n'affiche aucun secret).
2. Lire la ligne « Clé d'abonnement » du panneau de diagnostic T1740 **depuis le téléphone** — seule source sur ce que le navigateur détient réellement.
3. Valider la console en recette.

---

## 9. Décision MASTER actée

T1821 **ne corrige pas** les défauts §3.1 et §3.2 : ils sont documentés et épinglés par un test. **T1822** (« Sécuriser la réconciliation VAPID et empêcher les faux abonnements valides ») sera ouverte **après fusion de T1821**, et prioritaire avant la prochaine release PROD.
