---
task_id: TASK-097
title: T075.18 — Legacy Routes /boucles & Community-Named Admin Surface Repositioning Audit

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit

priority: MEDIUM

created_at: 2026-05-18 00:18:20 Europe/Paris
updated_at: 2026-05-18 00:42:00 Europe/Paris

labels:
  - audit
  - legacy
  - routes
  - repositioning
  - community
  - organization
  - admin

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T075.18 — Legacy Routes /boucles & Community-Named Admin Surface Repositioning Audit

## Objectif

Auditer et cadrer le repositionnement des surfaces legacy visibles :
- `/boucles`
- `/boucles/creer`
- éventuel `/partners`
- routes Admin nommées Community / Communities
- contrôleurs Admin concernés
- vues concernées
- tests AdminCommunities legacy
- textes UI visibles qui confondent Community, Organization, Partner ou Loop

**Règles architecture à préserver :**
- Organization = Tenant.
- Loop ≠ Tenant.
- Partner ≠ Tenant.
- Partner = co-branding / distribution.
- `current_organization` est la source runtime canonique.
- `organization_id` est canonique côté nouveau code.
- `community_id` reste uniquement une colonne DB legacy de transition.
- `current_community` ne doit plus être une dépendance runtime normale.
- `/boucles` est une surface legacy et ne doit pas être confondue avec les vrais Loops.
- Aucun nouveau concept, fichier, test, helper, service, doc ou prompt ne doit introduire Community comme concept actif.
- Les usages legacy restants doivent être documentés avec justification et handoff.

---

## Questions à Résoudre

1. Que fait exactement `/boucles` aujourd'hui ?
2. `/boucles` représente-t-il encore des Organizations, des Partners ou une ancienne surface Community ?
3. `/boucles/creer` doit-il être supprimé, redirigé, ou repositionné en "Devenir partenaire" ?
4. `/partners` existe-t-il déjà ?
5. Faut-il créer `/partners` maintenant ou seulement documenter la cible ?
6. Quelles routes Admin utilisent encore Community dans leur nom ?
7. Ces routes Admin doivent-elles rester legacy jusqu'à migration DB ?
8. Quels textes visibles doivent être modifiés plus tard dans une tâche UI dédiée ?
9. Quels tests doivent rester legacy car ils couvrent encore la table communities ?
10. Quel découpage futur permet de solder proprement cette dette ?

---

## Périmètre Inclus

- audit routes/web.php pour `/boucles`, `/partners`, admin communities
- audit contrôleurs concernés
- audit vues concernées
- audit tests concernés
- TASK file
- documentation courte si nécessaire

## Hors Scope

- pas de migration DB
- pas de suppression du modèle Community
- pas de remplacement global
- pas de création module Partner
- pas de refonte UI
- pas de changement runtime large
- pas de changement API métier
- pas de changement Policy métier
- pas de changement middleware
- pas de ChatLoop
- pas de nouvelle interface
- pas de nouvelle feature métier
- pas de modification PROD
- pas de giant search/replace
- pas de redirection opportuniste non validée

---

## Plan d'Audit

### Phase 1 — Routes & Contrôleurs
1. Lire `routes/web.php` — identifier toutes les routes /boucles, /partners, admin communities
2. Lire les contrôleurs rattachés
3. Documenter le rôle exact de chaque route

### Phase 2 — Vues
4. Lister les vues Blade associées
5. Identifier les textes UI qui utilisent "Community", "Boucle", "Partner" de façon ambigüe

### Phase 3 — Tests
6. Lister les tests couvrant Admin + Community
7. Identifier les tests purement legacy (dépendent encore de la table communities)

### Phase 4 — Synthèse
8. Répondre aux 10 questions ci-dessus
9. Proposer le découpage futur en sous-tâches (T075.19, T076.x, etc.)
10. Documenter les handoffs nécessaires

---

## Critères d'Acceptation

- TASK file créé, status IN_PROGRESS, lock actif selon script.
- Branche dédiée créée depuis develop clean.
- Le TASK file documente clairement que T075.18 est audit / repositioning.
- Le TASK file interdit explicitement toute implémentation massive.
- Les handoffs futurs sont prévus vers T075.19 / T76 selon résultat d'audit.
- Aucun fichier runtime modifié à la création.
- git status final propre ou modifications volontairement commitées.
- Branche poussée sur origin si création commitée.

