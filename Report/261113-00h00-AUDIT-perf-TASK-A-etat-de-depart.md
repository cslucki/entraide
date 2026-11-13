# TASK A — DOSSIER PRÊT À LANCER ET ÉTAT DE DÉPART

**Date** : 13/11/2026, 00h18 Paris · **Mode** : préparation seule, `LOCAL_OPS / no branch / no commit`
**Référence de mesure** : `39160c3e` · VERSION `1.812` · branche `TASK-1812-…`
**`DATABASE_TARGET`** = `prod_work` · **Aucun GO_WRITE pris.**

---

## 1. ÉTAT DE DÉPART — TASK A EST BLOQUÉE, ET C'EST NORMAL

Point 8 de l'arbitrage : vérifier le merge de T1812, la synchronisation de `develop`
et la propreté Git **avant** de créer A.

| Contrôle | État mesuré | Verdict |
|---|---|---|
| PR T1812 | **#812 OPEN**, non-draft, `mergeable: MERGEABLE` | **pas fusionnée** |
| `origin/develop` | `06791d0c` — « Merge PR #810 … TASK-1811 » | **encore en 1.811** |
| Commits T1812 absents de `develop` | **12** | — |
| Branche T1812 poussée | `origin/TASK-1812-…` présente | oui |
| Arbre de travail | **1 fichier non suivi** : ce rapport. Voir l'encadré ci-dessous | à traiter |
| Prochain numéro alloué | **1813** (`MAX_GLOBAL + 1`, max consommé = 1812) | libre |
| Autre PR ouverte | **#781** (draft, prérequis Managed Queue) | sans rapport |

**Conclusion : A ne peut pas être créée maintenant.** Il manque trois choses, dans
cet ordre : la fusion de #812, `develop` ramené à 1.812, et un arbre propre.

### Les rapports de cette campagne — fait nouveau à 00h00

**Mes deux rapports précédents ne sont plus sur le disque** :
`261112-23h26-AUDIT-performance-globale-bouclepro.md` et
`261112-23h52-AUDIT-perf-plan-execution-3-tasks.md` étaient non suivis dans `Report/`
vers 23h52 ; ils sont **absents à 00h00**, et n'ont jamais été commités (`HEAD` est
inchangé à `39160c3e`). Je constate, je n'explique pas : je n'ai lancé aucune commande
de suppression.

**Aucun contenu n'est perdu** : les deux documents sont intégralement conservés dans
les artifacts publiés, et peuvent être réécrits sur disque à la demande.

L'arbre ne porte donc plus qu'**un** fichier non suivi : ce rapport. La règle du dépôt
veut les rapports versionnés dans `Report/`, et `develop` est protégé (push direct
rejeté) : ils doivent **partir avec la PR #812**. **C'est ton geste, pas le mien.**

---

## 2. LA CAUSE RACINE DES 3 LECTURES `organization_ai_settings` — MESURÉE, ET CE N'EST PAS CE QUE JE CROYAIS

J'avais proposé « mémoïser `ProviderResolver` ». **C'était la mauvaise correction.**
Les deux portes IA **mémoïsent déjà**, et l'une est déjà clé par `organizationId` :

- `app/Support/Ai/AiSurfaceGate.php:51` → `$this->memo[$organizationId] ??= $this->resolver->isAvailableFor($organizationId)`
- `app/Support/Ai/AiFabContext.php:160` → `$this->memo[$user->id] ??= $this->build(...)`

Le mémo existe, il est correctement clé — **il ne sert simplement à rien.** Mesure du
nombre d'instances distinctes créées dans **une seule** requête :

| Classe | Liaison conteneur | `/boucles` | `/dashboard` |
|---|---|---|---|
| `AiFabContext` | **`scoped()`** (`AppServiceProvider.php:125`) | **1** instance ✅ | **1** instance ✅ |
| `AiSurfaceGate` | **aucune liaison** | **2** instances ❌ | **4** instances ❌ |
| `ProviderResolver` | **aucune liaison** | **3** instances ❌ | **6** instances ❌ |
| → `organization_ai_settings` | | **3 requêtes** | **3 requêtes** |

`AiSurfaceGate` est injecté par `@inject` dans **au moins 6 Blade du même layout**
(`user-dashboard-nav`, `app-side-nav`, `mobile-topbar`, `layouts/navigation`,
`blog-editor`, `requests/create`), plus `DashboardController:107` et `:156`,
`LoopCardRegistry:470`, `MemberOnboardingSteps:101`. **Chaque résolution fabrique une
instance neuve au mémo vide.** Le mémo ne franchit jamais la frontière d'un appelant.

