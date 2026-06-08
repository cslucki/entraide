---
task_id: TASK-226
title: Prod-local sync safety and release readiness strategy

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-226-prod-local-sync-safety-and-release-readiness-strategy

priority: HIGH

created_at: 2026-06-08 12:22:47 Europe/Paris
updated_at: 2026-06-08 23:31:16 Europe/Paris

labels:
  - safety
  - sync
  - scripts

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: MERGED
  url: null
---

# Objective

Sécuriser le workflow de synchronisation PROD → local PostgreSQL, documenter les scripts existants, et préparer une procédure de release readiness pour un futur alignement develop → main / PROD.

Cette tâche NE déploie PAS en production.
Cette tâche NE lance PAS `migrate:fresh`, `db:wipe`, `prod-mirror`.

---

# Contexte initial

## Git state

- **Branche courante :** `TASK-226-prod-local-sync-safety-and-release-readiness-strategy`
- **Base :** `develop` (HEAD `8a4cfae` — `feat(ai): add backup-internal script for ignored directories`)
- **Git status à l'arrivée :** sale (pré-existant, pas causé par l'agent courant)
  - `AGENTS.md` modifié (10 lignes supprimées — section backup-internal.sh + en-tête MCP Tools)
  - `ai/scripts/backup-internal.sh` supprimé (67 lignes)
- **Action prise :** stash dédié `"pre-TASK-226: cleanup backup-internal.sh + AGENTS.md doc"`
- **Stash :** `stash@{0}` — préserve les changements, ne les mélange pas à cette tâche

## Divergence main/develop

| Métrique | Valeur |
|---|---|
| Commits develop-only | ~597 |
| Commits main-only | 3 (release backports pre-T074) |
| Fichiers de migration différents | 36 (12 modifiés, 23 ajoutés, 1 renommé) |

Dernière migration commune : `2026_05_13_000005_drop_point_ledger_reason_check_constraint`.
Develop a des migrations pour : loops, organization_settings, blog naming, hero gradient, service points, PWA mobile, bug_reports.

L'alignement develop → main est une tâche séparée (release readiness future).

## Incident pg-validate.sh

**Ce qui s'est passé :** L'agent a exécuté `ai/scripts/pg-validate.sh` dans le cadre de l'audit. Le Step 5 de ce script lance `php artisan migrate:fresh --seed --force` sur la base `bouclepro` (pas `bouclepro_test`).

**Conséquence :** La base PostgreSQL locale `bouclepro` a été vidée et re-seedée :
- 4 organisations créées (seed)
- 17 utilisateurs créés (seed)
- DashboardDemoSeeder a échoué (`Undefined array key "conseil"`) — bug préexistant

**Violation des règles :** L'AGENTS.md interdit explicitement `migrate:fresh --seed` sur `bouclepro`. Le script `pg-validate.sh` est dangereux car il n'a aucun garde-fou empêchant de cibler la base principale.

**Réparation locale :** Aucune action de restauration entreprise. La DB locale contient désormais des données de seed fraîches. La prochaine sync PROD→local via `sync-prod-to-local.sh` pourra restaurer des données réalistes.

## État DB après reset (seed)

| Table | Compteur |
|---|---|
| organizations | 4 |
| users | 17 |
| services | 0 |
| service_requests | 0 |
| blog_posts | 0 |
| loops | 0 |
| loop_members | 0 |
| loop_messages | 0 |

## Dumps disponibles

| Source | Fichier | Taille |
|---|---|---|
| Sync PROD (ancien) | `_bash_cyril/synchro_prod/dumps/production_20260530_130035.dump` | 116 KB |
| Sync PROD (ancien) | `_bash_cyril/synchro_prod/dumps/production_20260530_125946.dump` | 116 KB |
| Backup interne local | `/home/cyril/claude-code/local-backups/test.laravel-internal/_backup_snapshots/local_bouclepro_20260602_155605.dump` | 121 KB |

## Conclusion sur l'état actuel

- La base seed actuelle est *suffisante* pour valider des bugs UI, navigation, ou authentification simples
- Elle est *insuffisante* pour valider services, demandes, blog, loops, transactions ou données métier réelles

