# AUDIT GLOBAL PERFORMANCE BOUCLEPRO — rapport READ ONLY

**Date** : 12/11/2026, 23h26 Paris
**Mode** : `LOCAL_OPS / no branch / no commit` — audit en lecture seule, aucun fichier applicatif modifié
**Branche** : `TASK-1812-correctifs-divers-multi-domaines` @ `b3529c7f` · VERSION `1.811` · 1 worktree, arbre propre
**TASK en cours** : T1812, non perturbée (aucun fichier de `app/`, `resources/`, `routes/`, `config/`, `database/`, `tests/` touché)
**`DATABASE_TARGET`** = `prod_work` (`bouclepro_prod_work`, copie jetable issue de la baseline 1.595)
**PROD** : consultation passive uniquement. Aucune écriture, migration, config ni redémarrage.

---

## 0. MÉTHODE ET LIMITES DE LA MESURE — à lire avant les chiffres

### Ce qui est mesuré et fiable

Les **nombres de requêtes SQL** sont exacts et **reproductibles à l'identique sur 3 passes
indépendantes** (vérifié : `/loops/{id}`=101, `/agenda`=69, `/flux`=61, `/membres`=52,
`/boucles`=17, `/admin/dashboard`=193 — trois fois le même résultat).

Harnais de mesure : script jetable en scratchpad qui amorce le framework, pose **un seul**
`DB::listen` remis à zéro entre deux routes, et passe une requête GET par le noyau HTTP.
Un `debug_backtrace` filtré hors `vendor/` donne l'origine applicative exacte de chaque
requête répétée — c'est de là que viennent les `fichier:ligne` de ce rapport.

**Une erreur de mesure a été commise puis corrigée** : la première version du harnais
enregistrait un `DB::listen` par itération, ce qui comptait chaque requête N fois.
Les premiers chiffres (`/blog`=105, `/agenda`=396) étaient faux. Tous les chiffres
ci-dessous viennent du harnais corrigé.

### Ce qui n'est PAS une prédiction PROD

- **`opcache.enable_cli = Off`** : mes temps d'horloge (`wall`) et de mémoire sont mesurés
  **sans OPcache**. PHP-FPM en PROD, avec OPcache, est sensiblement plus rapide sur la part
  compilation/autoload. **Les `wall ms` sont indicatifs, pas des temps PROD.** Les nombres
  de requêtes, eux, ne dépendent pas d'OPcache.
- La base locale est un **PostgreSQL local** (latence sub-ms). La PROD est un **Neon
  serverless** : chaque requête y paie un aller-retour réseau. **C'est ce qui transforme un
  nombre de requêtes en latence.** Je n'ai pas pu mesurer la latence PROD par requête (§F).
- Volumes du jumeau : **27 Mo, 82 users, 9 Organizations, 26 Boucles, 118 messages,
  365 migrations, 162 tables**. Représentatif de la PROD, qui est petite.

### Conséquence directe sur les conclusions

**Il n'y a pas de problème de volume de données.** À 27 Mo, aucun ajout d'index n'est
justifiable, et aucune migration n'est proposée dans ce rapport. Les coûts réels sont :
le **nombre d'allers-retours SQL par requête**, le **CPU PHP par requête**, le **travail
synchrone dans la requête web**, et le **poids frontend**. C'est là que porte l'audit.

---

## A. DIAGNOSTIC EXÉCUTIF — les 5 risques principaux

### A1. Le sondage du ChatLoop est le premier poste de coût récurrent

`resources/views/livewire/loop-chat.blade.php:4` — `wire:poll.3s`.
**Mesuré** : un cycle de sondage = **19 requêtes SQL, ~90 ms de rendu serveur, 4,2 Ko de
snapshot Livewire** sérialisé à chaque aller-retour.

À 3 secondes, un seul onglet de chat ouvert au premier plan génère **20 requêtes HTTP et
380 requêtes SQL par minute**, pour afficher le plus souvent exactement la même chose.

**Nuance vérifiée dans le code de Livewire 4** (`vendor/livewire/livewire/dist/livewire.js`) :
Livewire **suspend déjà** le sondage quand l'onglet passe en arrière-plan
(`document.hidden`, sauf modificateur `.keep-alive`). Le coût ne concerne donc que les
onglets **au premier plan**. Ajouter `.visible` n'apporterait presque rien ici, le chat
occupant toute la page. **Le vrai levier est le coût par sondage et l'intervalle**, pas
la visibilité.

