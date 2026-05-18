---
task_id: TASK-099
title: t076-0-public-french-routing-ui-wording-audit

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-099-t076-0-public-french-routing-ui-wording-audit

priority: MEDIUM

created_at: 2026-05-18 05:19:43 Europe/Paris
updated_at: 2026-05-18 06:03:35 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

T076.0 — Public French Routing & UI Wording Audit.

Audit public routing and public UI wording so every public URL and every public-facing UI string is French, while preserving tenant architecture rules and runtime compatibility.

This task is an audit/preparation task only at creation time. No implementation changes are authorized in this initialization patch.

---

# Planned Actions

- [x] inventory public routes and detect any English public URL segments
- [x] inventory public-facing UI wording and detect any English text
- [x] verify public routes remain Organization-scoped and are not treated as global
- [x] document required follow-up implementation scope without modifying routes/controllers/views/tests in this initialization step
- [x] define validation scope for future routing, UI, tenant, and browser checks

Constraints:

- develop is the base branch
- main / PROD must not be touched
- one task = one branch = one TASK file
- patch expected during initialization: TASK file only
- Organization = Tenant
- Loop != Tenant
- Partner != Tenant
- Public != global
- current_organization remains canonical runtime tenant context
- community_id remains temporary legacy database compatibility
- do not introduce new Community concepts
- no public URL may be in English
- no public UI may be in English
- do not modify routes/controllers/views/tests during task initialization

---
# Progress Log


## 2026-05-18 05:19:43 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-099-t076-0-public-french-routing-ui-wording-audit

Status:
IN_PROGRESS

## 2026-05-18 05:20:07 Europe/Paris

OPENCODE initialized the task scope after create-task.sh.

Initialization constraints recorded:

- audit public French routing and public UI wording
- preserve Organization tenant boundary
- keep Partner and Loop non-tenant concepts
- preserve current_organization as canonical runtime context
- keep community_id as temporary legacy DB compatibility only
- no implementation changes during initialization
- TASK file is the only expected patch

## 2026-05-18 05:30:24 Europe/Paris

OPENCODE completed the read-only audit for T076.0.

Files inspected without runtime modification:

- `routes/web.php`
- `routes/auth.php`
- `app/Http/Controllers/HomeController.php`
- `app/Http/Controllers/CommunityRequestController.php`
- `app/Http/Controllers/LoopController.php`
- `resources/views/layouts/navigation.blade.php`
- `resources/views/home.blade.php`
- `resources/views/partials/footer.blade.php`
- `resources/views/boucles/index.blade.php`
- `resources/views/community-requests/create.blade.php`
- `resources/views/loops/index.blade.php`
- `resources/views/loops/create.blade.php`
- `resources/views/community/landing.blade.php`
- `resources/views/auth/register.blade.php`

Commands executed for audit only:

- `rg`-powered searches via OpenCode Grep for `boucles`, `loops`, `partners`, `partenaires`, `organization`, `organisation`, and related CTA wording.
- `php artisan route:list --path=boucles --json`
- `php artisan route:list --path=loops --json`
- `php artisan route:list --path=partners --json`
- `php artisan route:list --path=organization --json`
- `php artisan route:list --path=partenaires --json`
- `php artisan route:list --path=organisation --json`

No route, Blade, controller, migration, test, or runtime file was modified.

---

# Audit Findings

## Route Matrix Actuelle