Second chemin : `AiFabContext.php:282` appelle `ProviderResolver::isAvailableFor()`
**directement**, en contournant la porte — donc son propre mémo, pourtant efficace,
ne couvre pas cette lecture-là.

### La correction, en conséquence : deux gestes, aucun cache neuf

1. **Lier `AiSurfaceGate` en `scoped()`** — une ligne, à côté des trois `scoped()`
   déjà présentes (`AppServiceProvider.php:125, 129, 143`). Le mémo déjà écrit se met
   alors à fonctionner comme son auteur l'avait prévu.
2. **`AiFabContext.php:282` passe par `AiSurfaceGate`** au lieu d'appeler
   `ProviderResolver` directement.

**`ProviderResolver` n'est ni lié, ni mémoïsé, ni touché.** C'est l'autorité qui
dépense et qui applique la configuration IA du tenant : elle continue de relire à
chaque fois. **On mémoïse la porte d'affichage, jamais l'autorité.**

---

## 3. LE MÉCANISME EXISTE DÉJÀ — ET SA DOCTRINE INTERDIT DEUX DE MES QUATRE CANDIDATS

`app/Support/ScopedReadCache.php` (T1778), lié en `scoped()` à
`AppServiceProvider.php:143`. Son docblock est une doctrine, et il est explicite :

> « Ce n'est **pas un cache** au sens de l'infrastructure : aucun Redis, aucun
> Memcached, aucun fichier, rien qui survive au processus, rien qui se partage entre
> deux visiteurs. C'est un tableau PHP dont la durée de vie est bornée par le
> conteneur. »
>
> « Uniquement des lectures de RÉFÉRENCE […] : une Organization, une Boucle, la langue
> d'une Organization. **Jamais une préférence, jamais une appartenance, jamais un
> statut de livraison.** […] **Jamais du contenu ADMINISTRABLE non plus.** Le gabarit
> d'email a été mémorisé ici le temps d'une mesure, puis **retiré**. »

`scoped()` est vidé **entre deux requêtes HTTP et entre deux jobs de file** — donc
aussi entre deux commits Livewire, qui sont des requêtes HTTP. La protection
« aucun état périmé entre deux commits Livewire » est tenue **par construction**.

### Application de la doctrine à mes quatre familles

| Famille | × | Admissible dans `ScopedReadCache` ? |
|---|---|---|
| `organizations where is_default` | 2 | **OUI** — « une Organization » est l'usage listé comme permis |
| `organizations` par id | 2 | **OUI** — idem |
| `organization_ai_settings` | 3 | **NON** — **administrable** : écrit depuis `OrgAdminController`, `AdminOrganizationController`, `AiUserCreditSettings`, `AiRerankSettings`. C'est exactement le cas du gabarit d'email, retiré |
| `member_notifications` non lus | 2 | **NON** — c'est un **statut**, qui peut changer en cours de requête |

**Deux de mes quatre candidats sont interdits par la doctrine du dépôt.** D'où la
correction de la §2 pour l'un, et un troisième moyen pour l'autre.

### `member_notifications` : aucun cache, de la simplification

