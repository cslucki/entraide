---
task_id: TASK-085
title: T075.6 — Blog Organization Scoping + Containment

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-085-t075-6-blog-organization-scoping-containment

priority: HIGH

created_at: 2026-05-17 01:12:40 Europe/Paris
updated_at: 2026-05-17 03:05:00 Europe/Paris

labels:
  - organization-scoping
  - blog
  - tenant-safety
  - containment

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

Rendre le Blog strictement Organization-scopé et empêcher toute création ou exposition de BlogPost tenantless.

Aucun BlogPost ne doit être créé, listé, affiché ou modifié sans Organization résolue.
Le Blog ne doit plus fonctionner comme surface globale tenantless.

---

# Constat Initial

- Blog routes root domain : `/blog` et `/blog/*`
- `BlogController` ne résout pas explicitement l'Organization.
- `BlogPost::create` ne set pas correctement `community_id` dans l'état audité.
- Résultat historique : BlogPost peut être créé sans tenant.
- Blog index/show peuvent exposer des posts cross-Organization.
- Le Blog doit suivre la règle : **public ne veut pas dire global. Une route publique peut être Organization-scopée.**

---

# Architecture Rules

- Organization = Tenant.
- Loop ≠ Tenant.
- Community / `community_id` / `current_community` restent legacy technique temporaire.
- Ne pas introduire de nouveau vocabulaire ou nommage Community dans les nouveaux concepts, services, vues, textes UI, docs ou prompts.
- Utiliser Organization / Loop / Member / Interaction côté produit et nouveau code.
- N'utiliser `community_id` / `current_community` / routes legacy que lorsque nécessaire pour compatibilité technique temporaire.
- Documenter toute utilisation legacy.
- Ne pas lancer de migration Community → Organization.
- Ne pas modifier `BelongsToTenantScope` dans cette tâche.
- Ne pas toucher aux API, Policies globales, Partner architecture complète, `/org/{organization}`, migrations DB, admin global, ou refactor route massif.

---

# Scope Strict

## 1. Inspecter

- `routes/web.php` pour les routes blog
- `app/Http/Controllers/BlogController.php`
- `app/Http/Controllers/BlogCommentController.php`
- `app/Models/BlogPost.php`
- `app/Models/BlogComment.php` si nécessaire
- Policies BlogPost si existantes
- Tests existants liés au blog

## 2. Implémentation attendue par CODE, pas par OPS

- Blog **index** doit charger uniquement les posts de l'Organization résolue.
- Blog **show** doit bloquer un post hors Organization résolue.
- Blog **create/store** doit exiger une Organization résolue.
- `BlogPost` créé doit recevoir l'identifiant legacy compatible nécessaire, probablement `community_id` depuis `currentOrganization()->id`.
- Blog **edit/update/delete** doivent rester auteur/admin mais aussi Organization-safe.
- Blog **comments** doivent rester attachés à un BlogPost Organization-safe.
- Empêcher création de post tenantless.
- Préserver les routes actuelles autant que possible.
- Pas de refonte UI Blog.
- Pas de nouveau modèle Partner.
- Pas de migration.

## 3. Tests attendus

- Test création BlogPost avec Organization résolue : post rattaché à l'Organization.
- Test création BlogPost sans Organization résolue : bloqué / redirect / 404 selon comportement existant cohérent.
- Test index Blog : ne liste pas les posts d'une autre Organization.
- Test show Blog : post d'une autre Organization retourne 404.
- Test edit/update/delete cross-Organization : bloqué.
- Test commentaire sur post cross-Organization : bloqué ou impossible.
- Tests existants Blog doivent rester verts.
- Full suite locale à lancer après implémentation.

---

# Acceptance Criteria

- Aucun BlogPost tenantless ne peut être créé via l'UI.
- `/blog` n'expose que les posts de l'Organization résolue.
- `/blog/{slug}` ne permet pas de lire un post hors Organization résolue.
- Les actions protégées Blog restent auth/verified selon routes existantes.
- Organization = Tenant préservé.
- Loop ≠ Tenant préservé.
- Community/`community_id` utilisé uniquement comme compatibilité legacy documentée.
- Aucun scope creep hors Blog.
- Pint OK.
- Tests ciblés Blog OK.
- Full suite locale OK.
- TASK finalisable avec `check-task.sh` puis `finalize-task.sh`.

---

# Hors Scope