| Route | Nom Laravel | Controleur / vue | Public / auth | Anglais / francais | Statut |
| --- | --- | --- | --- | --- | --- |
| `/` | `home` | `HomeController@index` -> `resources/views/home.blade.php` | Public | Francais visible, route neutre | keep |
| `/explorer` | `explorer` | `ExplorerController@index` | Public | URL anglaise | rename later vers route francaise a arbitrer, car hors scope strict partenaires/boucles mais contraire a "aucune URL publique en anglais" |
| `/membres` | `members.index` | `HomeController@members` -> `members.index` | Public, mais 404 si aucune organization runtime | Francais | keep, verifier tenant scope dans T076.1 |
| `/echanges` | `exchanges.index` | `HomeController@exchanges` -> `exchanges.index` | Public, mais 404 si aucune organization runtime | Francais | keep, verifier tenant scope dans T076.1 |
| `/boucles` | `boucles.index` | `HomeController@boucles` -> `resources/views/boucles/index.blade.php` | Public | Francais | rename/rework later: URL canonique future des vraies Boucles, mais sert actuellement la liste des `Community` actives, donc confusion Loop/Partner/Organization |
| `/boucles/creer` | `boucles.request.create` | `CommunityRequestController@create` -> `resources/views/community-requests/create.blade.php` | Public | Francais | redirect/rename later vers `/partenaires/demande`; ne doit plus etre sous `/boucles` |
| `/boucles/creer` POST | `boucles.request.store` | `CommunityRequestController@store` -> `CommunityRequest` persistence | Public | Francais | redirect/rename later vers POST `/partenaires/demande`; garder compatibilite temporaire si besoin SEO/bookmarks |
| `/blog` | `blog.index` | `BlogController@index` | Public | Francais/neutre | keep |
| `/blog/categorie/{slug}` | `blog.category` | `BlogController@byCategory` | Public | Francais | keep |
| `/blog/tag/{slug}` | `blog.tag` | `BlogController@byTag` | Public | `tag` est anglais mais largement conventionnel | defer, hors premiere tache runtime |
| `/blog/{post:slug}` | `blog.show` | `BlogController@show` | Public | Francais/neutre | keep |
| `/sitemap.xml` | `sitemap` | `SitemapController@index` | Public | Standard technique | keep |
| `/search` | `search` | `SearchController@index` | Public | URL anglaise | rename later vers `/recherche` avec redirection 301/302 a arbitrer |
| `/mentions-legales` | `mentions-legales` | `Route::view('mentions-legales')` | Public | Francais | keep |
| `/login` | `login` | `AuthenticatedSessionController@create` | Public guest | URL anglaise | defer, hors scope partenaire/boucles mais contraire a cible full-FR; futur `/connexion` |
| `/register` | `register` | `RegisteredUserController@create` -> `auth/register.blade.php` | Public guest | URL anglaise | defer, futur `/inscription` |
| `/forgot-password` | `password.request` | `PasswordResetLinkController@create` | Public guest | URL anglaise | defer, futur `/mot-de-passe-oublie` |
| `/reset-password/{token}` | `password.reset` | `NewPasswordController@create` | Public guest | URL anglaise | defer, futur `/reinitialiser-mot-de-passe/{token}` |
| `/services/{service}` | `services.show` | `ServiceController@show` | Public | URL anglaise | defer, verifier impact metier et tenant avant francisation |
| `/requests/{request}` | `requests.show` | `RequestController@show` | Public | URL anglaise | defer, futur equivalent demandes a arbitrer |
| `/profile/{user}` | `profile.show` | `ProfileController@show` | Public | URL anglaise | defer, futur `/profil/{user}` |
| `/loops` | `loops.index` | `LoopController@index` -> `resources/views/loops/index.blade.php` | Auth | URL anglaise | rename later vers `/boucles` pour vraies Boucles, apres liberation de `/boucles` public partenaires |
| `/loops/create` | `loops.create` | `LoopController@create` -> `resources/views/loops/create.blade.php` | Auth | URL anglaise | rename later vers `/boucles/creer` ou `/boucles/nouvelle` apres arbitrage UX |
| `/loops` POST | `loops.store` | `LoopController@store` | Auth | URL anglaise | rename later avec compatibilite interne |
| `/loops/{loop}` | `loops.show` | `LoopController@show` -> `resources/views/loops/show.blade.php` | Auth | URL anglaise | rename later vers `/boucles/{loop}` |
| `/loops/{loop}/members` POST | `loops.members.add` | `LoopController@addMember` | Auth | URL anglaise | rename later; segment membre francais a definir |
| `/loops/{loop}/messages` POST | `loops.messages.store` | `LoopController@storeMessage` | Auth | URL anglaise | rename later; segment messages acceptable en francais/anglais identique visuellement |
| `/loops/{loop}/help-request/analyze` POST | `loops.help-request.analyze` | `LoopController@analyzeHelpIntention` | Auth | URL anglaise | rename later; probablement action interne non publique visible mais route web auth |
| `/loops/{loop}/help-request/publish` POST | `loops.help-request.publish` | `LoopController@publishHelpRequest` | Auth | URL anglaise | rename later; probablement action interne non publique visible mais route web auth |
| `/{community}` | `community.redirect` | Closure redirect vers `/{community}/` | Public tenant slug | Francais variable / legacy `community` technique | keep compatibility; do not introduce Community as product concept |
| `/{community}/` | `community.home` | `CommunityLandingController` -> `resources/views/community/landing.blade.php` | Public organization-scoped via `ResolveCommunity` | Legacy `community` route param technique | defer; architecture migration Organization separee |
| `/{community}/login` | `community.login` | Auth controller | Public guest tenant-scoped | URL anglaise | defer; future francisation tenant auth a planifier avec compatibilite |
| `/{community}/register` | `community.register` | Auth controller | Public guest tenant-scoped | URL anglaise | defer; future francisation tenant auth a planifier avec compatibilite |
| `/{community}/explorer` | `community.explorer` | `ExplorerController@index` | Public tenant-scoped | URL anglaise | defer; futur `/explorer` ou `/echanges` tenant a arbitrer |
| `/{community}/membres` | `community.members.index` | `HomeController@members` | Public tenant-scoped | Francais | keep |
| `/{community}/echanges` | `community.exchanges.index` | `HomeController@exchanges` | Public tenant-scoped | Francais | keep |
| `/{community}/loops...` | `community.loops.*` | `LoopController` | Auth tenant-scoped | URL anglaise | rename later vers routes tenant-scoped francaises apres migration publique |
| `/partners` | aucun | aucun | N/A | Anglais | remove later / keep absent; route interdite en URL publique |
| `/organization` | aucun | aucun | N/A | Anglais | remove later / keep absent; route interdite en URL publique |
| `/partenaires` | aucun | aucun | N/A | Francais cible | add later en T076.1/T076.2 |
| `/partenaires/demande` | aucun | aucun | N/A | Francais cible | add later en T076.1/T076.2 |
| `/organisation` | aucun | aucun | N/A | Francais mais non arbitre | defer; seulement apres arbitrage dedie |
| `/organisation/demande` | aucun | aucun | N/A | Francais mais non arbitre | defer; seulement apres arbitrage dedie |

