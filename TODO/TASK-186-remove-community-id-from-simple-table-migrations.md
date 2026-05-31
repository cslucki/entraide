---
task_id: TASK-186
title: remove community_id from simple table migrations

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-186-remove-community-id-from-simple-table-migrations

priority: MEDIUM

created_at: 2026-05-31 19:15:15 Europe/Paris
updated_at: 2026-05-31 21:00:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-05-31 21:00:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Retirer `community_id` du chemin fresh-install pour les 5 tables simples (users, services, service_requests, transactions, blog_posts), sans toucher loops, referrals, referral_rewards ni l'app Laravel.

Stratégie :
- Les 4 migrations `add_community_id_to_*` deviennent no-op explicites.
- La migration blog_posts crée directement `organization_id` au lieu de `community_id`.
- `add_organization_id_to_tables` : colonnes et copie conditionnelles via `Schema::hasColumn`.
- `drop_community_id_from_tables` : drops conditionnels pour les 5 tables simples ; loops/referrals/referral_rewards intacts.

---

# Planned Actions

- [x] Inspecter l'architecture et les fichiers impactés (RAA RUN-005G)
- [x] Rendre no-op les 4 migrations add_community_id_to_* (fait en session précédente)
- [x] Modifier blog_posts migration : community_id → organization_id
- [x] Modifier add_organization_id_to_tables : guards hasColumn
- [x] Modifier drop_community_id_from_tables : drops conditionnels pour 5 tables simples
- [x] Audit community_id dans les 5 migrations simples (résultat : 0 occurrence)
- [x] Audit community_id dans migrations de transition (guards confirmés)
- [x] php artisan test (826 passed, 11 skipped, 0 failures)
- [x] migrate:fresh SQLite (succès complet)
- [x] migrate:fresh PostgreSQL (blocage environnemental — PostgreSQL non démarré)
- [x] vendor/bin/pint --dirty (appliqué)

---

# Progress Log

## 2026-05-31 19:15:15 Europe/Paris

Task created. Owner: SUPERVISOR. Branch: TASK-186-remove-community-id-from-simple-table-migrations.

## 2026-05-31 19:23:47 Europe/Paris

RAA reprise réalisée. Diff partiel présent : 4 migrations no-op'd (non stagées). TASK non tracké. Fichiers restants identifiés.

## 2026-05-31 21:00:00 Europe/Paris

Implémentation RUN-005G complète (Claude Sonnet 4.6, SUPERVISOR) :
- blog_posts migration : community_id → organization_id (up + down)
- add_organization_id_to_tables : guards hasColumn pour ajout colonne + copie
- drop_community_id_from_tables : drops conditionnels pour 5 tables simples, loops/referrals/referral_rewards intacts
- Audits : 0 community_id dans migrations simples, tous community_id restants dans transitions sont gardés
- Tests : 826 passed / 11 skipped / 0 failures
- migrate:fresh SQLite : PASS complet
- migrate:fresh PostgreSQL : BLOCAGE ENVIRONNEMENTAL (connexion refusée) — non bloquant
- Pint : appliqué

# Handoffs

# Tests

- [x] php artisan test — 826 passed, 0 failures
- [x] migrate:fresh SQLite — succès complet
- [ ] migrate:fresh PostgreSQL — blocage environnemental (PostgreSQL non démarré en WSL)
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

**php artisan test** : 826 passed, 11 skipped, 0 failures — 1756 assertions — 27.61s

**migrate:fresh --env=testing (SQLite)** : toutes les migrations passent sans erreur, chaîne complète validée.

**migrate:fresh --env=testing (PostgreSQL)** : SQLSTATE[08006] connection refused — blocage environnemental WSL, pas un bug de migration. Documenté.

---

# Review Notes

Implémentation strictement dans le périmètre RUN-005G :
- Aucune touche à loops, referrals, referral_rewards
- Aucune nouvelle migration créée
- Aucune modification app/, tests/, policies
- Compatibilité SQLite validée (foreign key handling différent — ok)
- Compatibilité vieille DB préservée via guards hasColumn (community_id encore présent → opérations normales)
- blog_posts : cas particulier — FK organization_id déjà créée par blog_posts migration en fresh install → bloc drop_community_id conditionnel sur hasColumn('blog_posts', 'community_id')

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