Les deux lectures viennent du **même** `User.php:608-614`, appelé par **deux partials
du layout** (badge de l'en-tête et badge du menu mobile) dans le même rendu.

La correction n'est pas un mémo : **le layout calcule la valeur une fois et la passe
aux deux partials.** Aucun état retenu, aucune fenêtre de péremption ouverte, rien à
invalider. C'est la « simplification » que l'arbitrage privilégie.

---

## 4. TASK A — PÉRIMÈTRE DÉFINITIF

| # | Famille | × | Moyen | Gain | Cache neuf ? |
|---|---|---|---|---|---|
| 1 | `organization_ai_settings` | 3 | `scoped(AiSurfaceGate)` + `AiFabContext:282` passe par la porte | **−2** | non — mémo déjà écrit |
| 2 | `organizations where is_default` | 2 | `ScopedReadCache` | **−1** | non — service existant |
| 3 | `organizations` par id | 2 | `ScopedReadCache` | **−1** | non — service existant |
| 4 | `member_notifications` non lus | 2 | calcul unique dans le layout, passé aux deux partials | **−1** | **aucun cache** |
|  |  |  | **TOTAL** | **−5 / page** | **aucun nouveau mécanisme** |

La cible `−5` de la §2 du plan est donc **tenue** — mais par des moyens différents de
ceux que j'avais proposés, et sans ajouter un seul cache, conformément au point 2.

### Fichiers concernés — 5, et aucun n'appartient à T1812

| Fichier | Geste |
|---|---|
| `app/Providers/AppServiceProvider.php` | +1 ligne : `$this->app->scoped(AiSurfaceGate::class);` |
| `app/Support/Ai/AiFabContext.php` (≈ 282) | passer par `AiSurfaceGate` au lieu de `ProviderResolver` |
| `app/Http/Middleware/ResolveUrlOrganization.php` (350, appelé de 219 et 274 ; 370) | `ScopedReadCache::souviens()` sur les deux lectures d'Organization |
| `resources/views/layouts/*` + les 2 partials de badge | calcul unique du compteur, passé en paramètre |
| `app/Models/User.php` (608-614) | **inchangé si possible** — la méthode reste la porte de lecture |

**Intersection avec les 12 correctifs de T1812 : vide.** T1812 a touché
`AdminController`, `HomeController`, `DeviceKind`, les fichiers de langue et des Blade
d'admin/annuaire/flux. **Seul `VERSION` sera en conflit**, comme toujours.

⚠️ **Un point de vigilance** : le geste n° 4 touche les **layouts partagés**. T1812 a
modifié `resources/views/components/user-dashboard-nav.blade.php` — qui est précisément
l'un des fichiers où `AiSurfaceGate` est `@inject`. **Pas de conflit de contenu** (A n'y
touche pas pour le n° 1), mais à revérifier au moment de brancher, après la fusion de #812.

### Métriques avant / après — critères d'acceptation

Baseline `39160c3e`, requêtes **applicatives** (plomberie de session comptée à part),
reproductible à l'identique sur 2 passes.

| Route | Avant | Cible | Dont |
|---|---|---|---|
| `/boucles` | 14 | **≤ 9** | −5 |
| `/dashboard` | 27 | **≤ 23** | −4 (pas de `is_default` sur cette route) |
| `/notifications` | 41 | **≤ 36** | −5 |
| `/flux` | 58 | **≤ 53** | −5 |
| `/agenda` | 66 | **≤ 61** | −5 |
| `/loops/{id}` | 98 | **≤ 88** | −10 (`organization_ai_settings` y est lu **9 fois**) |

**Aucun critère en millisecondes.** Les mesures de ce poste sont en CLI sans OPcache :
elles ne sont pas des mesures PROD, et ne sont pas contractuelles.

### Tests ciblés

- **Nouveau test de coût**, gabarit `tests/Feature/TASK1778FanOutSqlCostTest.php` :
  une assertion de plafond par famille, sur au moins `/boucles` et `/loops/{id}`.
- **Test d'instance unique** : `AiSurfaceGate` résolu deux fois dans la même requête
  rend **la même instance** — c'est la garde qui empêche la régression silencieuse du
  mémo (le défaut actuel ne se voit par aucun test fonctionnel).
- **Existants à faire repasser** : `ResolveUrlOrganization` (24 fichiers),
  `ProviderResolver` (31), `unreadNotificationsCount` (3).

### Critères de non-régression — les 5 protections de l'arbitrage

1. **Organization = Tenant** — sabotage obligatoire : un test qui **rougit** si une
   seconde Organization lit la valeur mémoïsée de la première. Les clés sont
   `organizationId` pour la porte IA, et doivent contenir « tout ce qui fait varier la
   réponse » (doctrine `ScopedReadCache`).
2. **Permissions toujours recalculées** — `ProviderResolver` n'est pas mémoïsé.
   `resolve()`, qui dépense, relit à chaque appel. Aucune appartenance n'entre dans
   `ScopedReadCache` : sa doctrine l'interdit, et TASK C utilisera le motif
   `render()`-scope de T1109, pas celui-ci.
3. **Aucun état périmé entre deux commits Livewire** — `scoped()` est vidé entre deux
   requêtes HTTP, et un commit Livewire **est** une requête HTTP. Tenu par construction.
   Réserve connue (T1109) : plusieurs `calls` d'un **même** commit partagent l'instance.
   Acceptable pour une porte d'affichage ; ce serait inacceptable pour une permission —
   et aucune permission n'est dans ce périmètre.