## UI Wording Matrix

| Fichier | Texte visible | Probleme | Cible francaise |
| --- | --- | --- | --- |
| `resources/views/layouts/navigation.blade.php` | Desktop guest link `Boucles` -> `route('boucles.index')` | Le libelle est francais mais pointe vers `/boucles`, qui liste actuellement des `Community`/partenaires au lieu des vraies Boucles | Conserver le libelle `Boucles` uniquement quand `/boucles` devient la page canonique des vraies Boucles; sortir partenaires dans `Partenaires` -> `/partenaires` |
| `resources/views/layouts/navigation.blade.php` | Mobile guest link `Boucles` -> `route('boucles.index')` | Meme confusion sur mobile drawer | `Partenaires` si lien vers offre partenaire; `Boucles` seulement pour vraies Boucles |
| `resources/views/layouts/navigation.blade.php` | Auth link `Boucles` -> `route('loops.index')` | UI francaise mais URL anglaise `/loops` | Garder wording `Boucles`; route future `/boucles` pour vraies Boucles auth |
| `resources/views/home.blade.php` | `Créez votre propre boucle` | Decrit une demande d'espace prive/tenant-like, pas une Loop collaborative interne | `Devenez partenaire` ou `Créez votre espace partenaire` selon arbitrage produit |
| `resources/views/home.blade.php` | `Créez votre espace privé sur BouclePro...` | Assimile boucle a espace prive / organisation; confusion Organization/Partner/Loop | `Ouvrez un espace partenaire pour votre réseau...` |
| `resources/views/home.blade.php` | CTA `Créer ma boucle` -> `route('boucles.request.create')` | CTA partenaire place sous boucle | `Devenir partenaire` -> `/partenaires/demande` |
| `resources/views/home.blade.php` | Mock card `Votre boucle ici` | Confusion boucle = tenant/espace partenaire | `Votre réseau ici` ou `Votre espace partenaire ici` |
| `resources/views/boucles/index.blade.php` | `Les Boucles` | Page liste des `Community` actives, pas des vraies Boucles | Pour page partenaire: `Partenaires`; pour future vraie page Boucles: contenu Loop reel |
| `resources/views/boucles/index.blade.php` | `Communautés thématiques ou professionnelles qui utilisent la plateforme` | Introduit Community comme concept visible et confond avec partenaire/organisation | `Réseaux et partenaires qui utilisent BouclePro` ou deplacer vers `/partenaires` |
| `resources/views/boucles/index.blade.php` | CTA `Créer ma boucle` | CTA de demande partenaire sous wording boucle | `Devenir partenaire` |
| `resources/views/boucles/index.blade.php` | Empty state `Aucune boucle disponible pour le moment.` | Page ne represente pas les vraies Boucles | `Aucun partenaire disponible pour le moment.` sur `/partenaires`; future `/boucles` a definir |
| `resources/views/community-requests/create.blade.php` | `Créez votre boucle` | Formulaire de demande de tenant/espace partenaire, pas Loop interne | `Devenir partenaire` ou `Demander un espace partenaire` |
| `resources/views/community-requests/create.blade.php` | `Une boucle, c'est votre réseau privé d'échange de services...` | Boucle presentee comme reseau prive/tenant | `Un espace partenaire permet a votre réseau d'utiliser BouclePro...` |
| `resources/views/community-requests/create.blade.php` | `Nom de votre boucle` | Champ partenaire/organisation sous nom boucle | `Nom de votre réseau` ou `Nom de votre organisation partenaire` |
| `resources/views/community-requests/create.blade.php` | Placeholder `BNI Lyon Est, Réseau artisans 06, Startup Nation...` | Exemples reseaux/partenaires, pas Loop interne | Garder exemples mais sous libelle partenaire/reseau |
| `resources/views/community-requests/create.blade.php` | `Nous étudions chaque demande... La création d'une boucle est gratuite.` | Demande partenaire nommee boucle | `La demande partenaire est gratuite.` |
| `resources/views/community-requests/create.blade.php` | Submit `Envoyer ma demande` | Correct mais contexte boucle incorrect | Garder `Envoyer ma demande` sous `/partenaires/demande` |
| `resources/views/loops/index.blade.php` | `Mes boucles`, `Vos espaces de collaboration`, `Créer votre première boucle` | Wording coherent pour vraie Loop interne | keep; route anglaise a renommer plus tard |
| `resources/views/loops/create.blade.php` | `Créer une boucle`, `Une boucle est un espace de collaboration privé` | Wording coherent avec Loop != Tenant si reste espace collaboratif interne | keep; route anglaise a renommer plus tard |
| `resources/views/community/landing.blade.php` | `Rejoindre la communauté`, `Communauté ... Propulsée par BouclePro` | UI publique expose Community legacy comme concept produit | defer vers migration Organization/partner wording dediee, ne pas corriger dans T076 runtime initiale |
| `resources/views/auth/register.blade.php` | `Rejoignez la communauté Entraide` | Community visible dans auth publique | defer vers wording Organization/plateforme dedie |
| `resources/views/partials/footer.blade.php` | `Contribuer sur GitHub`, `Signaler un bug` | Francais correct; GitHub nom propre | keep |

