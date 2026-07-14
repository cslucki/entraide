---
task_id: TASK-1010
title: Clean blog AI article generation output

status: DONE

owner: opencode

contributors: []

branch: TASK-1010-clean-blog-ai-article-generation-output

priority: HIGH

created_at: 2026-07-13 17:33:29 Europe/Paris
updated_at: 2026-07-13 19:20:00 Europe/Paris

labels: [blog, ai, editor]

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: READY
  url: https://github.com/cslucki/entraide/pull/73
---

# Objective

Corriger les sorties parasites de la génération IA des articles Blog principaux.

Quand le modèle renvoie une préface explicative et/ou des délimiteurs Markdown de type ```html autour du contenu HTML, le contenu inséré dans l'éditeur et sauvegardé dans le brouillon doit être uniquement le HTML utile de l'article.

---

# Scope

Inclus :
- génération IA d'article Blog (`blog_generate`) ;
- nettoyage serveur avant réponse JSON et sauvegarde du brouillon ;
- consigne de langue explicite selon la locale courante de l'application (`fr`/`en`) ;
- séparation UX "Questionner un passage" vs "Questionner l'article" ;
- tests ciblés T1010 ;
- bump officiel `VERSION=1.003`.

Exclus :
- Dossiers ;
- `bouclepro.com`, DNS, Laravel Cloud, `APP_URL` ;
- prompts Explorer/Questionnements ;
- migrations, seeders, données, Organizations, Loops ;
- réécriture frontend de l'éditeur.

---

# Planned Actions

- [x] vérifier `develop` propre et synchronisé, `VERSION=1.002` ;
- [x] créer TASK-1010 via `create-task.sh` ;
- [x] bump officiel `VERSION=1.003` via `bump-version.sh` ;
- [x] auditer le flux `BlogController` → `BlogAiService` → éditeur TipTap ;
- [x] nettoyer uniquement `blog_generate` côté serveur ;
- [x] ajouter tests T1010 sur préface, fences Markdown et texte final parasite ;
- [x] valider via `safe-test.sh`, Pint, `view:cache`, `git diff --check`.

# Pending Migrations

**ATTENTION** : 2 migrations liées à TASK-1010 sont en attente (pending) dans la branche :

1. `database/migrations/2026_07_11_000000_add_ai_method_origin_to_blog_post_annotations.php` — Pending
2. `database/migrations/2026_07_11_000001_seed_blog_method_selection_prompts.php` — Pending

Ces migrations doivent être jouées (`php artisan migrate`) lors de la merge dans `develop` ou lors du déploiement.

---

# Progress Log

## 2026-07-13 17:33 — Création

Task créée sur branche `TASK-1010-clean-blog-ai-article-generation-output`.

## 2026-07-13 17:36 — Version officielle

Commande exécutée : `ai/scripts/bump-version.sh 1.003`.

Résultat : `VERSION` passe de `1.002` à `1.003`.

## 2026-07-13 17:45 — Audit du flux Blog IA

Fichiers inspectés :
- `app/Services/BlogAiService.php` ;
- `app/Http/Controllers/BlogController.php` ;
- `resources/js/app.js` ;
- `tests/Feature/T3BlogEditorAiAdminTest.php`.

Constat :
- `blog_generate` renvoyait le texte IA brut ;
- le controller sauvegardait ce contenu dans le brouillon en création d'article ;
- l'éditeur insérait directement `data.content` ;
- la réponse brute IA est déjà conservée dans `ai_interactions.response`.

Décision : nettoyer côté serveur dans `BlogAiService::buildResult()` uniquement pour `blog_generate`, afin de corriger à la fois la réponse JSON et le brouillon sauvegardé, sans toucher `blog_correct` ni la sélection de méthode.

## 2026-07-13 17:52 — Implémentation

Modifié : `app/Services/BlogAiService.php`.

Ajout : `cleanGeneratedArticleHtml()`.

Comportement :
- extrait le contenu du premier bloc fenced Markdown ```html lorsqu'il existe ;
- supprime les fences Markdown restantes ;
- coupe toute préface avant la première balise HTML d'article reconnue ;
- coupe le texte parasite après la dernière balise fermante reconnue ;
- ne modifie pas la réponse brute stockée dans `ai_interactions.response`.

Balises de début reconnues : `article`, `section`, `div`, `h1`, `h2`, `h3`, `h4`, `p`, `ul`, `ol`, `blockquote`.

## 2026-07-13 17:56 — Tests T1010

Ajouté dans `tests/Feature/T3BlogEditorAiAdminTest.php` :
- `test_t1010_ai_generate_removes_explanatory_preface_and_markdown_fences` ;
- `test_t1010_ai_generate_removes_preface_and_trailing_text_without_fences`.

Les tests vérifient :
- réponse JSON nettoyée ;
- brouillon Blog sauvegardé avec le HTML nettoyé ;
- absence de préface, fences et texte final parasite dans le contenu renvoyé ;
- conservation de la réponse brute IA dans `ai_interactions.response` pour audit.