---

## Tests Attendus

- `php artisan route:list` (validation inventaire)
- `php artisan route:list --path=admin` (routes admin)
- `rg "boucles" routes/` (présence/absence)
- `rg "partners" routes/` (présence/absence)
- `rg "Community" app/Http/Controllers/Admin/` (contrôleurs legacy)
- `rg "Community" resources/views/` (textes UI)
- `rg "AdminCommunities\|admin.*communities\|CommunityController" tests/` (tests legacy)

---

# Planned Actions

- [X] créer TASK + branche (OPS)
- [X] Phase 1 — audit routes & contrôleurs (CODE)
- [X] Phase 2 — audit vues & textes UI (CODE)
- [X] Phase 3 — audit tests legacy (CODE)
- [X] Phase 4 — synthèse & découpage futur (CODE)
- [X] documentation légère si nécessaire (CODE)
- [X] handoff vers T075.19 / T76 (OPS)
- [X] review OPENAI intégrée (OPS)
- [X] TASK file passé DONE + UNLOCKED (OPS)
- [X] check-task.sh validé (OPS)
- [X] finalize-task.sh exécuté (OPS)
- [X] commit + push finalisation (OPS)
- [X] merge dans develop (OPS)
- [X] status passé MERGED (OPS)

---

# Progress Log

## 2026-05-18 00:18:20 Europe/Paris

### OPS — Création tâche

- create-task.sh exécuté avec succès
- Branche créée : `TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit`
- TASK file complété avec objectif, périmètre, hors scope, plan d'audit, critères d'acceptation
- Aucun fichier runtime modifié
- Commit + push effectués pour figer la création de tâche

## 2026-05-18 00:22:38 Europe/Paris

### OPENCODE — Audit legacy routes /boucles & Admin Community

- Préflight effectué : branche courante `TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit`.
- `git status --short --branch` propre avant modification TASK.
- `check-task.sh` non lancé volontairement : tâche toujours `IN_PROGRESS` + `LOCKED`.
- Audit lecture seule exécuté sur `routes/web.php`, contrôleurs, vues et tests concernés.
- Aucun fichier runtime modifié.
- Seule modification volontaire : ce TASK file.
- Décision provisoire : ne pas implémenter `/partners` dans T075.18 ; documenter la cible et reporter toute implémentation à une tâche validée par Cyril.
- Décision provisoire : conserver temporairement `/boucles`, `/boucles/creer`, `AdminCommunityController`, `admin.communities`, `admin.meta-community` et `admin.users.assign-community` comme surfaces legacy documentées.

## 2026-05-18 00:36:00 Europe/Paris

### OPS — Finalisation T075.18

- OPENAI review reçue : APPROVE WITH NOTES.
- Review intégrée dans Review Notes.
- Status passé de IN_PROGRESS à DONE.
- Lock passé de LOCKED à UNLOCKED.
- check-task.sh validé avec succès (DONE + UNLOCKED + branche correcte).
- finalize-task.sh exécuté avec succès.
- Seule modification : ce TASK file.

## 2026-05-18 00:42:00 Europe/Paris

### OPS — Merge T075.18 dans develop

- merge-task.sh exécuté avec succès.
- Merge commit develop : `c972a01`.
- develop poussé sur origin.
- Status TASK passé de DONE à MERGED.
- Lock : UNLOCKED (inchangé).
- Aucun fichier runtime modifié.
- main / PROD non touchés (`b392a13`).
- Branche distante conservée (`origin/TASK-097-t075-18-...`).
- Aucun fichier runtime modifié.
- Commit + push finalisation effectués.

---

# Audit Results — T075.18

## Surfaces Auditées