**Décomposition des 19 requêtes** : seules 6 concernent réellement « un nouveau message
est-il arrivé ? ». Les autres re-dérivent à chaque cycle un contexte qui ne change pas :
dossiers de la Boucle et leurs enfants, `organization_ai_settings`, `loop_cards`, message
épinglé, appartenance du membre, Organization, note de connaissance dérivée.

### A2. Un socle de ~8 requêtes redondantes est payé sur CHAQUE page

Mesuré sur la page la plus légère (`/boucles`, 17–18 requêtes) : avant tout contenu
spécifique, la chaîne middleware + layout exécute déjà 18 requêtes, dont **8 sont des
doublons stricts dans la même requête HTTP** :

| Requête dupliquée | Nombre | Origine mesurée |
|---|---|---|
| `organizations where is_default` | ×2 | `ResolveUrlOrganization.php:350`, appelé depuis `:274` et `:219` |
| `organization_ai_settings` | ×3 | `ProviderResolver.php:142` via `AiFabContext` et `AiSurfaceGate` |
| `member_notifications` (compteur non lus) | ×2 | `User.php:613`, appelé par deux partials du layout |
| `organizations` par id | ×2 | résolution de tenant + helper de vue |

C'est le gisement le plus rentable du rapport : **il améliore toutes les routes à la fois**,
sans toucher à une seule règle métier.

### A3. L'espace de travail d'une Boucle — la surface centrale — est la page la plus lourde

`/loops/{id}` : **101 requêtes, 95 ms SQL, 20,5 Mo**. Le même prédicat d'appartenance
(`loop_members where loop_id=? and user_id=? and status=?`) y est évalué **11 fois** dans
un seul rendu, depuis 5 endroits distincts.

20,5 Mo par requête sur une instance **512 Mo** borne mécaniquement la concurrence.

### A4. Le travail réseau tiers s'exécute DANS la requête web de l'utilisateur

`QUEUE_CONNECTION=sync` (confirmé en PROD au dashboard, release 1.764), **aucun
`background-process` en PROD**, aucun `onConnection()` dans le dépôt.

Conséquence **vérifiée localement** : `queue:work` sur la connexion `sync` **sort
immédiatement en code 0 sans rien consommer**. Le worker planifié chaque minute
(`routes/console.php:47-61`), construit par T1511 avec son allowlist documentée,
**est donc inerte** : les jobs ne touchent jamais la table `jobs`, ils s'exécutent en ligne.

Chaîne mesurée pour un dépôt de fichier :
`DossierFileObserver.php:21` → `DossierFileIndexingDispatcher::dispatchForFile()` →
`IndexDossierFileChunks` (exécuté en ligne) → `DossierFileIndexer.php:122` →
**appel réseau d'embeddings OpenAI dans la requête HTTP de l'utilisateur**.

Idem pour les notifications : `NotificationDeliveryPlanner.php:134` dispatche un job
**par canal et par destinataire** ; sous `sync`, chaque envoi SMTP et chaque Web Push
part depuis la requête web.

**À ne pas « corriger » naïvement** : T1777 a établi que `sync` est précisément ce qui fait
fonctionner 4 files sur 7 sans consommateur. Basculer la connexion globale sans poser de
worker arrêterait email et Web Push **en silence**. La séquence correcte est celle de T1777.

### A5. Deux surfaces dégradent linéairement avec le nombre d'Organizations

`/admin/dashboard` : **193 requêtes** à 9 Organizations, dont ~81 agrégats
`count()`/`sum()` sur `ai_provider_invocations`, exécutés **un par Organization** :
`PlatformDashboardMetrics.php:153` boucle sur les Organizations, et chaque tour appelle
`GuestShellUsageService.php:91-99` (7 agrégats) + `GuestShellPolicyService.php:143-144`
(2 agrégats) = **9 requêtes par Organization**.

À 50 Organizations, la même page tirerait ~1 000 requêtes. C'est le point de saturation
le plus net pour la montée en charge multi-Organizations.

---

## B. TOP 10 DES OPTIMISATIONS

Gains exprimés en **requêtes SQL supprimées par requête HTTP** — la grandeur que j'ai
mesurée exactement. Les gains en millisecondes PROD sont des estimations explicites,
parce que la latence Neon par requête n'est pas mesurable depuis ce poste (§F).