## Decisions Proposees

| Sujet | Decision | Raison |
| --- | --- | --- |
| `/partners` | remove later / keep absent | Route interdite en URL publique; aucune route actuelle trouvee |
| `/organization` | remove later / keep absent | Route interdite en URL publique; aucune route actuelle trouvee |
| `/partenaires` | rename/add later | Cible canonique pour l'offre partenaire publique |
| `/partenaires/demande` | rename/add later | Cible canonique pour le formulaire partenaire |
| `/boucles` actuel | rename later | Doit cesser de lister des Community/partenaires; garder la destination future pour vraies Boucles |
| `/boucles/creer` actuel | redirect later | Devrait rediriger vers `/partenaires/demande` pendant la transition |
| CTA `Créer ma boucle` public | rename later | Cible demandee: `Devenir partenaire` |
| Routes auth `/loops*` | defer | Non publiques guest, mais URL anglaise; a renommer apres liberation de `/boucles` |
| `/organisation` et `/organisation/demande` | defer | Explicitement seulement apres arbitrage dedie |
| Legacy `Community` technique | defer | Ne pas melanger avec migration DB/runtime Community |

## Risques

| Risque | Detail | Mitigation future |
| --- | --- | --- |
| SEO | `/boucles` existe deja et peut etre indexee comme liste de communautes/partenaires | Redirections explicites, sitemap mis a jour, verifier canonical/meta dans T076.1/T076.2 |
| Bookmarks | Utilisateurs peuvent avoir des liens `/boucles` ou `/boucles/creer` | Maintenir redirections temporaires documentees vers `/partenaires` et `/partenaires/demande` |
| Confusion Loop/Partner/Organization | Le public wording actuel utilise boucle pour un espace prive tenant-like; les vraies Loops sont dans `/loops` auth | Separer strictement `Partenaires` de `Boucles`; reserver `/boucles` aux vraies Loops |
| Tenant safety | `HomeController@boucles` liste toutes les `Community::where('is_active', true)` sans `current_organization`; public ne doit pas signifier global | Revoir la portee publique: page partenaire marketing peut lister partenaires publics; routes metier doivent resoudre `current_organization` |
| Migration scope | Corriger les termes Community/Organization en meme temps que les routes peut declencher une migration runtime non voulue | Garder T076.1 focalisee sur routing/redirects/wording public; laisser DB/runtime a une tache dediee |
| Route conflicts | `/boucles` future vraie Loop peut entrer en conflit avec route publique actuelle et routes auth `/loops` | Sequencer en plusieurs taches et tester guest/auth/tenant prefixes |