- Pas de migration Community → Organization.
- Pas de nouveau modèle Partner.
- Pas de route `/org/{organization}`.
- Pas de refactor global routes.
- Pas de modification `BelongsToTenantScope`.
- Pas de modification API.
- Pas de refonte UI Blog.
- Pas de Playwright obligatoire sauf si modification UI visible majeure.
- Pas de PROD / main.

---

# Planned Actions

- [ ] inspecter routes/blog dans web.php
- [ ] inspecter BlogController
- [ ] inspecter BlogCommentController
- [ ] inspecter BlogPost model
- [ ] inspecter BlogComment model
- [ ] inspecter policies BlogPost
- [ ] inspecter tests existants blog
- [ ] implémenter Organization scoping BlogController index
- [ ] implémenter Organization scoping BlogController show
- [ ] implémenter Organization scoping BlogController create/store
- [ ] implémenter Organization scoping BlogController edit/update/delete
- [ ] implémenter Organization scoping BlogCommentController
- [ ] empêcher création BlogPost tenantless
- [ ] écrire tests feature Blog Organization scoping
- [ ] lancer Pint
- [ ] lancer tests ciblés Blog
- [ ] lancer full suite locale
- [ ] valider acceptance criteria
- [ ] finaliser TASK

---

# Progress Log

## 2026-05-17 01:12:40 Europe/Paris

Task created by OPS.

Owner: OPENCODE
Branch: TASK-085-t075-6-blog-organization-scoping-containment
Status: IN_PROGRESS
Lock: LOCKED by OPENCODE

READY FOR CODE HANDOFF.

## 2026-05-17 02:30:00 Europe/Paris — Implémentation containment Blog

### Fichiers inspectés
- `routes/web.php` — routes blog identifiées (public + auth + comments)
- `app/Http/Controllers/BlogController.php`
- `app/Http/Controllers/BlogCommentController.php`
- `app/Models/BlogPost.php` + trait `HasOrganizationId`
- `app/Models/BlogComment.php`
- `app/Policies/BlogPostPolicy.php`
- `app/Http/Middleware/ResolveUrlOrganization.php` — confirme `/blog/*` est une feature route (default org résolue)
- `app/Support/Tenancy/CurrentOrganization.php` + `app/Support/helpers.php`
- `database/migrations/*blog*` + `2026_05_12_101622_add_organization_id_to_tables.php`
- tests existants liés blog : seul `tests/Feature/OrganizationRelationshipsTest.php` référence `BlogPost` (relation test inchangé)

### Fichiers modifiés
- `app/Http/Controllers/BlogController.php` — guards Organization ajoutés sur `index`, `byCategory`, `byTag`, `show`, `create`, `store`, `edit`, `update`, `publish`, `destroy`, `myPosts`. `store()` force `community_id = currentOrganization()->id` côté serveur.
- `app/Http/Controllers/BlogCommentController.php` — guards Organization sur `store` (via `$post`) et `destroy` (via `$comment->post`).
- `tests/Feature/T0756BlogOrganizationScopingTest.php` — 8 tests créés couvrant index, show, store hidden field tampering, store sans org, update/destroy cross-org, comment cross-org.

### Tests
- T0756 ciblé : 8/8 verts (14 assertions, 0.59s)
- Full suite : 598/598 verts (1309 assertions, 17.25s) — +8 par rapport au baseline T075.5 (590).
- Pint : appliqué `--dirty`, fixers normalisés sur les 2 controllers.

### Décisions clés
- `community_id` reste colonne legacy DB — utilisée explicitement dans les guards et la création (synchronisée vers `organization_id` par le trait `HasOrganizationId`).
- `myPosts()` également scopé Organization pour éviter qu'un user qui a changé d'Organization voie ses anciens posts.
- `relatedPosts` dans `show()` scopé Organization.
- `Category` et `Tag` `withCount(['blogPosts' => ...])` étendus pour scoper par Organization.
- `BlogPostPolicy` non modifié — la sémantique auteur/admin est conservée, le guard Organization est ajouté en amont du `authorize()`.

### Hors scope respecté
- Pas touché à `BelongsToTenantScope`.
- Pas de migration.
- Pas de Partner model.
- Pas de route `/org/{organization}`.
- Pas de refactor global.
- Pas de refonte UI.