### B1 — P0 · Coût par sondage du ChatLoop
- **Problème** : chaque `wire:poll.3s` re-dérive un contexte invariant. 19 requêtes là où 6 suffisent.
- **Fichiers** : `resources/views/livewire/loop-chat.blade.php:4` (directive) ; `app/Livewire/LoopChat.php` (3 560 lignes, `render()`/propriétés calculées) ; `resources/views/livewire/message-thread.blade.php:1` (`wire:poll.3000ms`, même schéma).
- **Preuve** : 19 requêtes / ~90 ms / 4,2 Ko par cycle, liste complète des 19 requêtes relevée. 8 d'entre elles portent un contexte qui ne peut pas changer entre deux cycles de 3 s.
- **Correction envisagée** : sortir le contexte invariant du chemin de sondage (dossiers, `loop_cards`, `organization_ai_settings`, message épinglé, appartenance) — soit par propriétés calculées mises en cache sur le cycle de vie du composant, soit en faisant porter le sondage par une méthode étroite qui ne lit que les messages. Envisager 3 s → 6–10 s en second temps.
- **Gain** : **−13 requêtes par cycle, soit −260 requêtes/minute par onglet de chat ouvert**. CPU : ~2/3 du coût de rendu du sondage.
- **Risque de régression** : moyen. Le chat est la surface la plus visible ; un contexte trop agressivement mis en cache peut figer un panneau (dossiers, épinglé). L'envoi d'un message ne dépend pas du sondage — la latence perçue à l'émission n'est pas en jeu. Allonger l'intervalle dégrade la latence de *réception*, à arbitrer par Cyril.
- **Effort** : moyen.

### B2 — P0 · Les 8 requêtes dupliquées du socle
- **Problème** : 4 requêtes identiques exécutées 2 à 3 fois dans la même requête HTTP, sur toutes les pages.
- **Fichiers et lignes** : `app/Http/Middleware/ResolveUrlOrganization.php:350` (×2, via `:274` et `:219`) · `app/Ai/ProviderResolver.php:142` dans `tenantConfiguration()` (×3) · `app/Models/User.php:608-614` `unreadNotificationsCount()` (×2).
- **Preuve** : backtraces complètes relevées pour chacune ; socle de 18 requêtes sur `/boucles` listé requête par requête.
- **Correction envisagée** : mémoïsation **à l'échelle de la requête**, clé comprise. Pour `ProviderResolver`, un tableau interne indexé **par `organizationId`**. Pour `unreadNotificationsCount`, idem **par `organizationId`**. Pour le résolveur d'URL, mémoïser l'Organization par défaut sur l'instance de middleware.
- **Gain** : **−8 requêtes sur chaque page authentifiée**, soit −47 % du socle.
- **Risque de régression** : **faible, à une condition absolue** — toute mémoïsation doit être clé par `organization_id` (et par `user_id` pour le compteur) et ne vivre que le temps de la requête. Un cache statique indexé sur rien serait une **fuite inter-tenant**. `Organization = Tenant` : c'est le seul point de vigilance, et il est bloquant.
- **Effort** : faible.

### B3 — P0 · Appartenance évaluée 11 fois sur l'espace de travail
- **Problème** : `LoopMember::where(...)->exists()` répété 11 fois pour le même couple (Boucle, membre) dans un rendu.
- **Fichiers** : `app/Http/Controllers/LoopController.php:878-881` (`workspaceCardsFor`, appelé depuis `:802`, `:807`, `:808`, `:852`, `:853`) · `:905-910` (`placedCardsFor`, depuis `:816`, `:817`) · `:729` · `app/Livewire/LoopChat.php:510` (depuis `:458`, `:498`) · `app/Policies/LoopPolicy.php:108`.
- **Preuve** : 11 backtraces distinctes relevées sur un seul rendu de `/loops/{id}`.
- **Correction envisagée** : calculer `$isActiveMember` une fois dans `show()` et le passer aux deux helpers privés, qui ont déjà la même porte d'entrée documentée.
- **Gain** : **−10 requêtes** sur la page la plus lourde.
- **Risque** : faible. L'appartenance ne change pas pendant un rendu. Ne pas unifier la porte d'entrée des deux helpers : le commentaire en place dit explicitement qu'ils doivent garder la même règle, pas la même implémentation.
- **Effort** : faible.

### B4 — P1 · Agrégats par Organization sur `/admin/dashboard`
- **Problème** : 9 agrégats SQL par Organization, en boucle.
- **Fichiers** : `app/Services/Admin/PlatformDashboardMetrics.php:153` · `app/Services/GuestShell/GuestShellUsageService.php:91-99` · `app/Services/GuestShell/GuestShellPolicyService.php:143-144`.
- **Preuve** : 193 requêtes mesurées, dont 81 tracées à ces lignes (9 × 9 Organizations).
- **Correction envisagée** : un `GROUP BY organization_id, process, operation` avec agrégats conditionnels sur les fenêtres temporelles, en 1 à 2 requêtes pour toutes les Organizations.
- **Gain** : **−80 requêtes aujourd'hui ; −990 à 50 Organizations.** Supprime la dégradation linéaire.
- **Risque** : faible-moyen. Page SuperAdmin, trafic très faible, 1 utilisateur — d'où P1 et non P0. Les fenêtres temporelles doivent être reproduites exactement.
- **Effort** : moyen.