## Plan T076.1 / T076.2 / T076.3 / T076.4

| Tache | Objectif | Scope propose |
| --- | --- | --- |
| T076.1 | Runtime routing minimal partenaires | Ajouter `/partenaires` et `/partenaires/demande`; deplacer les vues publiques actuellement sous `/boucles`; CTA public `Devenir partenaire`; rediriger `/boucles/creer` vers `/partenaires/demande`; ne pas migrer DB/runtime Community |
| T076.2 | Liberation de `/boucles` | Transformer `/boucles` en route canonique des vraies Boucles ou en page d'attente coherent Loop; retirer la redirection implicite vers partenaires; definir comportement guest/auth |
| T076.3 | Francisation routes anglaises publiques | Auditer et renommer avec redirects `/search`, auth guest (`/login`, `/register`, password), `/services`, `/requests`, `/profile`, et variantes tenant-scoped si valide |
| T076.4 | Validation UI/SEO/browser | Mettre a jour sitemap/canonical si necessaire; ajouter PHPUnit routes/redirections; Playwright desktop/mobile/dark avec screenshots et verification console |

## Acceptance Criteria Pour La Premiere Tache Runtime

- `/partenaires` existe en route publique francaise et n'utilise pas `/boucles` comme alias principal.
- `/partenaires/demande` existe en route publique francaise pour le formulaire de demande partenaire.
- Le CTA public visible est `Devenir partenaire`.
- `/boucles` ne redirige pas vers partenaires par defaut.
- `/boucles/creer` ne reste pas la destination canonique du formulaire partenaire; si conservee, elle redirige explicitement vers `/partenaires/demande`.
- Aucune route publique `/partners` ou `/organization` n'est ajoutee.
- `/organisation` et `/organisation/demande` restent absentes tant que l'arbitrage dedie n'est pas fait.
- Les modifications restent compatibility-first et ne changent pas les migrations ni le modele tenant runtime.
- `current_organization` reste la source runtime canonique; `community_id` reste legacy DB temporaire.
- Aucun nouveau concept produit `Community` n'est introduit dans l'UI publique.