## Tentative de sync PROD (2026-06-08)

**Premier essai :** échec phase 7 — `PHP_SCRIPT` résolvait `$BASE_DIR/synchro_pgsql-avant-migration/sync-prod-to-local.php` alors que les scripts étaient encore dans `_bash_cyril/`. Temp DB `bouclepro_prod_import_tmp` préservée pour diagnostic.

**Cyril a déplacé** les scripts de `_bash_cyril/synchro_pgsql-avant-migration/` vers `synchro_pgsql-avant-migration/` (racine projet). `BASE_DIR` corrigé (retour à `..`).

**Second essai :** sync complète réussie (script, ETL, seeders, nettoyage temp DB). Rapport final : `synchro_pgsql-avant-migration/logs/rapport-final-20260608_170501.md`.

**Résultat :** 19 users locaux vs 20 attendus (PROD). Cause : `vin.100.gay@gmail.com` existait déjà localement avec un UUID différent. L'ETL upsert par PK n'a pas pu écraser par email. Les 5 QA ont été ajoutés par `QaAccountsSeeder`.

**Conclusion :** L'ETL non destructif ne peut pas produire une copie exacte de la PROD car il fusionne avec les données locales existantes. Pour un miroir fidèle, il faut repartir d'une base vide.

## Nouvelle stratégie : cycle rehearsal → release → sync

Suite à discussion avec Cyril, la stratégie évolue vers un cycle durable :

1. **Rehearsal :** restaurer un dump PROD complet dans `entraide_rehearsal`, exécuter `php artisan migrate`, valider.
2. **Release :** corriger les bugs, préparer la migration finale vers PROD `entraide` sur Laravel Cloud.
3. **Local sync :** après chaque release PROD, réaligner `bouclepro` depuis la PROD migrée pour rester un miroir de travail.
4. **Nouveaux besoins :** les données réelles PROD (ex. nouvel utilisateur veut créer une boucle/org) sont reflétées localement avant développement.
5. **Toute migration future :** passe d'abord par rehearsal avant PROD.

### Cycle cible

```text
PROD entraide (source de vérité)
  → rehearsal entraide_rehearsal (migration test)
  → release vers PROD (après validation)
  → réalignement bouclepro (miroir local)
  → nouveaux développements
  → nouveau rehearsal
  → nouvelle release
```

Document de rehearsal créé : `migration/2026-06-08-rehearsal-prod-to-develop.md` (dossier local exclu de Git via `.git/info/exclude`).

---

# Scripts audités

## pg-validate.sh — DANGEREUX

- **Lignes :** 164
- **Ce qu'il fait :** Prereqs → check reachable → create bouclepro_test → switch to pgsql → **migrate:fresh --seed --force** → PHPUnit
- **Risque :** Le Step 5 cible `bouclepro`, pas `bouclepro_test`. Aucun garde-fou ne vérifie la cible.
- **Action :** À neutraliser pour empêcher un nouveau wipe involontaire.

## pg_get_and_convert_from_prod.sh — CASSÉ / OBSOLÈTE

- **Lignes :** 162
- **Problème :** Ligne 20 référence `$BASE_DIR/.ai-local/orchestrator/scripts-orchestrator/pg-sync-transform.php` qui n'existe plus.
- **Dépendance :** Aucun autre script ne référence ce fichier. Le script est inutilisable.
- **Action :** Marquer comme obsolète dans le header. Ne pas supprimer — simple marquage.

## pg-dump.sh — Fonctionnel mais DESTRUCTIF

- **Lignes :** 529
- **Ce qu'il fait :** Dump PROD, import local, mirror-import, reset local.
- **Sécurité :** Dispose de prompts `read -rp` pour confirmation destructive.
- **Action :** Utilisable uniquement sur validation Cyril explicite. Pas de modification.

## sync-prod-to-local.sh — RECOMMANDÉ