- `routes/web.php`
- `app/Http/Controllers/HomeController.php`
- `app/Http/Controllers/CommunityRequestController.php`
- `app/Http/Controllers/Admin/AdminCommunityController.php`
- `app/Http/Controllers/Admin/AdminMetaCommunityController.php`
- `app/Http/Controllers/Admin/AdminController.php`
- `resources/views/boucles/index.blade.php`
- `resources/views/community-requests/create.blade.php`
- `resources/views/layouts/navigation.blade.php`
- `resources/views/layouts/admin.blade.php`
- `resources/views/home.blade.php`
- `resources/views/admin/communities/index.blade.php`
- `resources/views/admin/communities/create.blade.php`
- `resources/views/admin/communities/edit.blade.php`
- `resources/views/admin/meta-community/index.blade.php`
- `tests/Feature/Admin/AdminCommunitiesTest.php`
- `tests/Feature/Admin/AdminUsersTest.php`
- `tests/Feature/T07411RoutesTenantSafetyTest.php`

## Routes Trouvées

- `GET /boucles` → `HomeController@boucles`, route name `boucles.index`.
- `GET /boucles/creer` → `CommunityRequestController@create`, route name `boucles.request.create`.
- `POST /boucles/creer` → `CommunityRequestController@store`, route name `boucles.request.store`.
- Aucune route `/partners` trouvée dans `php artisan route:list` ni dans `routes/web.php`.
- `GET /admin/communities` → `AdminCommunityController@index`, route name `admin.communities`.
- `GET /admin/communities/create` → `AdminCommunityController@create`, route name `admin.communities.create`.
- `POST /admin/communities` → `AdminCommunityController@store`, route name `admin.communities.store`.
- `GET /admin/communities/{community}/edit` → `AdminCommunityController@edit`, route name `admin.communities.edit`.
- `PUT /admin/communities/{community}` → `AdminCommunityController@update`, route name `admin.communities.update`.
- `POST /admin/communities/{community}/toggle-active` → `AdminCommunityController@toggleActive`, route name `admin.communities.toggle-active`.
- `DELETE /admin/communities/{community}` → `AdminCommunityController@destroy`, route name `admin.communities.destroy`.
- `GET /admin/meta_community` → `AdminMetaCommunityController@index`, route name `admin.meta-community`.
- `POST /admin/meta_community` → `AdminMetaCommunityController@update`, route name `admin.meta-community.update`.
- `PATCH /admin/users/{user}/assign-community` → `AdminController@assignCommunity`, route name `admin.users.assign-community`.
- Le wildcard legacy `/{community}` reste actif avec contrainte excluant notamment `boucles`.
- Les routes métier legacy `community.*` restent préfixées par `/{community}` et middleware `community`.

## Contrôleurs Concernés

- `HomeController@boucles` liste `Community::where('is_active', true)->orderBy('name')->get()` et rend `boucles.index`.
- `CommunityRequestController@create` rend `community-requests.create`.
- `CommunityRequestController@store` valide `boucle_name`, `contact_name`, `contact_email`, `description`, `context`, crée un `CommunityRequest`, puis redirige vers `boucles.index` avec message visible "demande de boucle".
- `AdminCommunityController` administre directement `App\Models\Community` et la table `communities` : liste, création, édition, activation, suppression soft delete avec nullification de `community_id` et `organization_id` sur modèles liés.
- `AdminMetaCommunityController` ne gère qu'un réglage global `global_color_mode`, mais la surface visible et route restent nommées `meta-community` / `Meta-Communauté`.
- `AdminController@assignCommunity` maintient une affectation utilisateur legacy via `community_id`, synchronisée vers `organization_id` avec la même valeur.

## Vues Concernées

- `resources/views/boucles/index.blade.php` affiche "Les Boucles", sous-titre "Communautés thématiques ou professionnelles", CTA "Créer ma boucle", liste les `communities` actives et redirige chaque carte vers `community.home`.
- `resources/views/community-requests/create.blade.php` affiche "Créez votre boucle" et un formulaire de demande de boucle avec champs `boucle_name`, `description`, `context`, `contact_name`, `contact_email`.
- `resources/views/home.blade.php` contient un CTA public "Créer ma boucle" vers `boucles.request.create`.
- `resources/views/layouts/navigation.blade.php` affiche "Boucles" vers `boucles.index` pour les visiteurs et vers `loops.index` pour les utilisateurs authentifiés, ce qui entretient l'ambiguïté visible entre `/boucles` legacy et vrais Loops.
- `resources/views/layouts/admin.blade.php` affiche une entrée admin "Communautés" vers `admin.communities`, une entrée "Boucles" vers `admin.loops`, et une entrée "Meta-Communauté" vers `admin.meta-community`.
- `resources/views/admin/communities/*.blade.php` porte encore les libellés visibles "Communauté", "Créer une communauté", "Éditer la communauté", "Points offerts à chaque nouvel inscrit dans cette communauté".
- `resources/views/admin/meta-community/index.blade.php` porte les libellés visibles "Meta-Communauté", "hors communautés spécifiques", "Les communautés conservent leur propre personnalisation".
- `resources/views/admin/users.blade.php` et `resources/views/admin/users/edit.blade.php` utilisent encore `community_id`, affichent des colonnes/libellés communauté et proposent l'affectation utilisateur à une communauté.