### Risques résiduels
- Le test `test_blog_show_returns_post_in_resolved_organization` valide qu'un post Org A est accessible quand Org A est résolue — couvre la régression potentielle "guard trop strict".
- Aucun test Playwright lancé (pas de modification UI visible — hors scope explicite).
- Si `default_organization_id` n'est pas défini en PROD et qu'aucune Organization n'est `is_active`, le middleware peut ne pas résoudre et `/blog` retournera 404. Comportement attendu : `/blog` est désormais Organization-scoped, donc 404 est cohérent.

Status: DONE
Lock: UNLOCKED
Ready for OPS pipeline (check-task → finalize-task).

## 2026-05-17 03:00:00 Europe/Paris — Correction OPENAI review (parent_id cross-post)

### Point bloquant remonté par OPENAI
`BlogCommentController::store` validait `parent_id` avec `exists:blog_comments,id` mais ne vérifiait pas que le commentaire parent appartient au même `BlogPost` que `$post`. Un user pouvait poster un commentaire sur post Org A avec `parent_id` pointant sur un commentaire de post Org B — créant une relation réponse cross-post/cross-Organization.

### Correction appliquée
- `app/Http/Controllers/BlogCommentController.php` — après validation, si `parent_id` présent : chargement du parent comment + `abort_unless($parent && $parent->blog_post_id === $post->id, 404)`. Comportement retenu : **404** (cohérent avec les autres guards Organization du controller).
- `tests/Feature/T0756BlogOrganizationScopingTest.php` — ajout du test `test_blog_comment_store_blocks_parent_id_from_another_post` : crée post A (Org A) + post B (Org B) + commentaire parent sur post B ; user A tente de répondre sur post A en pointant parent_id sur le commentaire de post B → 404 et aucun blog_comment créé.

### Tests
- T0756 ciblé : 9/9 verts (17 assertions, 0.58s) — +1 nouveau test.
- Full suite : 599/599 verts (1312 assertions, 16.54s) — +1 par rapport à 598.
- Pint : passed (0 fixers).

### Hors scope respecté
- Pas de migration, pas de modification `BelongsToTenantScope`, pas de refactor Blog global, pas d'API, pas de route `/org/{organization}`, pas de Partner, pas d'UI, pas de commit.

---

# Handoffs

## Handoff to CODE

- Status: IN_PROGRESS, locked to OPENCODE
- Aucune implémentation encore faite — uniquement setup OPS
- Next agent : écrire l'implémentation Blog Organization scoping
- Inspecter les fichiers listés dans Scope Strict §1 avant de coder
- Suivre l'ordre d'implémentation dans Scope Strict §2
- Écrire les tests listés dans Scope Strict §3
- Valider tous les acceptance criteria avant finalisation

---

# Tests

- [x] Test création BlogPost avec Organization résolue
- [x] Test création BlogPost sans Organization résolue (bloqué — 404)
- [x] Test index Blog : pas de posts cross-Organization
- [x] Test show Blog : post cross-Organization retourne 404
- [x] Test edit/update/delete cross-Organization : bloqué (update/destroy — 404)
- [x] Test commentaire sur post cross-Organization : bloqué (404)
- [x] Tests existants Blog restent verts
- [x] Pint OK

---

# Test Results

## T0756BlogOrganizationScopingTest — 2026-05-17 (post-correction OPENAI)

```
✓ blog index lists only resolved organization posts                   0.32s
✓ blog show blocks cross organization post                            0.02s
✓ blog show returns post in resolved organization                     0.03s
✓ blog store uses resolved organization not tampered community id     0.02s
✓ blog store fails safe when no organization resolved                 0.02s
✓ blog update cross organization is blocked                           0.02s
✓ blog destroy cross organization is blocked                          0.02s
✓ blog comment store on cross organization post is blocked            0.02s
✓ blog comment store blocks parent id from another post               0.02s

Tests: 9 passed (17 assertions) — Duration: 0.58s
```

## Full suite — 2026-05-17 (post-correction OPENAI)

```
Tests: 599 passed (1312 assertions) — Duration: 16.54s
0 failures, 0 errors, 0 regressions
```

Avant T075.6 : 590 tests. Après implémentation initiale : 598 (+8). Après correction OPENAI parent_id : 599 (+1).

---

# Review Notes

## Décisions architecturales