### B5 — P1 · N+1 `loop_cards` sur `/agenda` — le chemin rapide existe déjà
- **Problème** : 24 requêtes `select card_key from loop_cards`.
- **Fichiers** : `app/Services/Loops/LoopEventService.php:44`, `:49`, `:68` → `app/Support/Loops/LoopPermissionResolver.php:64` et `:215` → `app/Support/Loops/LoopTypeRegistry.php:550`.
- **Preuve** : 24 requêtes tracées ; `activeCardsFor()` (`LoopTypeRegistry.php:547-550`) teste **déjà** `relationLoaded('cards')` et évite la requête quand la relation est chargée.
- **Correction envisagée** : un `->with('cards')` sur la collection de Boucles que `LoopEventService` parcourt. Aucun code de registre à modifier.
- **Gain** : **−23 requêtes** sur `/agenda` (69 → ~46). Bénéficie aussi à `/loops/{id}` (`loop_cards` y est lu 4 fois).
- **Risque** : **très faible.** Le chemin rapide est déjà écrit et couvert.
- **Effort** : très faible. **Meilleur rapport gain/risque du rapport.**

### B6 — P1 · 3 requêtes par événement sur `/agenda`
- **Problème** : pour chaque événement, trois requêtes séparées sur `loop_event_responses` (décompte groupé, réponse du membre, toutes les réponses).
- **Fichiers** : `app/Services/Loops/LoopEventService.php:457`, `:475`, `:490` via `app/Support/Loops/LoopEventPresenter.php:32`, `:57`, `:66`.
- **Preuve** : 21 requêtes pour 7 événements (3 × 7), tracées.
- **Correction envisagée** : charger les réponses une fois par événement (ou en une requête pour tous les événements de la page) et dériver les trois lectures en PHP.
- **Gain** : **−14 à −19 requêtes** sur `/agenda`.
- **Risque** : faible. Trois présentations de la même donnée ; attention à conserver le décompte par type de réponse à l'identique.
- **Effort** : faible-moyen.

### B7 — P1 · N+1 `public_location` sur l'annuaire
- **Problème** : l'accesseur `public_location` charge paresseusement `organization` **et** `country` pour chaque membre affiché.
- **Fichiers** : `app/Models/User.php:269-280` (accesseur) · vue compilée appelant `$member->public_location` · requête de liste : `app/Http/Controllers/HomeController.php:233`, qui précharge `services` mais **ni `organization` ni `country`**.
- **Preuve** : 26 requêtes tracées (13 `organizations` + 13 `countries`) pour 13 membres, toutes depuis `User.php:275`.
- **Correction envisagée** : ajouter `organization` et `country` au `->with([...])` de `HomeController.php:233`.
- **Gain** : **−24 requêtes aujourd'hui ; jusqu'à −70 sur une page pleine** (`paginate(36)`).
- **Risque** : faible. **Ne pas restreindre les colonnes** (`organization:id,show_country`) sans inclure `deleted_at` : `Organization` est en `SoftDeletes` et la relation est filtrée dessus. Le préchargement nu est le choix sûr.
- **Effort** : très faible.

### B8 — P1 · 2 requêtes par notification sur `/notifications`
- **Problème** : pour chaque notification, le résolveur de cible vérifie l'accès par deux requêtes (Organization du destinataire, puis appartenance à la Boucle).
- **Fichiers** : `app/Support/Notifications/NotificationTargetResolver.php:327`+`:337`, `:429`+`:439`, `:151`+`:161`, appelés depuis `app/Http/Controllers/NotificationCenterController.php:627` (boucle de `:153`) et `:530`.
- **Preuve** : 12 requêtes tracées pour 6 notifications.
- **Correction envisagée** : résoudre les appartenances des Boucles citées en une passe avant la boucle, puis décider en mémoire.
- **Gain** : **−10 requêtes** (44 → ~34), proportionnel au nombre de notifications affichées.
- **Risque** : moyen — c'est un **contrôle d'accès**. La correction doit conserver exactement le même prédicat. `forRecipient()` est, par commentaire, la seule porte de lecture : la respecter.
- **Effort** : moyen.