- **Lignes :** 530+
- **Ce qu'il fait :** Utilise une DB temporaire `bouclepro_prod_import_tmp`, pg_dump PROD → restore temp → ETL PHP sync → rapport.
- **Sécurité :** 
  - NE modifie PAS le schéma local
  - NE touche pas à PROD (read-only)
  - Préserve les tables locales (loops, loop_members, loop_messages)
  - Temp DB isolée, drop after sync
  - Backfill legacy organization_id
- **Dépendances :** `sync-prod-to-local.php` (726 lignes, ETL upsert FK-safe)
- **Action :** Audit complet déjà fait. C'est le script candidat pour Étape 2.

## sync-prod-to-local.php — COMPOSANT ETL

- **Lignes :** 726
- **Ce qu'il fait :** Connexion deux bases, tables communes, upsert par PK, FK ordering, backfill, reset sequences, rapport.
- **Action :** Audit complet. Aucune modification nécessaire.

---

# Commandes interdites (tâche + vie courante)

- `php artisan migrate:fresh --seed` — wipe la DB locale
- `php artisan db:wipe` — wipe la DB locale
- `pg-dump.sh prod-mirror` — destructif local
- `pg-validate.sh` — tant que non sécurisé
- Toute commande Laravel Cloud
- Tout push sur `main`
- Tout merge sur `main`
- Toute migration PROD
- Tout dump PROD (sans validation Cyril explicite)

# Commandes nécessitant validation Cyril avant exécution

- `sync-prod-to-local.sh` — dump + sync réelle
- `pg-dump.sh` mode dump PROD
- `pg-dump.sh` mode import local
- Toute commande PostgreSQL admin (création/suppression de DB)
- Toute commande de restauration de dump

---

# Planned Actions

- [x] Audit Git : status, divergence main/develop
- [x] Audit scripts : pg-dump, pg-validate, pg_get_and_convert_from_prod, sync-prod-to-local
- [x] Audit PostgreSQL local : connexion, état
- [x] Rapport Étape 1 complet
- [x] Stash préexistant préservé
- [x] TASK-226 créée, branche créée
- [x] Sécuriser `pg-validate.sh` (garde-fou : blocage si DB != bouclepro_test)
- [x] Marquer `pg_get_and_convert_from_prod.sh` comme obsolète/cassé (header uniquement)
- [x] Audit final `sync-prod-to-local.sh` et `.php`
- [x] Exécution sync PROD (non destructive, 19 users)
- [x] Nouvelle stratégie rehearsal `entraide_rehearsal`
- [x] Dossier `migration/` créé, exclu Git
- [x] Note de rehearsal rédigée
- [x] Exécuter le rehearsal (création entraide_rehearsal → restore dump → migrations → validation)
- [x] Ajouter QA (QaAccountsSeeder) sur entraide_rehearsal — exécuté
- [x] Tests Cyril (login, navigation, parcours métier) — OK
- [x] Admin outils : controller + routes + vues + menu Outils
- [x] Fix view : mapping catégories par slug (pas par id)
- [x] Fix view : withCount services/service_requests dans fix-categories
- [x] Fix DashboardDemoSeeder : slugs obsolètes → nouveaux slugs + fallbackCategory()
- [x] Bug admin/users (Alpine scope TASK-225)
- [x] Centre supervision IA (OpenAI 401 diagnostiqué + modèle)

---

# Progress Log

## 2026-06-08 12:22:47 Europe/Paris

Task created. Branch: TASK-226-prod-local-sync-safety-and-release-readiness-strategy

## 2026-06-08 12:22:47 Europe/Paris + delta

Contexte complet ajouté au TASK file :
- Git state + stash préexistant
- Divergence main/develop (597 commits, 36 migrations)
- Incident pg-validate.sh documenté
- Audit des 5 scripts (pg-dump, pg-validate, pg_get_and_convert_from_prod, sync-prod-to-local.sh/.php)
- Commandes interdites listées
- Commandes à validation Cyril listées
- Plan de sécurisation

## 2026-06-08 ~12:30 Europe/Paris