## Tests Concernés

- `tests/Feature/Admin/AdminCommunitiesTest.php` couvre l'accès admin, création, édition, activation, visibilité, suppression soft delete et assertions DB sur la table `communities`.
- `tests/Feature/Admin/AdminUsersTest.php` couvre `admin.users.assign-community`, `community_id`, la synchronisation indirecte avec des `Organization::factory()` et le libellé visible "Communauté".
- `tests/Feature/T07411RoutesTenantSafetyTest.php` couvre explicitement `/boucles` comme route publique legacy et l'existence de `route('boucles.index')`.
- Aucun test dédié trouvé pour `CommunityRequestController`, `community-requests`, `boucle_name` ou `boucles.request.*`.
- Les nombreux usages `community_id` dans les tests métier restent hors scope T075.18 ; ils relèvent de la migration DB/runtime progressive.

## Décisions Provisoires

- `/boucles` reste une route legacy publique pour l'instant. Elle liste les records `communities` actifs, qui sont aujourd'hui le support DB legacy des Organizations/espaces publics, pas les vrais Loops métier.
- `/boucles` ne doit pas être renommé en Loop ni traité comme tenant. Il ne représente pas les vrais `Loop` collaboratifs.
- `/boucles/creer` doit être repositionné plus tard vers une intention "Devenir partenaire" ou "Créer un espace partenaire" si Cyril valide le produit cible, mais ne doit pas être supprimé ni redirigé dans T075.18.
- `/partners` n'existe pas aujourd'hui. Ne pas créer `/partners` dans T075.18.
- La cible `/partners` doit seulement être documentée maintenant. Création route/module à reporter à une tâche suivante validée par Cyril.
- `AdminCommunityController` et `admin.communities.*` doivent rester legacy temporairement tant que la table `communities`, le modèle `Community`, les factories et les tests DB associés restent actifs.
- `admin.meta-community` doit rester legacy temporairement ; son contenu semble être un réglage global de plateforme et devrait être renommé plus tard vers une surface type "Platform settings" / "Paramètres plateforme" dans une tâche UI/runtime cadrée.
- `admin.users.assign-community` doit rester legacy temporairement car il manipule encore `community_id` et synchronise `organization_id`.

## Risques Si Renommage Trop Tôt

- Rupture des route names utilisés dans Blade, tests et contrôleurs (`boucles.index`, `boucles.request.*`, `admin.communities.*`, `admin.meta-community`, `admin.users.assign-community`).
- Confusion accrue si `/boucles` est renommé vers Loop alors qu'il liste des `Community` records et non des vrais `Loop`.
- Risque de casser les tests `AdminCommunitiesTest`, `AdminUsersTest` et `T07411RoutesTenantSafetyTest` avant migration DB complète.
- Risque de casser la compatibilité `/{community}` et le middleware legacy `community` si les réservations de slugs ou route names sont modifiées sans plan global.
- Risque de casser les écrans admin qui nullifient ou synchronisent encore `community_id` / `organization_id`.
- Risque de créer un concept Partner prématuré sans modèle, politique produit, isolation, routing et wording validés.

## Legacy Temporaire à Conserver