### B9 — P2 · `morphTo` résolu un par un sur le Flux
- **Problème** : le rendu du flux résout le sujet polymorphe de chaque entrée individuellement (`loops`, `loop_events`, `transactions`, `services`, `users`).
- **Fichiers** : `app/Support/Feed/FeedEventRenderer.php:188`, `:278`, `:378`.
- **Preuve** : 61 requêtes sur `/flux`, dont ~26 de résolution de sujet, tracées.
- **Correction envisagée** : préchargement polymorphe (`with('subject')` / `loadMorph`) sur la collection du flux.
- **Gain** : **−20 requêtes environ** (61 → ~40).
- **Risque** : moyen. Le rendu lit plusieurs types ; un `loadMorph` incomplet ferait retomber silencieusement en N+1 (sans régression fonctionnelle).
- **Effort** : moyen.

### B10 — P2 · La pile éditeur est dans le bundle universel
- **Problème** : `resources/js/app.js` importe **statiquement** l'éditeur TipTap, l'éditeur markdown WYSIWYG, FilePond (+ son CSS) et SortableJS. Les 4 layouts chargent ce bundle, **y compris `guest.blade.php` (page de connexion)**.
- **Fichiers** : `resources/js/app.js:1-10` · `resources/views/layouts/app.blade.php:190`, `guest.blade.php:109`, `admin.blade.php:11`, `org-admin.blade.php:48`.
- **Preuve mesurée** : `app-Cm5vKLa2.js` = **890 Ko brut / 257 Ko gzip / 209 Ko brotli** ; `app.css` 228 Ko brut / 33 Ko gzip. Total `public/build` = 2,1 Mo. `deep-chat` (116 Ko gz) et `flowchart`/mermaid (138 Ko gz) sont **déjà** des chunks séparés — ce point-là est correctement fait.
- **Correction envisagée** : passer l'éditeur et FilePond en `import()` dynamique, déclenché par la présence de leur cible dans le DOM.
- **Gain** : **estimé** — l'éditeur et l'uploader représentent la majeure partie du bundle ; une baisse substantielle des 257 Ko gzip sur toutes les pages non-rédaction. **Chiffre exact non mesuré** : il demande une construction d'essai, hors périmètre read-only.
- **Risque** : faible-moyen, mais **à vérifier en recette réelle** : un éditeur chargé trop tard casse une page de rédaction. Surfaces à repasser : `/blog/rediger/*`, dossiers, profil.
- **Effort** : moyen.

---

## C. INFRASTRUCTURE, PROCESSUS ET COÛTS

### C1. Inventaire — LOCAL vs PROD, strictement séparé

| | LOCAL (ce poste, WSL) | PROD (Laravel Cloud `entraide`/`main`) |
|---|---|---|
| Serveur PHP | 2 × `artisan serve` (ports 8000 et 8097/`prodwork`) | PHP-FPM, **1 instance 1 vCPU / 512 Mo** (mesure 05/11) |
| OPcache | **CLI : désactivé** | activé (présumé, non vérifié) |
| PostgreSQL | local, ~17 processus, 27 Mo de données | **Neon serverless** |
| Worker de file | aucun démon | **`background-process:list` = `[]` → aucun** |
| Scheduler | — | `schedule:run` chaque minute |
| Redis / WebSocket | aucun | **aucun** (mesure 05/11) |
| Queue | `sync` | **`sync`** (confirmé au dashboard, release 1.764) |
| Cache / Session | `database` / `database` | **non vérifié** (§F) |

### C2. Processus nécessaires, redondants, ou inertes

**Nécessaires** : PHP-FPM, PostgreSQL, `schedule:run` chaque minute.

**Inerte — constat vérifié** : `Schedule::command('queue:work', [...])` chaque minute
(`routes/console.php:47-61`). Sous `QUEUE_CONNECTION=sync`, `queue:work` **sort
immédiatement en code 0 sans rien consommer** (testé localement), et les jobs
n'atteignent jamais la table `jobs` puisqu'ils s'exécutent en ligne. Ce worker, et son
allowlist soigneusement documentée par T1511/T1539, **ne produisent aujourd'hui aucun
effet**. Le système fonctionne quand même — par l'exécution en ligne — mais le filet
prévu n'en est pas un. **Rien à supprimer** : il redevient utile dès qu'une connexion
`database` est posée. À documenter, pas à démonter.

**Utile et bien réglé** : `knowledge:derive-due` toutes les 10 minutes. Ses jobs
s'exécutent en ligne **dans le processus du scheduler** (CLI), pas dans une requête
web — ici `sync` est sans inconvénient. `guest:purge-expired` quotidien,
`feed:publish-scheduled` chaque minute : légers.