Sécurisation appliquée :
- `ai/scripts/pg-validate.sh` : garde-fou ajouté au Step 5. Lit `DB_DATABASE` depuis `.env`. Si != `bouclepro_test`, bloque avec `exit 1` et message clair. Ne bascule pas silencieusement.
- `ai/scripts/pg_get_and_convert_from_prod.sh` : header marqué OBSOLÈTE/CASSÉ avec référence vers `sync-prod-to-local.sh` comme remplacement.
- Aucun script lancé après modification.
- Aucun commit.

## 2026-06-08 ~13:10 Europe/Paris

Amélioration du garde-fou pg-validate.sh (review Cyril) :
- `php artisan config:clear` ajouté **avant** la détection de la cible (supprime tout cache de config)
- Double vérification : `.env` (brut) + `php artisan config:show` (valeur réelle Laravel après bootstrap)
- Blocage si l'une ou l'autre des deux valeurs est vide, inconnue, ou != `bouclepro_test`
- Aucune redirection silencieuse vers `bouclepro_test`
- Aucun test / aucun script destructif lancé

Documentation ajoutée au TASK file :
- Compteurs DB post-reset (organizations=4, users=17, services=0, etc.)
- Dumps disponibles (synchro_prod + backup interne)
- Conclusion : seed suffisant pour UI simple, insuffisant pour données métier

## 2026-06-08 ~17:00-17:30 Europe/Paris

Sync PROD exécutée via `synchro_pgsql-avant-migration/sync-prod-to-local.sh` :
- Premier essai échoué (path scripts déplacés). Script corrigé après déplacement Cyril.
- Second essai réussi (dump PROD 112K, ETL complet, seeders, nettoyage temp DB).
- Résultat : 19 users (conflit email `vin.100.gay@gmail.com` existant local).
- Rapport : `synchro_pgsql-avant-migration/logs/rapport-final-20260608_170501.md`.

Nouvelle direction stratégique (discussion Cyril) :
- Objectif : simuler la future mise à jour PROD.
- Base de rehearsal : `entraide_rehearsal` (dump PROD complet + migrations develop).
- Dossier `migration/` créé, exclu de Git.
- Note de rehearsal : `migration/2026-06-08-rehearsal-prod-to-develop.md`.

## 2026-06-08 ~17:45-18:00 Europe/Paris