## Test Plan Futur

- PHPUnit route tests: `GET /partenaires` 200, `GET /partenaires/demande` 200, `GET /partners` non canonique/404 ou redirect selon decision, `GET /organization` non canonique/404, `GET /boucles/creer` redirect vers `/partenaires/demande`, `GET /boucles` ne redirect pas vers partenaires.
- PHPUnit route name tests: verifier les nouveaux noms Laravel (`partenaires.index`, `partenaires.request.create`, `partenaires.request.store` ou equivalent choisi) et absence d'impact sur routes auth existantes.
- PHPUnit tenant safety: verifier que les pages metier publiques restent organization-scoped quand applicable et que la page partenaire marketing n'expose pas de donnees inter-tenant non prevues.
- Playwright desktop: navigation header/footer depuis home vers partenaires/demande; verifier texte `Devenir partenaire` et absence de `/partners`/`/organization` dans les liens visibles.
- Playwright mobile: ouvrir mobile drawer, verifier liens publics, CTA et navigation retour.
- Playwright dark mode: screenshots obligatoires home, `/partenaires`, `/partenaires/demande`, et navigation mobile en dark mode.
- Playwright console/network: aucune erreur console, aucun 404 inattendu sur assets/routes, pas de Livewire/Alpine regression.
- Screenshots obligatoires: desktop, mobile, dark mode, et etat formulaire demande partenaire.

## Modified Files

- `TODO/TASK-099-t076-0-public-french-routing-ui-wording-audit.md`

Runtime files modified: none.

# Handoffs

# Tests

- [x] route inventory via `php artisan route:list --path=boucles --json`
- [x] route inventory via `php artisan route:list --path=loops --json`
- [x] confirmed no `/partners`, `/organization`, `/partenaires`, `/organisation` routes currently match route-list criteria
- [x] targeted `rg`/Grep inspection of public navigation, footer, `/boucles`, `/loops`, partner/organization wording
- [ ] feature tests (future runtime task)
- [ ] browser validation (future runtime task)
- [ ] responsive validation (future runtime task)
- [ ] console inspection (future runtime task)
- [ ] tenant validation (future runtime task)

---

# Test Results

Read-only audit commands completed. No PHPUnit or Playwright suite was run because this task intentionally made no runtime changes.

Observed command note:

- `php artisan route:list --path=boucles --columns=method,uri,name,action,middleware` failed because Laravel 13 route-list does not expose a `--columns` option in this project; reran successfully with `--json`.
- `php artisan route:list --path=partners --json`, `--path=organization --json`, `--path=partenaires --json`, and `--path=organisation --json` returned no matching routes, confirming absence of those route families.

---

# Review Notes

Audit complete. TASK status set to DONE and lock released. `ai/scripts/merge-task.sh TASK-099` merged the task branch into `develop`.

OPENAI review: APPROVE WITH NOTES. No blocking issue. Runtime unchanged. Documentation-only patch.

Notes to preserve for follow-up scope:

- In the matrix, "rename later" on `/boucles` means move the partner content currently served by `/boucles`, not abandon `/boucles`.
- `/boucles` remains reserved as the future canonical French public route for real Loops.
- `/boucles` must not redirect to partners by default.
- `/boucles/creer` may be explicitly redirected to `/partenaires/demande` in T076.1.
- `/explorer` is a separate English URL debt to arbitrate later, not in this merge.

Merged into `develop` on 2026-05-18 06:03:35 Europe/Paris. Runtime files remained untouched.