- Table `communities` et modèle `Community` tant que migration DB non soldée.
- Colonne `community_id` comme colonne DB legacy de transition.
- Route `/boucles` et route name `boucles.index` comme surface publique legacy.
- Routes `/boucles/creer` et `boucles.request.*` comme formulaire legacy de demande.
- Routes `admin.communities.*` et contrôleur `AdminCommunityController` comme admin legacy de la table `communities`.
- Route `admin.meta-community` comme nom legacy jusqu'à clarification UI/plateforme.
- Route `admin.users.assign-community` tant que l'affectation admin utilise encore `community_id`.
- Tests legacy qui protègent encore la table `communities` et les route names existants.

## Réponses aux 10 Questions

1. `/boucles` affiche une page publique listant toutes les `Community` actives par nom et liant chaque carte vers la landing legacy `community.home`.
2. `/boucles` représente une ancienne surface Community basée sur la table `communities`. Dans le vocabulaire cible, ces records se rapprochent davantage d'Organizations/espaces partenaires que de vrais Loops, mais le code actuel ne modélise pas encore Partner.
3. `/boucles/creer` ne doit pas être supprimé maintenant. Décision provisoire : le repositionner plus tard vers une intention "Devenir partenaire" / "Créer un espace partenaire" si validé, sans implémentation dans T075.18.
4. `/partners` n'existe pas dans `php artisan route:list` ni dans `routes/web.php`.
5. Ne pas créer `/partners` maintenant. Documenter uniquement la cible et reporter l'implémentation à T075.19/T076 ou une tâche dédiée validée par Cyril.
6. Routes admin encore nommées Community : `admin.communities.*`, `admin.meta-community`, `admin.meta-community.update`, `admin.users.assign-community`.
7. Oui, ces routes doivent rester legacy jusqu'à migration DB/runtime et plan de renommage contrôlé, car elles manipulent encore `Community`, `communities`, `community_id` et route names testés.
8. Textes visibles à reprendre plus tard : "Boucles" public visiteur, "Créer ma boucle", "Créez votre boucle", "Communautés thématiques ou professionnelles", "Communautés" admin, "Créer une communauté", "Éditer la communauté", "Meta-Communauté", "hors communautés spécifiques", "communauté globale", colonne/libellé "Communauté" côté admin users.
9. Tests à garder legacy : `AdminCommunitiesTest` pour table `communities`, `AdminUsersTest` pour `admin.users.assign-community` / `community_id`, `T07411RoutesTenantSafetyTest` pour `/boucles` public et route names legacy.
10. Découpage futur recommandé : T075.19 pour décision produit/routing `/boucles` vs `/partners`; T076 UI pour wording visible; tâche DB dédiée pour migration `communities`/`community_id` vers Organization; tâche Admin dédiée pour renommer `AdminCommunityController` et routes admin après compatibilité; tâche tests dédiée pour basculer assertions legacy vers vocabulaire cible.

## Découpage Futur Recommandé

- T075.19 : cadrer et valider produit/routing public `/boucles`, `/boucles/creer`, cible `/partners`, sans toucher DB.
- T076 UI : remplacer les textes visibles ambigus par Organization / Partner / Loop selon surface, avec validation desktop/mobile/dark mode.
- T076 Admin : préparer une surface admin Organization/Partner explicite, tout en gardant alias/compat route names si nécessaire.
- T076 DB/runtime : migrer progressivement `communities` et `community_id` vers Organization native, puis seulement ensuite renommer contrôleurs/routes/tests.
- T076 Tests : déplacer les tests legacy vers assertions Organization/Partner une fois le runtime prêt.

## Hors Scope Confirmé

- Aucune création de `/partners`.
- Aucune suppression ou redirection de `/boucles`.
- Aucun renommage de `AdminCommunityController`.
- Aucune modification de route, contrôleur, vue, test, middleware, policy, API, migration ou Blade runtime.
- Aucun module Partner créé.
- Aucun changement de status `DONE`.
- Aucun unlock.
- Aucun `finalize-task.sh` ni `merge-task.sh`.

---

# Handoffs

## Prochains handoffs prévus

Une fois l'audit terminé, handover vers :
- **T075.19** — Implémentation des repositionnements validés
- **T076.x** — Nettoyage UI / textes visibles / docs
- Agent **OPENAI / Codex GPT-5.5** pour review ciblée si nécessaire
- **Claude Code** uniquement si complexité architecture forte (peu probable ici)