### C3. Risques de saturation

1. **Mémoire** : 20,5 Mo par requête sur `/loops/{id}` (hors OPcache ; l'ordre de grandeur reste), pour **512 Mo** d'instance. Cela borne le nombre de workers PHP-FPM simultanés à quelques unités. **C'est la contrainte dure de la configuration actuelle.**
2. **CPU** : 1 vCPU partagé avec tout le travail synchrone — SMTP, Web Push, embeddings OpenAI. Un envoi lent monopolise une part significative de la capacité.
3. **Connexions PostgreSQL** : `sync` + cache `database` + session `database` placent Postgres sur le chemin critique de **tout**. Sur Neon serverless, chaque requête est un aller-retour réseau : c'est le multiplicateur qui rend les §B1/B2 importants. **Non mesuré depuis ce poste (§F).**
4. **Montée en charge multi-Organizations** : `/admin/dashboard` est le seul point de dégradation **linéaire en nombre d'Organizations** trouvé (§B4).

### C4. Coûts potentiellement évitables

- **Le poste le plus clair** : le sondage du ChatLoop. 380 requêtes SQL/minute par onglet de chat au premier plan, dont ~2/3 évitables (§B1). C'est du CPU et des allers-retours Neon payés en continu.
- **Les 8 requêtes du socle** (§B2), multipliées par toutes les pages de tous les membres.
- **Appels IA** : `organization_ai_settings` est relu 3 fois par page et 9 fois sur `/loops/{id}` **même quand aucune IA n'est utilisée** — c'est de la lecture de configuration, pas un coût provider. Je n'ai relevé **aucun appel provider redondant** : la chaîne RAG est correctement bornée (§C5).
- **Pas de surcoût d'hébergement identifié à supprimer** : il n'y a ni Redis, ni WebSocket, ni worker, ni service auxiliaire à retirer. L'infrastructure est déjà minimale. **Aucune nouvelle infrastructure n'est proposée.**

### C5. Ce qui est bien fait — et qu'il ne faut pas « optimiser »

En toute honnêteté d'audit, plusieurs points souvent problématiques sont ici solides :

- **La recherche sémantique est correctement bornée.** `DossierSemanticSearchService::search()` filtre `dossier_chunks.organization_id` **et** vérifie l'Organization sur chaque table jointe, avec `limit` plafonné à 5 (candidats ≤ 20). L'absence d'index vectoriel (`ivfflat`/`hnsw`) sur `dossier_chunks.embedding` est donc **sans conséquence** : le pré-filtre par tenant réduit l'ensemble avant le calcul de distance. **Je ne recommande pas d'index vectoriel.**
- **La pagination est saine.** Deux seuls `::all()` dans les contrôleurs, sur une bibliothèque de gabarits adossée à la configuration, pas à une table de volume.
- **Le découpage frontend est partiellement fait** : `deep-chat` et mermaid sont déjà des chunks séparés.
- **`sync` n'est pas une négligence** : c'est, comme T1777 l'a établi, ce qui fait fonctionner 4 files sans consommateur.
- **Le chemin rapide de `activeCardsFor()` est déjà écrit** — il suffit de l'alimenter (§B5).

### C6. Hygiène d'index — et correction d'une erreur de mon premier passage

533 index pour 162 tables. Mon premier relevé annonçait « 5 doublons exacts » :
**c'était faux, et je le corrige**. En lisant les définitions, la plupart sont des index
**partiels** ou **d'expression**, que ma requête de détection rendait identiques à tort :

- `users_email_lower_index` (`lower(email)`) vs `users_email_orphan_unique` (`lower(email) WHERE organization_id IS NULL`) → **tous deux justifiés**. Le premier sert la requête `where LOWER(email) = ?` que j'ai relevée dans le socle de chaque requête.
- `dossiers_personal_documents_unique` et `acquisition_journeys_one_published` → **partiels uniques, justifiés**.

Reste **un seul** index réellement redondant (`users_organization_id_index`, préfixe de
`users_organization_slug_unique`) et une vingtaine de redondances de préfixe.

**Gain réel à 27 Mo : négligeable.** Je ne propose ni migration ni suppression : le
bénéfice ne justifierait pas le risque. **P2, hygiène, à faire seulement si une TASK
touche déjà ces tables.**

---

## D. QUICK WINS — risque faible, gain mesuré

Par ordre de rapport gain/risque :

| # | Action | Gain mesuré | Risque | Effort |
|---|---|---|---|---|
| 1 | `->with('cards')` sur les Boucles parcourues par `LoopEventService` (§B5) | −23 req. sur `/agenda` | très faible (chemin rapide déjà écrit) | très faible |
| 2 | `organization` + `country` dans le `->with()` de `HomeController.php:233` (§B7) | −24 req., jusqu'à −70 page pleine | faible | très faible |
| 3 | `$isActiveMember` calculé une fois dans `LoopController::show()` (§B3) | −10 req. sur la page la plus lourde | faible | faible |
| 4 | Mémoïsation par `organizationId` dans `ProviderResolver` (§B2) | −2 req./page, −8 sur `/loops/{id}` | faible **si clé par tenant** | faible |
| 5 | Mémoïsation de `unreadNotificationsCount` et de l'Organization par défaut (§B2) | −3 req. sur chaque page | faible **si clé par tenant** | faible |

Total des quick wins : **−8 à −10 requêtes sur chaque page authentifiée**, et
**−33 requêtes sur `/agenda`** (69 → ~36). Aucune règle métier touchée, aucune migration.

---

## E. PLAN DE MISE EN ŒUVRE — 3 premières TASKs indépendantes

Petites, mesurables, sans dépendance entre elles. Chacune suit le protocole T1812 :
diagnostic → correctif → **recette → commit → mise à jour du taskfile, à CHAQUE correctif**.

### TASK 1 — « Préchargements manquants » (P0/P1, risque le plus faible)
**Périmètre** : §B5 (`loop_cards` sur `/agenda`) + §B7 (`public_location` sur l'annuaire) + §B3 (appartenance ×11).
**Mesure d'acceptation** (le harnais de ce rapport est reproductible à l'identique) :
`/agenda` 69 → ≤ 40 · `/membres` 52 → ≤ 30 · `/loops/{id}` 101 → ≤ 90.
**Pourquoi en premier** : trois corrections locales, aucun contrat métier touché, le chemin
rapide de `activeCardsFor()` est déjà écrit et couvert par les tests.

### TASK 2 — « Le socle d'une requête » (P0, le plus rentable)
**Périmètre** : §B2 — les 8 doublons du socle (`ProviderResolver`, `unreadNotificationsCount`, Organization par défaut).
**Mesure d'acceptation** : `/boucles` 17 → ≤ 10, et le gain doit se constater **sur toutes**
les routes du tableau de mesure.
**Garde bloquante à prouver par sabotage** : chaque mémoïsation est clé par
`organization_id` (et `user_id` le cas échéant) et ne survit pas à la requête. Un test qui
rougit si un second tenant lit la valeur du premier. **`Organization = Tenant` n'est pas
négociable** — c'est la seule raison pour laquelle cette TASK pourrait mal tourner.

### TASK 3 — « Le coût d'un sondage du ChatLoop » (P0, le plus gros gain récurrent)
**Périmètre** : §B1 — sortir le contexte invariant du chemin de sondage de `LoopChat`.
**Mesure d'acceptation** : un cycle `$refresh` passe de 19 requêtes à ≤ 8, snapshot ≤ 4,2 Ko,
sans changement visible à l'écran.
**Arbitrage demandé à Cyril avant de commencer** : garde-t-on 3 s, ou passe-t-on à 6–10 s ?
Le gain CPU est proportionnel, le coût est la latence de *réception* d'un message.
**À faire en dernier des trois** : c'est la surface la plus visible du produit, et celle
qui demande une recette navigateur réelle.

**Hors de ces trois TASKs, et volontairement** : §B4 (`/admin/dashboard`, 1 utilisateur,
trafic négligeable), §B9 (`morphTo`), §B10 (bundle frontend, demande une construction
d'essai), §C6 (index, gain négligeable), et **toute évolution de la file d'attente** — qui
doit suivre la séquence de T1777 et relève d'une décision d'infrastructure, pas d'un
correctif de performance.

---

## F. POINTS NON VÉRIFIABLES — aucun résultat inventé

1. **Configuration Laravel Cloud non lisible.** `cloud environment:get` a été **refusé par le classifieur** de l'outil. Je n'ai donc **pas** vérifié par moi-même, sur la PROD : `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, la taille d'instance, les variables masquées. Les valeurs PROD citées (`sync`, 1 vCPU/512 Mo, 0 worker, 0 Redis) viennent des mesures antérieures consignées (release 1.764, infra mesurée le 05/11) — **pas d'une mesure de ce jour**.
2. **`config:cache` / `route:cache` en PROD : état inconnu.** Localement, `bootstrap/cache/` ne contient ni `config.php`, ni `routes-*.php`, ni `events.php` — mais c'est normal en développement, et les commandes de construction Laravel Cloud ne sont pas dans le dépôt. Avec **982 routes** et 31 fichiers de configuration, l'écart serait réel sur 1 vCPU. **À vérifier en premier, avant toute autre optimisation CPU** : si ces caches ne sont pas posés au déploiement, c'est probablement le plus gros gain disponible, pour un changement de configuration sans risque de code. Je n'ai pas pu le mesurer : ma tentative de chiffrage local du coût de registre de routes a donné 0,1 ms, chiffre **non valide** (l'enregistrement est paresseux et n'avait pas été déclenché) — je ne le retiens donc pas.
3. **Latence PostgreSQL PROD par requête : non mesurée.** C'est le facteur qui convertit « nombre de requêtes » en « millisecondes ». Sur Neon serverless, l'écart avec mon PostgreSQL local (sub-ms) est probablement d'un ordre de grandeur. **Tout gain en ms annoncé dans ce rapport serait une invention** : je n'en annonce aucun, seulement des requêtes supprimées.
4. **`pg_stat_statements` absent** (extensions : `plpgsql`, `vector` seulement). Aucun plan ni statistique de requête réelle disponible, ni localement ni en PROD. **Aucun `EXPLAIN ANALYZE` n'a été lancé** : à 27 Mo, tous les plans seraient des balayages séquentiels rapides et ne diraient rien d'utile sur la PROD.
5. **Index non utilisés : non déterminable.** `idx_scan` sur le jumeau ne reflète que mon propre usage local depuis la copie, pas le trafic PROD. **Je n'ai donc produit aucune liste d'« index non utilisés »**, bien que la requête soit triviale : elle n'aurait rien prouvé.
6. **Fan-out de notifications non re-mesuré.** `MAIL_MAILER=smtp` et des clés VAPID réelles sont actives dans le `.env` local : publier un message aurait envoyé de vrais emails et de vraies notifications push. **Je m'en suis abstenu.** Le coût SQL du fan-out est celui mesuré par T1778 (2 400 → 200 requêtes) ; le coût réseau par envoi (SMTP, Web Push) n'est **pas** mesuré.
7. **Le gain frontend de §B10 est une estimation**, pas une mesure : le chiffrer exigerait une construction d'essai avec imports dynamiques, hors périmètre read-only.
8. **Aucune recette navigateur authentifiée.** Les mesures passent par le noyau HTTP, pas par un vrai navigateur : le coût JS réel, le temps de premier rendu et le comportement du sondage côté client ne sont pas mesurés.
9. **Volume PROD non relu ce jour.** Les volumes cités sont ceux du jumeau `prod_work` (baseline 1.595 + travail local). La lecture de la base PROD est refusée par le classifieur.

---

## ANNEXE — Tableau de mesure de référence

Jumeau `bouclepro_prod_work`, PHP 8.4 CLI **sans OPcache**, PostgreSQL local, utilisateur
`cslucki@gmail.com` (Organization `main`), une requête par processus (comme PHP-FPM).
**Requêtes SQL : exactes et reproduites 3 fois. `wall ms` : indicatif, hors OPcache.**

| Route | Requêtes | SQL ms | wall ms | Mém. Mo |
|---|---|---|---|---|
| `/admin/dashboard` | **193** | 122 | 210 | 8,5 |
| `/loops/{id}` (espace de travail) | **101** | 95 | 518 | 20,5 |
| `/agenda` | 69 | 42 | 66 | — |
| `/flux` | 61 | 36 | 109 | — |
| `/notifications/preferences` | 55 | 32 | 101 | — |
| `/membres` (annuaire) | 52 | 43 | 123 | — |
| `/notifications` | 44 | 60 | 43 | — |
| `/dashboard` | 30 | 35 | 92 | 6,5 |
| `/explorer` | 26 | 18 | 41 | — |
| `/blog` | 26 | 22 | 44 | — |
| `/echanges` | 25 | 22 | — | — |
| `/points` | 25 | 23 | — | — |
| `/messages` | 21 | 19 | — | — |
| `/boucles` (socle quasi nu) | 17 | 15 | 20 | — |
| **ChatLoop — 1 cycle `wire:poll.3s`** | **19** | 11 | 90 | snapshot 4,2 Ko |
| ChatLoop — montage du composant | 26 | 24 | 215 | — |

---

**FIN DU RAPPORT — STOP.** Aucun correctif appliqué, aucun fichier applicatif modifié,
aucun commit, aucune écriture PROD. En attente de l'arbitrage de Cyril sur §E.