Discussion stratégique avec Cyril :
- Objectif final : PROD migrée + local capable de recevoir et développer des besoins réels (exemple : un utilisateur veut créer une boucle/org, on développe localement, on met à jour PROD).
- Cycle défini : rehearsal → release → sync locale → nouveaux développements → nouveau rehearsal → nouvelle release.
- Note `migration/2026-06-08-rehearsal-prod-to-develop.md` mise à jour : étapes clarifiées (config:clear avant migrate, retour au contexte bouclepro en option, notation des modifications PROD au fil de l'eau).
- Section "Cycle cible après migration" ajoutée à la note.

## 2026-06-08 19:30:00 Europe/Paris

Rehearsal exécuté avec succès :
- Backup bouclepro → OK
- entraide_rehearsal créée, dump PROD restauré (20 users, 40 tables, 48 migrations)
- .env pointé sur entraide_rehearsal, config:clear + vérification
- php artisan migrate : 1 échec initial (FK loops → organizations avant rename), corrigé → 22 migrations PASS
- Compteurs post-migration : 20 users, 1 org, 7 services, 4 requests, 2 transactions, 2 blog_posts, 0 loops
- Migration corrigée : `2026_05_15_000001_create_loops_table` FK référencée sur `communities` au lieu de `organizations`
- Note de rehearsal mise à jour en temps réel avec horodatages

## 2026-06-08 19:45:00 Europe/Paris

QaAccountsSeeder exécuté sur entraide_rehearsal : 5 QA accounts créés (qa-admin, qa-member1/2, qa-cpme1/2), org CPME créée. 25 users au total.

## 2026-06-08 ~21:00 Europe/Paris

Build admin outils + fixes bugs :

**Admin outils créés :**
- `app/Http/Controllers/Admin/AdminOutilsController.php` — class avec assignData, doAssignData, fixCategories, doFixCategories
- `resources/views/admin/outils/assign-data.blade.php` — page fusionnée pour affecter utilisateurs, services, demandes et messages à une organisation cible
- `resources/views/admin/outils/fix-categories.blade.php` — comparison table current vs new, one-click apply
- Routes : `admin.outils.assign-data`, `admin.outils.assign-data.do`, `admin.outils.fix-categories`, `admin.outils.fix-categories.do`
- Menu sidebar : groupe "Outils" entre Organisations et IA

**Bugs fixés :**
- `fix-categories.blade.php` : `$mapping[$cat->id]` → `$mapping[$cat->slug]` (les clés du mapping sont par slug, pas par id)
- `AdminOutilsController::fixCategories()` : ajout `->withCount(['services', 'serviceRequests'])`
- `DashboardDemoSeeder` : slugs obsolètes (`conseil`, `formation`, `autre`, `tech-digital`, `traduction`, `design`) → nouveaux slugs correspondants (`lancer-son-activite`, `aides-demarches`, `bien-etre-equilibre`, `bricolage-projets-perso`, `creer-des-supports`, `ecrire-communiquer`). Ajout `fallbackCategory()` pour résilience si slug manquant.

## 2026-06-08 ~22:00 Europe/Paris

**Correctifs complémentaires :**

**Bug Alpine admin (TASK-225) :** Cause racine identifiée et corrigée :
- `app.js` n'importait pas Alpine.js (package.json avait alpinejs ^3.4.2 mais pas d'import)
- `admin.blade.php` n'avait pas `@stack('scripts')` → les `@push('scripts')` des vues admin ne s'affichaient pas
- Alpine n'était chargé que via `<livewire:scripts />` dans `app.blade.php` (layout public), pas dans l'admin
- Fix : `import Alpine from 'alpinejs'; window.Alpine = Alpine; Alpine.start();` dans `resources/js/app.js`
- Fix : `@stack('scripts')` ajouté dans `resources/views/layouts/admin.blade.php`

**Administration IA :** 401 OpenAI diagnostiqué + correctifs :
- Cause : clé API `proj-...` invalide/expirée (OpenAI: "Incorrect API key provided")
- `OpenAiSupervisionProvider::loadTaxonomyFromDb()` lit `categories` (slug/name_b2c) et `skills` (slug/name) depuis la DB, fallback sur config
- `SupervisionProvider::supervise()` accepte `$model` optionnel
- Controller valide depuis `AVAILABLE_MODELS` (gpt-4o-mini, gpt-4o, gpt-4.1-mini, gpt-4.1-nano, o4-mini)
- Dropdown sélecteur de modèle dans la vue

**Rappels :**
- `.env` toujours pointé sur `entraide_rehearsal` (pas `bouclepro`)
- Clé OpenAI invalide → besoin d'une nouvelle clé pour tester supervision IA
- En attente décision Cyril : commit des correctifs ou poursuite

## 2026-06-08 22:16:25 Europe/Paris

Évolution demandée par Cyril : fusionner l'outil d'affectation utilisateurs avec un outil d'affectation des données PROD sans organisation.

Implémentation :
- Remplacement de `assign-users` par `assign-data`
- Page unique `resources/views/admin/outils/assign-data.blade.php`
- Jeux de données cochables : users, services, service_requests, messages
- Controller met à jour uniquement `organization_id` vers l'organisation cible choisie
- `Service::withoutGlobalScopes()` et `ServiceRequest::withoutGlobalScopes()` utilisés pour inclure aussi les lignes sans org/hors scope courant
- Menu admin renommé : "Affecter données"
- `php artisan optimize:clear` exécuté pour purger les anciennes routes/vues compilées

Vérifications :
- `php -l app/Http/Controllers/Admin/AdminOutilsController.php` OK
- `php -l routes/web.php` OK
- `php artisan route:list --name=admin.outils` OK : 4 routes, dont `admin.outils.assign-data` + `.do`
- Recherche anciennes références `assign-users|assignUsers|doAssignUsers` OK après purge caches

## 2026-06-08 22:30:10 Europe/Paris