4. **Aucun état sensible dans les snapshots Livewire** — rien de ce périmètre n'est une
   propriété de composant. Garde reprise de
   `TASK1109RoadmapMembershipCostTest::test_the_cache_never_travels_in_the_livewire_snapshot()`,
   qui **lit le snapshot réel** et non les noms de propriétés.
5. **Multi-tenant** — `T0754DashboardMembersExchangesTenantSafetyTest`,
   `T07411RoutesTenantSafetyTest` verts.

### Séquence de workflow — point 8

```
# préalables, dans l'ordre, AUCUN pris par moi
1.  rapports de Report/ commités sur la branche T1812   (geste de Cyril)
2.  gh pr merge 812  via  ai/scripts/merge-task.sh 1812 --yes
    -> prouver  pr.state == MERGED  (merge-task.sh sort en code 0 SANS fusionner sans --yes)
3.  git checkout develop && git pull   -> develop doit afficher 1.812
4.  git status  -> propre AVANT toute création
5.  ai/scripts/create-task.sh "Socle d une requete : requetes communes repetees" CLAUDE
    -> alloue 1813, crée la branche depuis develop
6.  ai/scripts/bump-version.sh  -> VERSION = 1.813  (contrat TASK/VERSION = égalité bloquante)
7.  correctif 1 -> recette -> commit -> mise à jour du taskfile.  Puis correctif 2. Jamais en lot.
8.  ai/scripts/check-task.sh 1813
9.  ai/scripts/finalize-task.sh 1813  -> PR
10. CI GitHub pour la validation élargie ; tests ciblés en local
```

Rappel local : les tests sur base serveur exigent `BOUCLEPRO_TEST_DB_APPROVED=1`,
sinon 83 échecs / 0 assertion sur `ArtisanDatabaseGuard` — à ne pas prendre pour une
régression.

---

## 5. POINT 6 — AUDIT DES `env()` RUNTIME, FAIT

L'arbitrage demandait cet audit **avant** toute proposition d'activation de
`config:cache`. Résultat : **il n'existe que 2 appels PHP `env()` hors `config/`** dans
tout le code applicatif (`app/`, `routes/`, `bootstrap/`, `database/`) :

| Appel | Contexte |
|---|---|
| `app/Support/Ops/DestructiveDatabaseGuard.php:346` | garde branchée sur l'événement `CommandStarting` — **console uniquement** |
| `app/Support/Ops/ArtisanDatabaseGuard.php:239` | garde avec `if (! $app->runningInConsole())` en amont (`:145`) — **console uniquement** |

Les 25 occurrences de `env(` dans `resources/views` sont du **CSS
`env(safe-area-inset-*)`** — sans aucun rapport. 13 autres sont dans `tests/`.

**Aucun `env()` runtime n'est atteignable depuis une requête web PROD.** Les deux
existants sont des marqueurs d'approbation CLI, et leur dégradation sous config cache
irait vers le **refus** (`filter_var(null, VALIDATE_BOOL)` = `false`) — le sens sûr.

**Nuance à ne pas oublier** : Laravel Cloud injecte les variables comme variables
d'environnement **du processus**, pas via un fichier `.env`. Sous `config:cache`,
`env()` continue donc de les lire. Le piège classique du `.env` ignoré ne s'applique
probablement pas ici — mais je ne l'ai pas prouvé, et ce n'est pas nécessaire : il n'y
a rien à casser dans le chemin web.

### `route:cache` et optimisations réellement actives

