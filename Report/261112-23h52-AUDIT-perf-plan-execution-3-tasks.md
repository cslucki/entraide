# PLAN D'EXÉCUTION — arbitrage audit performance

**Date** : 12/11/2026, 23h52 Paris
**Mode** : préparation seule. `LOCAL_OPS / no branch / no commit`. **Aucun GO_WRITE pris.**
**Référence de mesure** : HEAD `39160c3e`, VERSION `1.812`, arbre propre.
**`DATABASE_TARGET`** = `prod_work`.

> L'audit du 23h26 avait été mesuré sur `b3529c7f` / 1.811. La branche a avancé
> pendant l'audit (correctifs 08, 09, 10 + bump 1.812). **Toute la baseline a été
> reprise sur `39160c3e`** pour être attribuable à un seul SHA. Les 14 lignes
> citées dans l'audit ont été revérifiées une par une : **toutes tiennent encore**.

---

## 1. POINT 5 DE L'ARBITRAGE — RÉSOLU PAR LA MESURE, PAS PAR SUPPOSITION

L'arbitrage disait : « vérifier en lecture seule les caches et OPcache réellement
actifs. Ne pas supposer leur absence à partir de l'environnement CLI. » C'était
justifié — et la vérification a abouti.

`cloud environment:get` reste refusé par le classifieur, mais `cloud environment:list`
(liste read-only d'`AGENTS.md`) expose les champs décisifs. **Aucune valeur sensible
n'a été lue** : les variables d'environnement sont masquées par défaut et je n'ai pas
employé `--show-sensitive`.

### Ce qui est maintenant ÉTABLI sur la PROD (env `main`)

```
buildCommand  : composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
                npm ci --audit false
                npm run build
deployCommand : php artisan migrate --force
```

**Il n'y a ni `php artisan optimize`, ni `config:cache`, ni `route:cache`, ni
`view:cache`, ni `event:cache`, dans la construction comme dans le déploiement.**

Ce n'est plus une déduction depuis le CLI local : c'est la commande de build PROD
elle-même. Avec **982 routes** et 31 fichiers de configuration, sur **1 vCPU**.

### Et deux faits qui changent la lecture

| Champ PROD | Valeur | Conséquence |
|---|---|---|
| `usesHibernation` | **`true`** | L'instance hiberne → **démarrages à froid**. Chaque réveil repaie l'amorçage complet, précisément ce que `config:cache` et `route:cache` suppriment. Les deux faits se multiplient. |
| `usesOctane` | `false` | Confirmé : aucun état conservé entre requêtes. |
| `cacheId` | `null` | **Aucun service de cache managé** provisionné (ni Redis, ni Memcached). |
| `websocketApplicationId` | `null` | Confirmé : aucun WebSocket. |
| `phpMajorVersion` | **`8.5`** | La PROD est en **PHP 8.5**, ce poste en **8.4**. Écart de version à connaître. |
| `region` | `us-east-2` | Application hébergée aux États-Unis. |

### Une variable d'environnement PROD qui ne fait rien

La PROD déclare **`CACHE_DRIVER`**. Or `config/cache.php:18` lit
`env('CACHE_STORE', 'database')`, et **`CACHE_DRIVER` n'est lu nulle part** dans
`config/`, `bootstrap/` ni `app/` (vérifié).

`CACHE_DRIVER` est le nom Laravel ≤ 10 ; le projet est en Laravel 13. **Cette
variable est morte** : elle est sans effet, et le magasin de cache retombe sur le
défaut `database`. Inoffensif *si* `database` était l'intention. Je ne peux pas lire
sa valeur (masquée) : **à confirmer par Cyril**, c'est le genre de réglage qui a l'air
autoritaire et ne gouverne rien.

### Ce que je n'ai PAS pu établir

**L'état d'OPcache lui-même n'est exposé par aucun champ du CLI.** Il est géré par la
plateforme. Je ne l'affirme ni actif ni inactif. L'observable décisif que je *peux*
produire était la commande de build — elle est ci-dessus. Pour une certitude sur
OPcache, il faut le tableau de bord Cloud.

---

## 2. CORRECTIONS À MES PROPRES CHIFFRES

Ces nombres vont devenir des critères d'acceptation : ils doivent être justes.
Deux défauts de mon harnais ont été trouvés et corrigés.

**Défaut 1 — la plomberie de session.** `Auth::login()` appelé avant `handle()`
provoquait une lecture de `sessions` **supplémentaire** et des insertions
`login_logs` qui n'existent pas dans une vraie requête HTTP. La baseline compte
désormais le **nombre applicatif** et la **plomberie** séparément.

**Défaut 2 — le socle « −8 » était un surcompte.** Le recomptage strict (requêtes
dont le texte SQL est identique dans la même requête HTTP) donne **5**, pas 8 : deux
des « doublons » étaient de la plomberie induite par le harnais.

| Audit du 23h26 | Corrigé @ `39160c3e` | Raison |
|---|---|---|
| socle : **−8** req/page | **−5** req/page | recomptage strict, plomberie exclue |
| `/boucles` = 17 | **14 applicatif** + 3 plomberie | artefact du harnais |
| `/loops/{id}` 101 → ≤ 90 | **98 → ≤ 65** | 37 redondances strictes mesurées, pas seulement les 11 d'appartenance |
| `/agenda` 69 → ≤ 40 | **66**, cible selon périmètre | 47 redondances strictes |
| `/membres` 52 → ≤ 30 | **49** (hors périmètre arbitré) | 30 redondances strictes |
| `/admin/dashboard` 193 | **186 applicatif** | idem |

### Baseline définitive @ `39160c3e` — identique sur 2 passes

| Route | Requêtes applicatives | Plomberie session | Dont strictement redondantes |
|---|---|---|---|
| `/boucles` | 14 | 3 | **5** |
| `/dashboard` | 27 | 3 | **7** |
| `/notifications` | 41 | 3 | **22** |
| `/membres` | 49 | 3 | **30** |
| `/flux` | 58 | 4 | **31** |
| `/agenda` | 66 | 3 | **47** |
| `/loops/{id}` | **98** | 3 | **37** |
| `/admin/dashboard` | **186** | 3 | **149** |

### Une clarification qui change la technique de la TASK ChatLoop

**Les 19 requêtes d'un cycle `wire:poll.3s` sont 19 requêtes DISTINCTES — aucun
doublon interne.** La réduction ne peut donc pas venir d'une dé-duplication : elle
vient d'un **retrait de périmètre** (ne plus re-dériver un contexte invariant).
C'est une technique différente de celle des deux autres TASKs, et cela rend la cible
« 6 à 8 » atteignable uniquement en acceptant que certains panneaux ne se
rafraîchissent plus toutes les 3 secondes. **Point d'arbitrage, voir §6.**

---

## 3. LA DOCTRINE DE MÉMOÏSATION EXISTE DÉJÀ DANS LE DÉPÔT — ET ELLE EST PLUS STRICTE QUE L'ARBITRAGE

L'arbitrage impose : « Réutilisation des résultats limitée au scope requête,
utilisateur et Organization » et « Aucun cache statique susceptible de survivre
entre tenants ».

**T1109 a déjà résolu exactement ce problème**, sur `LoopRoadmapCard`, et sa leçon
est plus exigeante que « scope requête » :

> « Le cache ne vit que pendant `render()`. C'est la correction d'une première
> version qui le laissait vivre toute la requête : une propriété privée ne voyage pas
> dans le snapshot — vérifié —, mais **l'unité de vie d'une instance Livewire n'est
> pas la requête, c'est le commit**, et un commit porte un tableau de `calls`. Deux
> gestes partis dans le même tick partagent donc une instance, et un droit retiré
> entre les deux par une requête concurrente n'était plus honoré. »
> — `app/Livewire/LoopRoadmapCard.php:914-926`

**Conséquence directe sur ce plan** : dans un composant Livewire, « scope requête »
**ne suffit pas**. La mémoïsation doit être **remplie à l'entrée de `render()` et
vidée à la sortie**. Hors Livewire (middleware, contrôleur), le scope requête est la
bonne borne.

Second enseignement, qui vise `LoopPermissionResolver` : il « interroge
`loop_members` à **chaque appel** : le resolveur n'a pas de cache, et n'est pas lié
en singleton — une instance neuve à chaque `app()` » (`LoopRoadmapCard.php:936`).
**Mémoïser dans le resolveur serait donc inopérant.** La correction doit être au
point d'appel, ou par préchargement.

### Le gabarit de test existe aussi

`tests/Feature/TASK1109RoadmapMembershipCostTest.php` porte les quatre gardes à
reprendre, dont une que je n'avais pas envisagée :

- `test_the_cost_does_not_follow_the_number_of_items()` — le coût ne suit pas le volume
- `test_the_membership_is_read_a_bounded_number_of_times()` — plafond de lectures
- `test_a_plain_member_pays_no_more_than_the_animator()` — invariance par rôle
- **`test_the_cache_never_travels_in_the_livewire_snapshot()`** — lit le **snapshot réel**, et non les noms de propriétés. Son commentaire dit pourquoi : la première version « n'assertait qu'une chose — aucun nom de propriété publique ne contient *membership* — et serait passée inchangée si quelqu'un avait ajouté `public array $droitsMemo` ».

Précédents de test de coût SQL à réutiliser : `TASK1778FanOutSqlCostTest.php`,
`TASK1109RoadmapMembershipCostTest.php`. **Rien à inventer.**

---

## 4. DÉPENDANCES AVEC T1812

### Fichiers déjà modifiés par T1812 (12 correctifs, `06791d0c..39160c3e`)

```
app/Http/Controllers/Admin/AdminController.php   app/Http/Controllers/HomeController.php
app/Support/Devices/DeviceKind.php               VERSION
lang/{fr,en}/{admin,directory,legal}.php
resources/views/admin/{users,login-history/index,partials/user-profile-modal}.blade.php
resources/views/components/user-dashboard-nav.blade.php
resources/views/favorites/index.blade.php        resources/views/livewire/explorer.blade.php
resources/views/livewire/partials/feed-post-card.blade.php
resources/views/members/{index,partials/boucles-membre}.blade.php
resources/views/mentions-legales.blade.php       tests/Feature/TASK1812*.php
```

### Intersection avec les trois TASKs arbitrées : **VIDE**

| TASK | Fichiers visés | Conflit T1812 |
|---|---|---|
| **A — socle** | `app/Http/Middleware/ResolveUrlOrganization.php` · `app/Models/User.php` · `app/Ai/ProviderResolver.php` | **aucun** |
| **B — sondage ChatLoop** | `app/Livewire/LoopChat.php` · `resources/views/livewire/loop-chat.blade.php` | **aucun** |
| **C — Loop Workspace** | `app/Http/Controllers/LoopController.php` · `app/Livewire/LoopChat.php` · `app/Policies/LoopPolicy.php` · `app/Services/Loops/LoopEventService.php` | **aucun** |

**Aucune des trois TASKs ne touche un fichier de T1812.** Le risque de conflit de
fusion est nul sur le code.

### Les deux seules dépendances réelles

1. **`VERSION`** — contrat TASK/VERSION = égalité bloquante. T1812 est à `1.812`.
   Les trois TASKs doivent brancher depuis `develop` **après** la fusion de T1812,
   et prendront `1.813`, `1.814`, `1.815` dans leur ordre d'ouverture. C'est le seul
   fichier systématiquement en conflit, et il se règle à la main comme d'habitude.

2. **TASK B et TASK C touchent toutes deux `app/Livewire/LoopChat.php`.**
   C'est une dépendance **entre mes propres TASKs**, pas avec T1812 :
   `LoopChat.php:510` (contrôle d'appartenance, périmètre C) est dans le chemin de
   rendu que B réécrit. **Elles ne peuvent pas être menées en parallèle.**

### Report confirmé, conformément au point 6

Hors périmètre, et **non préparés** : l'annuaire (`HomeController.php:233`, B7 de
l'audit — et c'est le seul point qui *aurait* heurté T1812), le `morphTo` du Flux,
le bundle frontend, `/admin/dashboard`, l'hygiène d'index, toute migration, toute
nouvelle infrastructure, et le chantier file d'attente.

---

## 5. LES TROIS TASKS

Ordre d'exécution **recommandé** : A → B → C. L'arbitrage numérotait le ChatLoop en
premier ; je propose A d'abord pour une raison technique, soumise à arbitrage (§6).

---

### TASK A — « Le socle d'une requête »
*Priorité transversale de l'arbitrage (point 2). Risque le plus faible, portée la plus large.*

**Call sites CONFIRMÉS** — mesurés sur 8 routes, identiques partout (sauf `/dashboard`,
qui ne passe pas par `is_default`) :

| Requête | × | Call site confirmé | Gain |
|---|---|---|---|
| `organization_ai_settings` | 3 | `app/Ai/ProviderResolver.php:142` (`tenantConfiguration()`), atteint par `ProviderResolver.php:154` depuis `AiFabContext` et `AiSurfaceGate` | **−2** |
| `organizations where is_default` | 2 | `app/Http/Middleware/ResolveUrlOrganization.php:350`, appelé depuis `:274` et `:219` | **−1** |
| `member_notifications` non lus | 2 | `app/Models/User.php:608-614` (`unreadNotificationsCount()`), appelé par deux partials du layout | **−1** |
| `organizations` par id | 2 | `ResolveUrlOrganization.php:370` + un accès paresseux dans le layout | **−1** |

**Correction** : mémoïsation **bornée au scope requête**, clé comprise. Aucun de ces
trois fichiers n'est un composant Livewire — le scope requête est ici la bonne borne
(cf. §3).
- `ProviderResolver` : tableau interne indexé **par `organizationId`**. Attention, le
  resolveur n'est **pas** lié en singleton : la mémoïsation doit tenir par un
  mécanisme à durée de requête, pas par l'instance.
- `unreadNotificationsCount` : mémo indexé **par `organizationId` + `user_id`**.
- Organization par défaut : mémo sur l'instance de middleware (une par requête).

**Métriques avant/après** (`39160c3e`, harnais reproductible) :

| Route | Avant | Cible |
|---|---|---|
| `/boucles` | 14 | **≤ 9** |
| `/dashboard` | 27 | **≤ 23** |
| `/notifications` | 41 | **≤ 36** |
| `/flux` | 58 | **≤ 53** |
| `/agenda` | 66 | **≤ 61** |
| `/loops/{id}` | 98 | **≤ 88** (`organization_ai_settings` y est lu **9 fois**) |

**Tests ciblés** : gabarit `TASK1778FanOutSqlCostTest`. Un test de coût par famille,
plus les tests existants à faire repasser : `ResolveUrlOrganization` (24 fichiers),
`ProviderResolver` (31 fichiers), `unreadNotificationsCount` (3 fichiers).

**Critères de non-régression — garde bloquante** :
1. **Sabotage obligatoire** : un test qui **rougit** si un second tenant lit la valeur
   mémoïsée du premier. Clé par `organization_id` prouvée, pas supposée.
2. Aucune propriété statique de classe. La mémo ne survit pas à la requête.
3. Le compteur de notifications non lues reste **exact** après marquage comme lu dans
   la même requête — si une telle séquence existe, la mémo doit être invalidée.
4. Ancrage tenant : `T0754DashboardMembersExchangesTenantSafetyTest`,
   `T07411RoutesTenantSafetyTest` repassent verts.

**Effort** : faible. **Risque** : faible, *conditionné à la garde 1*.

---

### TASK B — « Le coût d'un sondage du ChatLoop »
*Point 1 de l'arbitrage. Polling conservé à 3 s. Cible : 19 → 6-8 requêtes.*

**Fichiers** : `app/Livewire/LoopChat.php` (3 560 lignes) ·
`resources/views/livewire/loop-chat.blade.php:4`.
*(`resources/views/livewire/message-thread.blade.php:1` porte le même schéma en
`wire:poll.3000ms` — à traiter dans la même TASK ou à reporter, cf. §6.)*

**Les 19 requêtes d'un cycle, classées** — mesurées, toutes distinctes :

*Nécessaires à « un message est-il arrivé ? » (6, à conserver)*
`loop_messages` fenêtre · `loop_messages` IN · `users` IN (auteurs) ·
`loop_messages` cités · `users` IN (cités) · `reactions` des messages affichés.

*Contexte invariant entre deux cycles de 3 s (8, candidats au retrait)*
`dossiers` de la Boucle · `dossiers` enfants · `organization_ai_settings` ·
`loop_cards` · message épinglé · `loop_members` appartenance · `organizations` ·
`derived_knowledge_notes`.

*Infrastructure (5)*
`cache` · `loops` · et 3 de session/divers.

**Correction** : sortir le contexte invariant du chemin de sondage. Deux voies, à
arbitrer (§6) : propriétés calculées mémoïsées **à l'échelle du `render()`** (doctrine
§3), ou sondage porté par une méthode étroite qui ne lit que les messages, le
contexte restant au montage et aux gestes.