Audit Cyril après test de `/admin/outils/assign-data` puis `/admin/messages` :
- La base est correcte pour `messages` : 2 messages, 0 sans `organization_id`.
- L'écran `/admin/messages` était buggué : il filtrait les messages d'échanges via `transactions.organization_id`, alors que les transactions associées étaient encore sans org.
- Correction `AdminMessageController` : filtre échanges et `show()` sur `messages.organization_id`; ChatLoop filtré sur `loop_messages.organization_id`.
- Extension `assign-data` aux tables admin/métier avec `organization_id` : transactions, loops, loop_members, loop_messages, blog_posts, blog_comments, blog_post_tag, reports, bug_reports, referrals, referral_rewards, point_ledger, categories, skills, tags, service_skill, service_tag, service_images, request_attachments, reviews, favorites, likes, email_templates, email_logs.
- Validation `datasets.*` rendue dynamique via `Rule::in(array_keys($datasets))`.
- Audit tables sans colonne `organization_id` : `community_requests` et `organization_requests` sont les seules tables applicatives concernées. À décider : demandes publiques pré-tenant ou oubli de migration.

Validation :
- `php -l app/Http/Controllers/Admin/AdminOutilsController.php` OK
- `php -l app/Http/Controllers/Admin/AdminMessageController.php` OK
- Routes outils toujours enregistrées

## 2026-06-08 22:47:53 Europe/Paris

Décision Cyril sur tables sans `organization_id` :
- `community_requests` est legacy et doit être supprimée. Décision notée dans `migration/2026-06-08-rehearsal-prod-to-develop.md`.
- `organization_requests` reste volontairement sans tenant : demande publique de mise à disposition de la plateforme sur un autre serveur.
- Audit routes : `organization_requests` n'a pas de page admin actuellement. Le flux est public via `GET/POST /partenaires/demande`, controller `OrganizationRequestController`, vue `resources/views/organization-requests/create.blade.php`.

## 2026-06-08 23:09:00 Europe/Paris

Implémentation admin demandée par Cyril :
- Page admin de consultation des `organization_requests` créée : `AdminOrganizationRequestController@index`, route `admin.organization-requests`, vue `admin/organization-requests/index.blade.php`.
- Menu Organisations : item `Demandes plateforme` ajouté en bas, avec badge sur les demandes `pending` via `pendingOrganizationRequestsCount`.
- Pages Échanges équipées d'un filtre organisation : Services, Transactions, Demandes, Boucles, Messages, Blog.
- Comportement filtre : par défaut organisation principale ; option `Toutes les organisations` ; option organisation ciblée par UUID.
- `/admin/outils/assign-data` : colonne `Table` ajoutée pour rendre explicite le nom de table/dataset.
- Journal rehearsal mis à jour avec la décision : `organization_requests` non tenant-scopée, `community_requests` à supprimer.

## 2026-06-08 23:27:31 Europe/Paris

Clôture demandée par Cyril.

Actions finales :
- TASK-226 passé en `DONE` et déverrouillé.
- Tous les fichiers modifiés de la tâche sont conservés pour staging/commit.
- Validation finale déjà exécutée : syntaxe PHP, routes admin, cache Blade, puis `optimize:clear`.
- Aucun déploiement PROD, aucune commande Laravel Cloud, aucune commande destructive.

## 2026-06-08 23:31:16 Europe/Paris

TASK-226 mergée dans `develop` via `ai/scripts/merge-task.sh TASK-226`.

Actions de clôture :
- Branche TASK-226 poussée sur `origin` via `finalize-task.sh`.
- Merge `--no-ff` vers `develop` exécuté avec succès.
- `develop` poussé sur `origin`.
- Bump version corrigé manuellement après merge : `v0.223-alpha` → `v0.226-alpha` via `ai/scripts/bump-version.sh TASK-226`.
- Statut TASK passé à `MERGED`.

---

# Tests

Validations exécutées pendant la tâche :