## 2026-07-13 18:10 — Localisation de la génération Blog

Demande Cyril : quand l'application est utilisée en version EN, l'article généré doit être en anglais.

Correction : `BlogAiService::generate()` ajoute désormais une consigne de langue explicite au prompt `blog_generate` selon `app()->getLocale()` :
- `en` : génération obligatoire en anglais ;
- autres cas : génération obligatoire en français.

Ajout test : `test_t1010_ai_generate_uses_current_english_locale_for_article_language` vérifie qu'une session `locale=en` injecte la consigne anglaise dans le prompt envoyé au provider.

## 2026-07-13 19:15 — Séparation UX passage vs article

Troisième commit `9832e96` : `feat(blog): separate passage vs article question UX modes`.

Objectif : distinguer clairement "Questionner un passage" (sélection de texte, card latérale) de "Questionner l'article" (chat global Deep Chat, modal).

Modifié :
- `resources/js/app.js` — état `active` dans `blogMethodSelectionCard`, suppression restauration `localStorage`, toggle activate/deactivate, `openWholeArticleExplorer()` ;
- `resources/views/components/blog-editor.blade.php` — icône toolbar avec état actif violet ;
- `resources/views/blog/edit.blade.php` — bouton "Désactiver", CTA "Questionner tout l'article" dans la card passage ;
- `lang/fr/blog.php` — renommage "Questionner le texte" → "Questionner un passage" (icône/card) et "Questionner l'article" (chat), 3 nouvelles clés ;
- `lang/en/blog.php` — renommage EN correspondant.

Validations :
- `safe-test.sh --filter T1010` : PASS 3/15 ;
- `safe-test.sh --filter T3BlogEditorAiAdminTest` : PASS 29/73 ;
- Pint PASS, view:cache PASS, git diff-check PASS, npm build PASS ;
- Playwright : labels corrects, card inactive au chargement, cycle toggle fonctionne.

---

# Tests

- [x] `php -l app/Services/BlogAiService.php` ;
- [x] `php -l tests/Feature/T3BlogEditorAiAdminTest.php` ;
- [x] `ai/scripts/safe-test.sh --dry-run --filter T1010` ;
- [x] `ai/scripts/safe-test.sh --filter T1010` ;
- [x] `ai/scripts/safe-test.sh --dry-run --filter T3BlogEditorAiAdminTest` ;
- [x] `ai/scripts/safe-test.sh --filter T3BlogEditorAiAdminTest` ;
- [x] `vendor/bin/pint --dirty --format agent` ;
- [x] `php artisan view:cache` ;
- [x] `git diff --check`.

---

# Test Results

- `safe-test.sh --filter T1010` : PASS, 3 tests, 15 assertions.
- `safe-test.sh --filter T3BlogEditorAiAdminTest` : PASS, 29 tests, 73 assertions.
- Pint : PASS.
- View cache : PASS.
- Diff check : PASS.

---

# Review Notes

Le correctif est volontairement limité au contenu retourné par `blog_generate`.

La réponse IA brute reste disponible dans `AiInteraction.response`; seul le contenu fonctionnel destiné à l'éditeur et au brouillon est nettoyé.

Aucun changement Dossiers, domaine, DNS, Cloud, migration, seed ou frontend.

---

## 2026-07-14 20:35 — Login redirect fix (commit 6d5f2f3)

### Contexte

Le post-login redirect utilisait une logique dupliquée et incohérente dans `AuthenticatedSessionController` et `RegisteredUserController`.

### Modifications

1. **`app/Models/User.php`** — ajouté `getLoginRedirectTarget()` :
   - Global admin → `/admin/dashboard`
   - Org admin (pas global) → `/org/{slug}/admin`
   - Regular user → `canonicalHome($org)` (loops pour default org, org loops pour scoped org)
   - Fallback → `/dashboard`

2. **`app/Http/Controllers/Auth/AuthenticatedSessionController.php`** — simplifié en `redirect()->intended($user->getLoginRedirectTarget())`

3. **`app/Http/Controllers/Auth/RegisteredUserController.php`** — idem

4. **`app/Support/helpers.php`** — `canonicalHome()` retourne `organization.loops.index` pour les scoped orgs avec `loops_enabled`

### Tests live (tous PASS)

| User | Expected | Actual |
|------|----------|--------|
| admin@bouclepro.test | `/admin/dashboard` | ✓ |
| launchpals.member1@bouclepro.test (org admin) | `/org/launchpals/admin` | ✓ |
| main.member1@bouclepro.test | `/loops/{id}` | ✓ |
| launchpals.member2@bouclepro.test | `/org/launchpals/loops/{id}` | ✓ |

---

# Version Notes

- Version bump officiel : `1.002` → `1.003`.
- Commande utilisée : `ai/scripts/bump-version.sh 1.003`.
- Ne pas modifier le footer manuellement ; il lit `config('app.version')`.
- 2 migrations pending à jouer lors de la merge : voir section "Pending Migrations".