Relevé sur les **10 derniers déploiements** (jusqu'au 05/11), `buildCommand`
**identique à chaque fois** :

```
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --audit false
npm run build
```
`deployCommand` : `php artisan migrate --force`. Déploiement PROD courant :
`depl-a335e1eb` = commit `1c2f4a07`, terminé le 12/11 à 21h17 UTC.

- **Optimisation réellement active** : `--optimize-autoloader` (Composer). Oui.
- **Aucune commande d'optimisation Laravel explicite**, sur aucun des 10 déploiements.
- **Ce que je ne peux pas prouver** : que la plateforme n'exécute pas `php artisan
  optimize` **implicitement** après le build. Aucun champ du CLI ne l'expose, et aucune
  sonde HTTP anonyme ne peut le distinguer — `config:cache` et `route:cache` ne changent
  pas la réponse servie. L'observable décisif serait la présence de
  `bootstrap/cache/config.php` sur l'instance, qui demande un accès shell PROD.
- `usesHibernation: true` reste le fait aggravant : chaque réveil repaie l'amorçage.

**Conformément au point 6 : aucune activation PROD proposée pendant cette campagne.**

---

## 6. POINT 7 — VAPID : MESURES NÉCESSAIRES, SANS AFFICHER DE VALEUR

**Incident local documenté** : lors de l'audit du 23h26, mon masquage a échoué en
lisant `MAIL_MAILER` et la clé privée VAPID du `.env` local **s'est affichée dans la
sortie de session**. Elle n'est ni dans un rapport, ni dans un artifact, ni commitée.

**Constat Git — vérifié par empreinte, aucune valeur réaffichée** :

| Contrôle | Résultat |
|---|---|
| `.env` ignoré / suivi | ignoré (`.gitignore:5`) / **non suivi** |
| `.env.example` : valeurs VAPID | **vides** (longueur 0), publique comme privée |
| Commit `9859c2ea` « clef privee jamais exposee » | n'a introduit que des valeurs **vides** — titre exact |
| Clé privée locale dans `HEAD` | **NON** (`git grep -F`) |
| Clé privée locale dans **tout** l'historique | **NON** (`git log --all -S`) |
| Permissions `.env`, `.env.prodwork` | `-rw-r--r--` — lisibles par tout compte du poste |

**Le dépôt public `cslucki/entraide` n'a jamais porté cette clé.**

### La question qui reste, et comment y répondre sans toucher au secret

La clé privée locale est-elle **celle de PROD** ? Je ne peux pas le déterminer :
la valeur PROD est masquée par le CLI, la clé publique n'est servie que sur des pages
**authentifiées** (`notifications/push`, `push-first-run`), et la lecture de la base
PROD est refusée. **Aucune sonde anonyme ne permet la comparaison.**

**Procédure sûre, à exécuter par Cyril** — elle ne manipule que la clé **publique**,
qui n'est pas un secret, et les paires VAPID étant 1:1, elle est concluante :

```
empreinte de la publique LOCALE  (calculée, sans afficher la valeur) :
    sha256(VAPID_PUBLIC_KEY local)[0:16] = bba3088340633af3

côté PROD : révéler VAPID_PUBLIC_KEY au tableau de bord Cloud, la passer à
sha256sum, comparer ces 16 caractères.

  empreintes ÉGALES        -> même paire : la privée locale EST celle de PROD
                              -> rotation VAPID prudente (et ré-abonnement des
                                 navigateurs : changer la paire invalide les
                                 abonnements push existants — à anticiper)
  empreintes DIFFÉRENTES   -> paires distinctes : clé de développement
                              -> incident clos, aucune action PROD
```

**La clé privée n'a été ni lue, ni hachée, ni affichée dans ce contrôle.**

**Mesures recommandées, indépendamment du résultat** :
1. `chmod 600 .env .env.prodwork` — ils portent des secrets et sont en `644`.
2. Conserver cet incident comme **incident local documenté** (point 7), sans action
   PROD tant que la comparaison d'empreintes n'est pas faite.

---

## 7. CE QUI RESTE HORS DE CE TOUR, ET NON OUBLIÉ

- **Matrice des 8 contextes du sondage ChatLoop** (point 3) : rattachée à la
  préparation de **TASK B**, pas anticipée ici — l'autorisation porte sur A.
  Elle devra couvrir, pour chacun : fonction et dépendances exactes, fréquence
  réellement nécessaire, conséquence d'un rafraîchissement différé, mécanisme existant
  réutilisable, tests de non-régression.
- **`message-thread.blade.php`** (point 5) : hors périmètre de B. Similarité à
  documenter — même directive `wire:poll.3000ms`, surface « messagerie d'entraide »,
  **coût jamais mesuré**. TASK indépendante si nécessaire.
- **TASK C** : baseline à reprendre **après** B, les deux touchant `LoopChat.php`.
  Rappel acquis ici : `ScopedReadCache` **interdit** l'appartenance — C devra utiliser
  le motif `render()`-scope de T1109.

---

**FIN DE LA PRÉPARATION — STOP.**
Aucun fichier applicatif modifié · aucun commit · aucune branche créée · aucune
écriture PROD · aucune valeur sensible lue ni affichée.
**TASK A est prête à lancer, et bloquée sur la fusion de la PR #812.**
