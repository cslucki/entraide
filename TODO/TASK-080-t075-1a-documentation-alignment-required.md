---
task_id: TASK-080
title: T075.1A — Documentation Alignment Required

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-080-t075-1a-documentation-alignment-required

priority: HIGH

created_at: 2026-05-16 20:35:06 Europe/Paris
updated_at: 2026-05-16 20:35:06 Europe/Paris

labels:
  - documentation
  - alignment
  - architecture
  - tenant-resolution
  - root-domain

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

Aligner les documents projet avec la stratégie T075.1 afin que les agents futurs ne confondent plus Partner, Organization, Loop, Community legacy, root domain, default Organization et partner slug routes.

**Source de vérité** : `docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md`

**Section de référence** : 7. Documentation Alignment Required

**Scope** : Documentation-only. Aucun code applicatif modifié.

---

# Documents à auditer

- `ai/context/architecture.md`
- `ai/context/multi-tenant.md`
- `ai/context/routing-strategy.md`
- `ai/context/business-rules.md`
- `docs/06-DOMAIN_ARCHITECTURE_V2.md`
- `docs/07-GLOSSARY.md`
- `docs/08-COMMUNITY_MIGRATION_STRATEGY.md`
- `docs/09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`
- `AGENTS.md`
- `CLAUDE.md`

---

# Architecture Rules à Rappeler

- **Organization = Tenant**. Tenant boundary, security boundary, billing boundary, governance boundary.
- **Loop ≠ Tenant**. Loops are collaborative contexts, relational groups, operational spaces. Never tenants.
- **Partner ≠ Tenant**. Partner = co-branding / distribution channel. Never a security or DB isolation layer.
- **Community / community_id / current_community** = legacy technique temporaire. Ne pas réintroduire comme concept produit.
- **Root domain n'est pas tenantless**. Toute route métier nécessite une Organization résolue.
- **/{feature}** résout l'Organization par défaut de la plateforme.
- **/{partnerSlug}/{feature}** résout l'Organization partenaire.
- **Public ≠ global**. Les endpoints publics restent Organization-scopés.
- **Toutes les features métier actuelles et futures doivent être Organization-scopées**.
- **/boucles est legacy** et doit être distingué des vrais Loops. Future redirection vers /partners.
- **Ancien "Créer une boucle"** à auditer / repositionner plus tard en "Devenir partenaire".

---

# Rationale

T075.1 a établi une stratégie claire de résolution du root domain. Les documents projet existants contiennent encore des confusions conceptuelles :

- Partner parfois traité comme tenant
- Loop parfois traité comme tenant
- Community encore présenté comme concept produit
- Root domain pas clairement défini comme tenant-aware
- Default Organization vs partner Organization pas distingué
- Public vs global pas clarifié

Cette tâche aligne la documentation pour que tous les agents (GLM, Claude, OpenCode, Codex, Gemini, Jules, DeepSeek) partagent le même modèle mental.

---

# Planned Actions

- [x] vérifier quels documents existent
- [x] ajouter Organization Scoping Rule dans chaque document pertinent
- [x] clarifier Partner ≠ Tenant
- [x] clarifier Loop ≠ Tenant
- [x] clarifier Organization = Tenant
- [x] clarifier Community legacy temporaire
- [x] ajouter root domain → default Organization
- [x] ajouter partnerSlug → Organization partenaire
- [x] documenter public ≠ global
- [x] documenter /boucles legacy → future /partners
- [x] documenter que toutes les futures features métier doivent être Organization-scopées
- [x] préparer handoff CODE
- [x] prévoir review OPENAI après rédaction

---

# Progress Log

## 2026-05-16 20:35:06 Europe/Paris

Task created by OPENCODE.
- Branche T075.1 mergée et supprimée (remote + local).
- T075.1A créée via create-task.sh.
- Scope : documentation alignment only.
- Aucun code applicatif modifié.

## 2026-05-16 23:30:00 Europe/Paris

Document alignment complete. 10/10 documents updated.

### Documents inspectés (tous existants)
- `ai/context/architecture.md` — modifié
- `ai/context/multi-tenant.md` — modifié
- `ai/context/routing-strategy.md` — modifié
- `ai/context/business-rules.md` — modifié
- `docs/06-DOMAIN_ARCHITECTURE_V2.md` — modifié
- `docs/07-GLOSSARY.md` — modifié
- `docs/08-COMMUNITY_MIGRATION_STRATEGY.md` — modifié
- `docs/09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md` — modifié
- `AGENTS.md` — modifié
- `CLAUDE.md` — modifié

### Résumé des ajouts par fichier