**Métriques avant/après** : un cycle `$refresh` mesuré par l'API de test Livewire.

| Mesure | Avant | Cible |
|---|---|---|
| Requêtes par cycle | **19** | **6 à 8** |
| Rendu serveur (local, hors OPcache) | ~90 ms | à relever, non contractuel |
| Snapshot Livewire | **4 297 octets** | **≤ 4 297** (ne doit pas croître) |

**Tests ciblés** : nouveau test de coût sur le cycle `$refresh` ; les **43 fichiers de
test** touchant `LoopChat` doivent repasser.

**Critères de non-régression** :
1. **Aucun comportement retiré** : un message entrant apparaît toujours dans les 3 s.
2. Les panneaux dont le rafraîchissement est retiré du sondage doivent être **nommés
   explicitement** dans le taskfile et **validés par Cyril** — c'est un changement
   fonctionnel assumé, pas un effet de bord.
3. **Le snapshot ne grossit pas** et aucune mémo n'y voyage (gabarit
   `test_the_cache_never_travels_in_the_livewire_snapshot`).
4. Mémo **vidée en sortie de `render()`** — un geste d'écriture relit l'état (§3).
5. **Recette navigateur réelle obligatoire** : surface la plus visible du produit.

**Effort** : moyen. **Risque** : **moyen** — le seul des trois à modifier un
comportement visible.

