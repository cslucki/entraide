---
task_id: LT-003
title: Document Root Domain Default Organization Resolution

status: DONE

owner: OPENCODE

contributors: []

branch: LT-003-document-root-domain-default-organization-resolution

priority: LOW

created_at: 2026-05-16 14:55:00 Europe/Paris
updated_at: 2026-05-16 14:55:00 Europe/Paris

labels:
  - documentation
  - architecture
  - domain
  - tenant

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-16 14:55:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# LT-003 — Document Root Domain Default Organization Resolution

## Objective

Documenter explicitement la règle d'architecture suivante :

> Le domaine racine (`test.laravel` en dev / `bouclepro.com` en production) **n'est pas hors tenant**.
>
> Il doit résoudre une **Organization par défaut** ou **rediriger vers une route Organization-scopée canonique**.

Aujourd'hui, cette règle n'est documentée ni dans `ai/context/architecture.md` ni dans `docs/06-DOMAIN_ARCHITECTURE_V2.md`. Aucune décision architecturale formelle (ADR) n'existe sur ce sujet.

---

## Constat

- Aucune mention de résolution de domaine racine dans les docs d'architecture actuelles
- Le comportement attendu pour `bouclepro.com/` (sans sous-domaine ni chemin `/org/{slug}`) n'est pas spécifié
- Le comportement attendu pour `test.laravel/` (dev) n'est pas spécifié
- Risque : traiter le domaine racine comme "hors tenant" créerait une brèche de sécurité et une incohérence architecturale

---

## Règle Architecture

```
Organization = Tenant
```

Conséquence :

1. Tout accès au domaine racine doit être traité dans un contexte tenant (Organization)
2. Deux options canoniques :
   a. **Redirection** : `bouclepro.com` → `bouclepro.com/org/{default-org-slug}` (route Organization-scopée)
   b. **Résolution interne** : le domaine racine résout une Organization par défaut (ex. page d'accueil publique avec résolution implicite)
3. Aucune fonctionnalité ne doit être accessible "hors tenant" sur le domaine racine
4. La règle s'applique à tous les environnements (dev, staging, production)

---

## Fichiers visés

- `ai/context/architecture.md` — ajouter section "Root Domain Resolution"
- `docs/06-DOMAIN_ARCHITECTURE_V2.md` — ajouter section ou annexe sur la résolution du domaine racine
- `@DOCS/architecture/ADR-root-domain-default-organization.md` — créer l'ADR si le dossier `@DOCS/architecture/` existe ou doit être créé

---

## Contraintes

- Documentation only
- Pas de code
- Pas de migration DB
- Pas de migration Community → Organization
- Pas de modification sur T074.10
- Préserver Organization = Tenant
- Préserver Loop ≠ Tenant
- Community / community_id / current_community = legacy technique temporaire, à documenter comme tel

---

## Précondition vérifiée

T074.11 — `status: MERGED`, `lock: UNLOCKED` ✅

---

## Dépendances

- Aucune (tâche documentation pure)

---

# Progress Log

## 2026-05-16 14:55:00 Europe/Paris

Task created.

Owner: OPENCODE
Branch: LT-003-document-root-domain-default-organization-resolution
Status: IN_PROGRESS

Locked to OPENCODE.

Précondition T074.11 vérifiée : MERGED / UNLOCKED.

Prochaine étape : implémenter la documentation dans les fichiers visés.

---

## 2026-05-16 16:55:00 Europe/Paris

### Documentation implemented

1. `ai/context/architecture.md` — Added "Root Domain Resolution" section after "Multi-Tenant First"
   - Rule: root domain is not tenantless
   - Resolution strategy (internal vs redirect)
   - Guard state documentation
   - Critical rules (Loop ≠ Tenant, no business routes outside Organization)

2. `docs/06-DOMAIN_ARCHITECTURE_V2.md` — Added section 9.5 "Root Domain Resolution"
   - Rule, rationale, resolution strategy, guard state
   - List of affected business routes
   - All environments rule

3. `ai/decisions/ADR-002-root-domain-default-organization.md` — Created ADR
   - Contexte, Décision, Conséquences
   - Hors scope clairement défini
   - Impact T75/T76 documenté
   - Related documents references

### Constraints respected

- No code modified
- No DB migration
- No Community → Organization migration
- No modification on T074.10
- Organization = Tenant preserved
- Loop ≠ Tenant preserved
- Community/community_id documented as legacy technical only
- No new product vocabulary introduced

### Status

Status: DONE
Lock: UNLOCKED
Handoff to T75/T76: documented in ADR-002

---

# Handoffs

## 2026-05-16 16:55:00 Europe/Paris

ADR-002 documents the impact on T75 (Organization resolution middleware) and T76 (admin route Organization context).

No code change required by LT-003. Handoff is architectural only.

T75: Take ADR-002 resolution strategy as foundation when implementing root domain Organization resolution.
T76: Take ADR-002 guard state rules when implementing admin routes without Organization context.

---

# Tests

N/A — Documentation only task.

---

# Test Results

N/A.

---

# Review Notes

## 2026-05-16 16:55:00 Europe/Paris

- Rule documented in three locations (architecture.md, DOMAIN_ARCHITECTURE_V2.md, ADR-002)
- All constraints respected (no code, no migration, no vocabulary drift)
- Organization = Tenant and Loop ≠ Tenant preserved
- Community terminology kept as legacy technical only
- Impact on T75/T76 documented in ADR-002

---

# Modified Files

- `ai/context/architecture.md` — Added "Root Domain Resolution" section
- `docs/06-DOMAIN_ARCHITECTURE_V2.md` — Added section 9.5 "Root Domain Resolution"
- `ai/decisions/ADR-002-root-domain-default-organization.md` — Created new ADR
- `TODO/LOW/LT-003-document-root-domain-default-organization-resolution.md` — Updated status DONE, lock UNLOCKED