---

# Tests

## Tests d'inventaire (Phase 0 — déjà exécutables)

- [X] `php artisan route:list | rg "boucles|partners|communities|community|admin"`
- [X] `php artisan route:list --path=admin`
- [X] `rg "boucles|partners|communities|community|Community|Partner" routes/web.php`
- [X] `rg "Community|Communities|community|communities|Partner|partners|boucles" app/Http/Controllers app/Http/Controllers/Admin resources/views tests`
- [X] `rg "route\('boucles|route\('partners|route\('admin\.communit|admin\.communit" resources/views tests app`

---

# Test Results

## 2026-05-18 00:22:38 Europe/Paris

- `php artisan route:list | rg "boucles|partners|communities|community|admin"` exécuté avec succès.
- `php artisan route:list --path=admin` exécuté avec succès, 65 routes admin listées.
- `rg "boucles|partners|communities|community|Community|Partner" routes/web.php` exécuté avec succès, 31 correspondances.
- `rg "Community|Communities|community|communities|Partner|partners|boucles" app/Http/Controllers app/Http/Controllers/Admin resources/views tests` exécuté avec succès ; sortie volumineuse attendue, lecture ciblée ensuite.
- `rg "route\('boucles|route\('partners|route\('admin\.communit|admin\.communit" resources/views tests app` exécuté avec succès ; les correspondances runtime pertinentes sont dans `resources/views`, `app/Http/Controllers` et `tests`. Les correspondances sous `storage/framework/views` sont des vues compilées ignorées pour décision.
- Aucun test PHPUnit ni Playwright lancé : T075.18 est audit/documentation TASK uniquement, sans modification runtime.
- Aucun `check-task.sh` lancé : tâche non `DONE` et toujours `LOCKED`.

---

# Review Notes

## OPENAI Review — 2026-05-18

### Verdict
APPROVE WITH NOTES

### Blocking Issues
Aucun.

### Confirmation Runtime
Le commit 8759f1a modifie uniquement le TASK file. Aucune route, aucun contrôleur, aucune vue, aucun test, aucune migration, aucun middleware, aucune API, aucune policy modifiés. Le scope audit/documentation est respecté.

### Points Validés
- `/boucles` est correctement qualifié comme surface legacy Community, non assimilée aux vrais Loop.
- `/boucles/creer` est laissé en place et repositionné seulement comme sujet futur potentiel "Devenir partenaire" / "Créer un espace partenaire".
- `/partners` n'est pas créé dans T075.18 et reste explicitement reporté.
- `admin.communities.*`, `admin.meta-community`, `admin.users.assign-community` sont conservés comme legacy temporaire justifié.
- `AdminCommunitiesTest`, `AdminUsersTest`, `T07411RoutesTenantSafetyTest` sont correctement maintenus comme safety coverage legacy.
- Le rapport évite le giant search/replace, la migration DB, le changement runtime et la création prématurée d'un modèle/surface Partner.

### Non-Blocking Notes
- Le découpage futur est sain : décision produit/routing `/boucles` vs `/partners`, UI wording, admin rename, migration DB/runtime, puis bascule tests.
- Risque résiduel principal : le vocabulaire visible "Boucles / Communautés / Meta-Communauté" reste confus tant que T076/UI/Admin rename n'est pas traité. C'est correctement documenté et hors scope T075.18.

### Recommandation Découpage Futur
- **T075.19** : arbitrage produit/routing `/boucles`, `/boucles/creer`, `/partners`.
- **T076 UI** : correction des textes visibles ambigus.
- **T076 Admin** : renommage admin contrôlé avec compat route names si nécessaire.
- **Tâche DB/runtime dédiée** : migration `communities` / `community_id`.
- **Tâche tests dédiée** : bascule des assertions legacy après runtime prêt.

## Contraintes OPS

- Aucune modification runtime autorisée pendant T075.18.
- T075.18 est purement audit + documentation.
- Le CODE agent exécutera les phases 1-4 en lecture seule.
- Ne pas créer de nouvelles routes, contrôleurs, vues, policies, middleware, migrations.
- Ne pas supprimer de code existant.
- Ne pas lancer de refactoring.