---

### TASK C — « Le Loop Workspace »
*Point 3 de l'arbitrage. 98 → ≤ 65, sans affaiblir ACL/tenant.*

**Fichiers** : `app/Http/Controllers/LoopController.php` · `app/Livewire/LoopChat.php`
· `app/Policies/LoopPolicy.php` · `app/Services/Loops/LoopEventService.php`.
Lecture seule mais à comprendre : `app/Support/Loops/LoopPermissionResolver.php`,
`app/Support/Loops/LoopTypeRegistry.php`.

**Les 37 redondances strictes, mesurées** :

| Requête | × | Call sites confirmés | Gain |
|---|---|---|---|
| `loop_members ... exists` | **11** | `LoopController.php:878-881` (`workspaceCardsFor`, depuis `:802 :807 :808 :852 :853`) · `:905-910` (`placedCardsFor`, depuis `:816 :817`) · `:729` · `LoopChat.php:510` (depuis `:458 :498`) · `LoopPolicy.php:108` | **−10** |
| `organization_ai_settings` | **9** | couvert par TASK A | **−8** |
| `loop_cards primary_rank` | 4 | composition des cartes | −3 |
| `loop_event_responses` (2 familles) | 4+4 | `LoopEventService.php:457 :475 :490` | −6 |
| `loops` par id | 3 | — | −2 |
| 8 autres paires (`organizations`, `users` ×2 jeux, `loop_join_requests`, `loop_invitations`, `loop_messages`, `loop_members user_id`, `member_notifications`) | 2 ch. | — | −8 |

