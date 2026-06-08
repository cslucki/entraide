---
task_id: TASK-227
title: Production migration runbook and safety scripts

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-227-production-migration-runbook-and-safety-scripts

priority: HIGH

created_at: 2026-06-08 23:35:35 Europe/Paris
updated_at: 2026-06-08 23:53:38 Europe/Paris

labels:
  - production
  - migration
  - safety
  - runbook

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: READY
  url: null
---

# Objective

Rédiger et préparer dans `migration/` les éléments nécessaires au futur passage en production : runbook, garde-fous, scripts read-only/dry-run, stratégie de backfill `organization_id`, et décision claire sur l'usage des bases locales `entraide_rehearsal` et `bouclepro`.

Cette tâche ne lance aucune commande PROD et ne contient aucun secret.

---

# Planned Actions

- [x] Relire `migration/2026-06-08-rehearsal-prod-to-develop.md`
- [x] Relire `TODO/TASK-226-prod-local-sync-safety-and-release-readiness-strategy.md`
- [x] Déduire la procédure PROD cible depuis le rehearsal
- [x] Documenter dans `migration/PRODUCTION_MIGRATION_RUNBOOK.md`
- [x] Ajouter script preflight read-only
- [x] Ajouter générateur SQL review-only pour backfill `organization_id`
- [x] Documenter la décision `entraide_rehearsal` vs `bouclepro`
- [x] Valider syntaxe des scripts
- [x] Exécuter les scripts en mode safe/read-only ou generation-only
- [x] Backup interne local du dossier `migration/`

---
# Progress Log


## 2026-06-08 23:35:35 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-227-production-migration-runbook-and-safety-scripts

Status:
IN_PROGRESS

## 2026-06-08 23:37:25 Europe/Paris

Travail réalisé dans le dossier ignoré `migration/` :

- Création de `migration/PRODUCTION_MIGRATION_RUNBOOK.md`.
- Création de `migration/scripts/prod-migration-preflight.sh`.
- Création de `migration/scripts/generate-prod-org-backfill-sql.sh`.
- Génération test d'un SQL review-only : `migration/generated/prod_org_backfill_20260608_233725.sql`.

Décision documentée : ne pas renommer `entraide_rehearsal` en `bouclepro` maintenant.

Raison :
- `entraide_rehearsal` reste une base de rehearsal/repetition de migration.
- `bouclepro` reste la base locale quotidienne de développement.
- L'application va encore évoluer avec nouvelles migrations/tables avant la PROD.
- Renommer maintenant mélangerait le snapshot de rehearsal avec le contexte de développement.

Moment recommandé :
- Revenir à `bouclepro` dès que Cyril veut reprendre le développement quotidien.
- Recréer `entraide_rehearsal` depuis un dump PROD frais juste avant le vrai passage PROD.
- Après migration PROD réussie, réaligner `bouclepro` depuis la PROD migrée pour retrouver un miroir local fidèle.

## 2026-06-08 23:53:38 Europe/Paris

Correction de stratégie validée par Cyril : dupliquer `entraide_rehearsal` vers `bouclepro`, ne pas renommer.

Actions exécutées :
- Backup de l'ancienne base locale `bouclepro` : `migration/bouclepro_before_rehearsal_copy_20260608_2340.dump`.
- Dump de `entraide_rehearsal` : `migration/entraide_rehearsal_to_bouclepro_20260608_2340.dump`.
- Connexions actives à `bouclepro` terminées.
- `bouclepro` supprimée/recréée localement.
- Dump `entraide_rehearsal` restauré dans `bouclepro`.
- `.env` repointé vers `DB_DATABASE=bouclepro`.
- `php artisan config:clear` exécuté.

Validations :
- `php artisan config:show database.connections.pgsql.database` → `bouclepro`.
- Compteurs : users=25, organizations=2, services=7, service_requests=4, transactions=2, messages=2, blog_posts=2, point_ledger=24.
- Null `organization_id` = 0 sur les tables vérifiées.
- `php artisan migrate:status` : migrations attendues `Ran`.

Décision finale :
- `entraide_rehearsal` reste la référence rehearsal.
- `bouclepro` redevient la base locale quotidienne, issue de la copie rehearsal validée.
- Avant PROD, refaire un nouveau `entraide_rehearsal` depuis un dump PROD frais.

# Handoffs

# Tests

- [x] `bash -n migration/scripts/prod-migration-preflight.sh`
- [x] `bash -n migration/scripts/generate-prod-org-backfill-sql.sh`
- [x] `migration/scripts/prod-migration-preflight.sh` exécuté en read-only
- [x] `migration/scripts/generate-prod-org-backfill-sql.sh` exécuté en generation-only
- [x] Backup local de `bouclepro` avant remplacement
- [x] Copie locale `entraide_rehearsal` → `bouclepro`
- [x] `.env` repointé sur `DB_DATABASE=bouclepro`
- [x] `php artisan migrate:status` vérifié

---

# Test Results

- Syntaxe bash OK.
- Preflight OK, avec avertissement attendu : working tree non clean car TASK-227 non encore commitée.
- Cible Laravel actuelle confirmée : `DB_DATABASE=entraide_rehearsal`.
- SQL review-only généré sans exécution DB.
- `bouclepro` restaurée depuis `entraide_rehearsal` et validée.

---

# Review Notes

- Les fichiers principaux sont dans `migration/`, ignoré Git volontairement.
- Backup interne requis après modification de `migration/`.
- Aucun secret ajouté.
- Aucune commande PROD exécutée.
- Aucune commande destructive exécutée.
- Commandes destructives uniquement locales sur `bouclepro`, après backup local et validation Cyril explicite.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