- **BlogController — guards Organization sur toutes les actions** :
  - `index()`, `byCategory()`, `byTag()`, `myPosts()` : `currentOrganization()` requis sinon `abort(404)` ; toutes les requêtes Eloquent scopées par `where('community_id', $organization->id)`.
  - `show()`, `edit()`, `update()`, `publish()`, `destroy()` : guard `currentOrganization()` + `abort(404)` si null OU si `$post->community_id !== $organization->id`.
  - `create()`, `store()` : guard Organization en tête. `store()` force `community_id = $organization->id` côté serveur — tout `community_id` envoyé par le client est ignoré (le champ n'est pas dans la liste `validate()` donc non présent dans `$data`, et il est écrasé explicitement).

- **BlogCommentController — guards via le post parent** :
  - `store(BlogPost $post)` : guard Organization + `$post->community_id !== $organization->id` → 404. Empêche commenter un post cross-Organization.
  - `store()` — `parent_id` cross-post bloqué : après validation, si `parent_id` est présent, on charge le `BlogComment` parent et `abort_unless($parent && $parent->blog_post_id === $post->id, 404)`. Empêche un user de créer une réponse cross-post / cross-Organization en falsifiant `parent_id`.
  - `destroy(BlogComment $comment)` : récupère `$comment->post`, applique le même guard via le post parent. La logique 403 author/admin existante est préservée APRÈS le guard tenant.

- **myPosts** : scopé Organization en plus du `user_id` — un user qui appartient maintenant à Org A ne doit pas voir ses anciens posts d'Org B via cette page.

- **Posts liés (`relatedPosts` dans `show`)** : scopés par `community_id = currentOrganization()->id` pour ne pas exposer de posts cross-Organization dans les suggestions.

- **`Category::withCount(['blogPosts' => ...])` et `Tag::withCount(['blogPosts' => ...])`** : closures Eloquent étendues pour scoper le count par Organization. Sinon le compteur affichait le total cross-Organization.

- **Index — `/blog` sans Organization résolue** : `abort(404)`. Le middleware `ResolveUrlOrganization` résout normalement l'Organization par défaut sur le root domain pour les routes feature comme `/blog`. Si aucune Organization n'est résolue (cas test sans `defaultOrganizationId` ni Organization active), le controller refuse.

- **Hidden field tampering** : non testé sur `blog.update` car la route accepte un POST avec champs métiers seulement — aucun hidden `community_id` n'a jamais été émis dans les vues blog (vérifié dans le code controller : `community_id` n'est ni dans `validate()` ni assigné depuis `$request` dans `update()`). Le guard `$post->community_id !== $organization->id` couvre toute tentative cross-org via URL.

## Usage legacy `community_id` documenté

- `BlogPost::create([..., 'community_id' => $organization->id, ...])` : `community_id` est utilisé comme identifiant tenant legacy. Le trait `HasOrganizationId` sur `BlogPost` synchronise automatiquement `organization_id` depuis `community_id` au save. Aucune introduction de nouveau code Community — utilisation strictement compatible avec le schéma DB actuel.

- Tous les guards utilisent `$model->community_id !== $organization->id` car la colonne canonique en DB reste `community_id` jusqu'à ce que la migration Community → Organization soit complétée (hors T075.6).

## OPENAI Review

- **Verdict initial** : REQUEST CHANGES
- **Point bloquant unique** : `BlogCommentController::store` validait `parent_id` avec `exists:blog_comments,id` sans vérifier que le commentaire parent appartient bien à `$post`. Risque cross-post / cross-Organization.
- **Correction appliquée** : check explicite après validation — `abort_unless($parent && $parent->blog_post_id === $post->id, 404)`. Comportement retenu : **404** (homogène avec les autres guards du controller).
- **Test ajouté** : `test_blog_comment_store_blocks_parent_id_from_another_post` — couvre exactement le scénario décrit par OPENAI.
- **Statut** : OPENAI REQUEST CHANGES corrigé — parent_id cross-post/cross-Organization bloqué.

## Hors scope — respecté

- Pas de migration DB.
- Pas de modification `BelongsToTenantScope`.
- Pas de nouveau modèle Partner.
- Pas de route `/org/{organization}`.
- Pas de refactor global routes ou Blog.
- Pas de refonte UI Blog.
- Pas de modification API.
- Pas de modification PROD / main.

---

# Risks

- Legacy `community_id` utilisé comme compatibilité — doit être documenté dans le code.
- Si Organization résolution échoue sur root domain, fallback nécessaire à définir.
- Routes Blog actuelles root domain (`/blog`) : vérifier comment Organization est résolue dans ce contexte.
- Ne pas casser les liens SEO existants si slugs restent identiques.