**Correction** :
- `$isActiveMember` calculé **une fois** dans `show()` et passé à `workspaceCardsFor()`
  et `placedCardsFor()`. Les deux doivent garder la **même règle** — leur commentaire
  l'exige — pas la même implémentation.
- `->with('cards')` là où `activeCardsFor()` est atteint : son chemin rapide
  `relationLoaded('cards')` (`LoopTypeRegistry.php:547-550`) est **déjà écrit et
  couvert**. Ne **pas** mémoïser dans `LoopPermissionResolver` : inopérant (§3).
- Côté `LoopChat`, mémo **à l'échelle du `render()`** uniquement.

**Métriques avant/après** :

| Mesure | Avant | Cible |
|---|---|---|
| `/loops/{id}` requêtes applicatives | **98** | **≤ 65** |
| dont `loop_members ... exists` | **11** | **≤ 2** |
| Mémoire (local, hors OPcache) | 20,5 Mo | à relever, non contractuel |

**Tests ciblés** : gabarit `TASK1109RoadmapMembershipCostTest` **intégralement
repris**, dont la garde snapshot. Existants à faire repasser :
`TASK1081WorkspaceCompositionTest`, `LoopVisibilityMembershipTest`,
`TASK1123LoopCardAdministrationTest`, et les 8 fichiers touchant
`workspaceCardsFor`/`placedCardsFor`.