| Fichier | Ajouts |
|---------|--------|
| `ai/context/architecture.md` | Organisation Scoping Rule + Partner ≠ Tenant + Loop ≠ Tenant + Public ≠ Global + URL Context Resolution Order |
| `ai/context/multi-tenant.md` | URL Context Resolution Order (5 niveaux : Platform global → Default Org → Partner slug → Authenticated personal → Fail-safe) |
| `ai/context/routing-strategy.md` | Root Domain Routing Patterns : pattern cible `/{feature}` et `/{partnerSlug}/{feature}`, règles public ≠ global, boucles legacy |
| `ai/context/business-rules.md` | Organisation Scoping Rule avec liste complète des features concernées |
| `docs/06-DOMAIN_ARCHITECTURE_V2.md` | 3 nouvelles sections : Partner/Co-branding, URL Context Resolution, Organisation Scoping Rule |
| `docs/07-GLOSSARY.md` | Concepts T075.1 : Partner, Default Organization, Partner slug route, Root domain, Platform global route, Organization-scoped public route + rappels critiques |
| `docs/08-COMMUNITY_MIGRATION_STRATEGY.md` | Root domain & default Organization resolution, Partner slug routing pattern, Community legacy clarification |
| `docs/09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md` | T075.1 decisions (AD01-AD11), T075.x task breakdown corrigé, Documentation alignment note |
| `AGENTS.md` | Organisation Scoping Rule en tête de fichier — règle globale pour tous les agents |
| `CLAUDE.md` | Concepts Clarifiés T075.1 — tableau Partner/Organization/Loop/Community/Root domain/etc. |

### Anti-drift coherency check
Aucun document ne dit :
- Partner = Tenant ✓
- Loop = Tenant ✓ (les mentions sont dans des blocs "NOT:")
- root domain = tenantless pour les routes métier ✓
- /blog global Platform ✓
- /boucles = vrai concept Loop ✓
- Community comme nouveau vocabulaire produit ✓
- toutes les routes publiques sont globales ✓

### Règles alignées dans tous les documents
- Organization = Tenant.
- Partner ≠ Tenant.
- Loop ≠ Tenant.
- Community / community_id / current_community = legacy technique temporaire.
- Root domain n'est pas tenantless.
- /{feature} = Organization par défaut de la plateforme.
- /{partnerSlug}/{feature} = Organization partenaire.
- Public ≠ global.
- Toutes les features métier actuelles et futures Organization-scopées.
- /boucles = legacy à déprécier, pas le vrai concept Loop.

### Stats
- 10 fichiers modifiés
- 275 insertions, 6 suppressions
- Aucun code applicatif modifié
- Aucune migration
- Tests non exécutés (documentation-only)
- Prêt pour review OPENAI

## 2026-05-16 23:55:00 Europe/Paris

OPENAI review: REQUEST CHANGES.

Corrections appliquées :
- `ai/context/architecture.md` : `/dashboard` retiré du default Organization context. `/dashboard` est maintenant documenté comme route personnelle authentifiée qui résout l'Organization du user connecté.
- `docs/06-DOMAIN_ARCHITECTURE_V2.md` : `/admin/loops` retiré de la liste des routes root Organization-scopées. `/admin/*` est clarifié comme Platform global intentionnel, non Organization-scopé.
- `ai/context/routing-strategy.md` : `/dashboard` et `/services/{uuid}` ne sont plus décrits comme global non-scoped. Ils sont documentés comme routes métier root legacy/currently unsafe devant résoudre une Organization via T075.2.

Aucun code applicatif modifié.
Aucune migration.
Tests non exécutés car documentation-only.
Prêt pour validation COCKPIT finale.

## 2026-05-16 23:59:00 Europe/Paris

Finalisation.
- Status passé à DONE, lock UNLOCKED.
- COCKPIT validation : approved.
- Aucun code applicatif modifié.
- Prêt pour check-task.sh + finalize-task.sh.

## 2026-05-17 00:05:00 Europe/Paris

Merged into develop via merge-task.sh.
- Merge commit: 96b44a2 (--no-ff).
- Push develop: OK.
- CI triggered automatically (in_progress).
- TASK status: MERGED.

---

# Handoffs

Pending.

---

# Tests

- Aucun test applicatif attendu.
- Vérification documentaire seulement.
- `git diff --stat` obligatoire.
- Aucun fichier applicatif modifié.

---

# Test Results

Pending.

---

# Review Notes

**Status**: MERGED.
**Phase**: Documentation alignment — mergé dans develop.
**Code modified**: None.
**Database migrations**: None.
**PROD**: Not touched.
**main**: Not touched.
**OPENAI review**: REQUEST CHANGES → corrections appliquées → COCKPIT approved.
**CI**: Not applicable (documentation-only).