- `php -l app/Http/Controllers/Admin/AdminController.php` OK
- `php -l app/Http/Controllers/Admin/AdminBlogController.php` OK
- `php -l app/Http/Controllers/Admin/AdminLoopController.php` OK
- `php -l app/Http/Controllers/Admin/AdminMessageController.php` OK
- `php -l app/Http/Controllers/Admin/AdminOrganizationRequestController.php` OK
- `php -l app/Http/Controllers/Admin/AdminOutilsController.php` OK
- `php -l routes/web.php` OK
- `php -l app/Providers/AppServiceProvider.php` OK
- `php artisan route:list --name=admin.organization-requests` OK
- `php artisan route:list --name=admin.outils` OK
- Routes admin Échanges vérifiées : services, transactions, requests, loops, messages, blog
- `php artisan view:cache` OK
- `php artisan optimize:clear` OK après validation

Tests Laravel complets non lancés volontairement : protection base locale, ne pas risquer `RefreshDatabase` sur `bouclepro`.

---

# Modified Files

Fichiers modifiés ou créés dans TASK-226 :

- `TODO/TASK-226-prod-local-sync-safety-and-release-readiness-strategy.md`
- `ai/scripts/pg-validate.sh`
- `ai/scripts/pg_get_and_convert_from_prod.sh`
- `app/Http/Controllers/Admin/AdminAiSupervisionController.php`
- `app/Http/Controllers/Admin/AdminBlogController.php`
- `app/Http/Controllers/Admin/AdminController.php`
- `app/Http/Controllers/Admin/AdminLoopController.php`
- `app/Http/Controllers/Admin/AdminMessageController.php`
- `app/Http/Controllers/Admin/AdminOrganizationRequestController.php`
- `app/Http/Controllers/Admin/AdminOutilsController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Ai/Contracts/SupervisionProvider.php`
- `app/Services/Ai/Providers/OpenAiSupervisionProvider.php`
- `database/migrations/2026_05_15_000001_create_loops_table.php`
- `database/seeders/DashboardDemoSeeder.php`
- `resources/js/app.js`
- `resources/views/admin/ai-supervision/index.blade.php`
- `resources/views/admin/blog/index.blade.php`
- `resources/views/admin/loops/index.blade.php`
- `resources/views/admin/messages/index.blade.php`
- `resources/views/admin/organization-requests/index.blade.php`
- `resources/views/admin/outils/assign-data.blade.php`
- `resources/views/admin/outils/fix-categories.blade.php`
- `resources/views/admin/requests.blade.php`
- `resources/views/admin/services.blade.php`
- `resources/views/admin/transactions.blade.php`
- `resources/views/layouts/admin.blade.php`
- `routes/web.php`

---

# Review Notes

Points critiques à valider avant cloture :
1. [x] pg-validate.sh neutralisé — garde-fou config:clear + .env + artisan config:show, bouclepro_test uniquement
2. [x] pg_get_and_convert_from_prod.sh marqué obsolète sans suppression destructive
3. [x] sync-prod-to-local.sh + .php exécutés (19 users, conflit email)
4. [x] Rehearsal `entraide_rehearsal` exécuté (22 migrations, 0 errors)
5. [x] QA accounts ajoutés (QaAccountsSeeder)
6. [x] Tests Cyril (login, navigation, parcours métier) — OK
7. [x] DashboardDemoSeeder — fixé (slugs obsolètes + fallback)
8. [x] Admin outils build — controller, routes, vues, menu
9. [x] Bug admin/users (Alpine scope TASK-225) — corrigé (import Alpine + @stack scripts)
10. [x] Centre supervision IA (OpenAI 401) — diagnostiqué (clé invalide), fix taxonomy DB + modèle selector
11. [x] Outil affectation données — étendu aux tables admin/métier avec `organization_id`
12. [x] `/admin/messages` — corrigé pour filtrer via `messages.organization_id` / `loop_messages.organization_id`
13. [x] Décision architecture : `community_requests` à supprimer, `organization_requests` reste publique sans tenant
14. [x] Page admin pour consulter les `organization_requests` + badge nouvelles demandes
15. [x] Filtres organisation ajoutés sur les pages Échanges admin
16. [x] `/admin/outils/assign-data` — colonne table ajoutée

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