**Critères de non-régression — ACL, garde bloquante** :
1. **Sabotage obligatoire, 3 cas** : un non-membre n'obtient aucune carte ; un membre
   `status != 'active'` non plus ; un SuperAdmin conserve l'accès actuel. Chacun doit
   **rougir** sur le code mémoïsé si la clé est fausse.
2. La mémo porte **`loop_id` + `user_id`** — jamais `loop_id` seul.
3. **Aucun élargissement de fenêtre d'autorisation** : chaque geste d'écriture relit
   l'état. C'est la régression exacte que T1109 a documentée.
4. `LoopPolicy` reste la seule autorité de décision — la mémo ne la contourne pas.

**Effort** : moyen. **Risque** : moyen — c'est de l'ACL. **À faire après B** (les deux
touchent `LoopChat.php`).

---

## 6. CE QUI RESTE À ARBITRER — aucun GO_WRITE pris

1. **Ordre A → B → C, ou B → A → C ?** L'arbitrage numérote le ChatLoop en premier.
   Je recommande **A d'abord** : `organization_ai_settings` est lu 3 fois par page et
   **9 fois** sur le Workspace, donc A déplace la baseline de C. Faire A en premier
   évite de mesurer C deux fois. A est aussi le plus faible risque — un bon premier
   pas. **Mais c'est ton arbitrage.**

2. **TASK B : quels panneaux acceptent de ne plus se rafraîchir toutes les 3 s ?**
   La cible 6-8 requêtes **n'est pas atteignable** sans retirer du périmètre de
   sondage : les 19 requêtes sont distinctes, il n'y a rien à dé-dupliquer. Les 8
   candidats sont nommés en §5-B. **Il me faut ta validation panneau par panneau** —
   c'est un changement fonctionnel, pas une optimisation invisible.

3. **`message-thread.blade.php` (`wire:poll.3000ms`) : dans TASK B ou reporté ?**
   Même schéma, autre surface (messagerie d'entraide). Non mesuré à ce jour.

4. **`CACHE_DRIVER` en PROD** : variable morte. Quelle valeur y est posée, et quelle
   était l'intention ? Si autre chose que `database`, le réglage n'a jamais pris effet.

5. **`config:cache` / `route:cache` / hibernation** : maintenant **prouvés absents**
   de la commande de build. C'est un changement de **configuration Laravel Cloud**,
   sans risque de code, et probablement le plus gros gain CPU disponible — mais
   l'arbitrage réserve l'infrastructure. **Chantier séparé, à ouvrir ou non.**
   Réserve honnête : `config:cache` fige `env()` hors configuration ; un `env()`
   appelé à l'exécution ailleurs que dans `config/` rendrait `null`. **À auditer
   avant toute activation** — ce n'est pas un interrupteur gratuit.

6. **Secret VAPID** : rien à faire côté dépôt (§7). Reste une question : **la clé
   privée locale est-elle la même qu'en PROD ?** Je ne peux pas lire la valeur PROD
   (masquée). Si elle est identique, une rotation est prudente ; si la locale est une
   clé de développement distincte, l'incident est clos.

---

## 7. POINT 7 DE L'ARBITRAGE — EXPOSITION DU SECRET VAPID, SANS LE RÉAFFICHER

Vérifié **par empreinte**, sans jamais réimprimer la valeur :

| Contrôle | Résultat |
|---|---|
| `.env` ignoré par git | **OUI** (`.gitignore:5`) |
| `.env` suivi par git | **NON** |
| `.env.example` : valeurs VAPID | **vides** (longueur 0), publique comme privée |
| Commit `9859c2ea` (« clef privee jamais exposee ») | n'a introduit que des valeurs **vides** — le titre était exact |
| Clé privée locale présente dans `HEAD` | **NON** (`git grep -F` sur l'arbre suivi) |
| Clé privée locale présente dans **tout** l'historique | **NON** (`git log --all -S`) |

**Conclusion** : le dépôt public `cslucki/entraide` **n'a jamais porté cette clé**.
La seule exposition est la **sortie de session de l'audit** du 23h26, de mon fait.

**Deux points d'hygiène** :
- `.env` et `.env.prodwork` sont en `-rw-r--r--` (lisibles par tous les comptes du
  poste). `chmod 600` serait plus juste pour des fichiers qui portent des secrets.
- La question ouverte reste l'identité entre clé locale et clé PROD (§6.6).

---

## 8. RÈGLES DE CONDUITE RETENUES POUR LES TROIS TASKS

- **Les mesures CLI ne sont pas des mesures PROD.** Les critères d'acceptation sont
  exprimés en **nombre de requêtes SQL** — grandeur exacte, indépendante d'OPcache.
  Aucun critère en millisecondes. Les temps relevés restent indicatifs et
  explicitement non contractuels.
- Protocole T1812 : diagnostic → correctif → **recette → commit → mise à jour du
  taskfile, à CHAQUE correctif**, jamais en lot.
- Branche depuis `develop` **après** fusion de T1812. `VERSION` = numéro de TASK.
- Réutilisation bornée au **scope requête** hors Livewire, au **scope `render()`**
  dans un composant Livewire (§3). Aucune propriété statique.
- Aucune migration, aucun index, aucune nouvelle infrastructure, aucun refactor massif.

---

**FIN DE LA PRÉPARATION — STOP.**
Aucun fichier applicatif modifié · aucun commit · aucune écriture PROD ·
aucune valeur sensible lue ni affichée. **En attente de GO_WRITE explicite et des
arbitrages du §6.**
