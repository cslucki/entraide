---
task_id: TASK-108
title: T077.4 — Boucles Product Doctrine: Flux, Signaux, Journal

status: DONE

owner: GLM

contributors: []

branch: TASK-108-t077-4-boucles-product-doctrine-flux-signaux-journal

priority: MEDIUM

created_at: 2026-05-20 20:20:57 Europe/Paris
updated_at: 2026-05-21 08:12:30 Europe/Paris

labels: []

lock:
  status: UNLOCKED

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Stabiliser la doctrine produit Boucles Product: Flux, Signaux, Journal avant toute implémentation runtime.

Scope: Documentation only. Aucun code runtime.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes (documentation only)
- [ ] run tests (non requis - scope documentation only)
- [ ] validate UI (non requis - scope documentation only)

---
# Progress Log


## 2026-05-20 20:20:57 Europe/Paris

Task created.

Owner:
GLM

Branch:
TASK-108-t077-4-boucles-product-doctrine-flux-signaux-journal

Status:
IN_PROGRESS

## 2026-05-20 20:25:00 Europe/Paris

**Documentation créée:**

Fichier: `docs/boucles-product-doctrine.md`

**Décisions Produit:**

1. **Flux Vivant** = activité réelle, émergente, non hiérarchique
2. **Signaux** = patterns détectés dans le Flux, indicateurs de dynamiques
3. **Boucles** = contextes intentionnels pour l'énergie collective
4. **Journal** = mémoire narrative séquentielle d'une Boucle
5. **IA** = clarifier, relier, mémoriser (pas remplacer l'élan humain)

**Relation Causale:**
```
Flux → Signaux → Boucles → Journal
```

**Règles Anti-Dérive:**
- Flux ≠ Workflow
- Signaux ≠ Notifications
- Boucles ≠ Groupes de discussion
- Journal ≠ Logs
- Organization = Tenant
- Loop ≠ Tenant

**Implications pour Tâches Futures:**
- T077.5 (Visibility): Signaux respectent visibilité, Journal accessible selon visibilité
- T077.6 (Membership): Signaux calculés sur activités membres, Journal attribue aux membres
- T077.9 (Surface Runtime): Interface expose Flux vivant, Signaux subtils, Journal navigable
- T078.2 (Flux & Signaux Runtime): Implémente cette doctrine fidèlement

**Fichiers Modifiés:**
- `docs/boucles-product-doctrine.md` (CRÉÉ)

**Tests Non Requis (Justification):**
- Scope: Documentation only
- Aucun code runtime
- Aucune modification de app/, routes/, resources/, database/, config/
- Tests Playwright requis pour T078.2 (runtime implementation), pas T077.4

**Limites:**
- Hors scope runtime
- Aucun code, migration, modèle, route, vue, composant
- Aucun test Playwright ou PHPUnit
- Ne pas toucher ALPHA
- Ne pas toucher main / PROD
- Ne pas introduire de nouveau vocabulaire Community/current_community/ResolveCommunity
- Préserver Organization = Tenant
- Préserver Loop ≠ Tenant
- Préparer T078.2 pour implémentation fidèle

**Handoff vers Tâches Suivantes:**
- T077.5 (Boucles Visibility Rules): Utiliser cette doctrine pour définir règles visibilité
- T077.6 (Boucles Membership & Roles): Utiliser cette doctrine pour définir rôles membres
- T077.9 (Boucles Product Surface Runtime V1): Utiliser cette doctrine pour concevoir interface
- T078.2 (Boucles Flux & Signaux Runtime): Implémenter cette doctrine fidèlement sans dériver

**Statut:** DONE
**Lock:** UNLOCKED

Prêt pour review OPENAI.

# Handoffs

# Tests

- [x] feature tests (NON REQUIS - scope documentation only)
- [x] browser validation (NON REQUIS - scope documentation only)
- [x] responsive validation (NON REQUIS - scope documentation only)
- [x] console inspection (NON REQUIS - scope documentation only)
- [x] tenant validation (NON REQUIS - scope documentation only)

---

# Test Results

**NON APPLICABLE** - Scope documentation only.

Justification: T077.4 ne modifie aucun code runtime. Tests Playwright et PHPUnit requis pour T078.2 (Flux & Signaux Runtime), pas pour T077.4.

---

---

# Review Notes

**Pour Review OPENAI:**

1. **Vérifier Stabilité Conceptuelle:**
   - Concepts Flux, Signaux, Boucles, Journal cohérents?
   - Relations causales claires?
   - Rôles IA bien définis?

2. **Vérifier Anti-Dérive:**
   - Règles anti-dérive suffisantes?
   - Distinctions claires (Flux ≠ Workflow, Signaux ≠ Notifications, etc.)?
   - Tenant safety préservé?

3. **Vérifier Implications Futures:**
   - T077.5, T077.6, T077.9, T078.2 bien éclairées?
   - T078.2 peut implémenter fidèlement cette doctrine?
   - Aucun gap conceptuel?

4. **Vérifier Scope Documentation Only:**
   - Aucune modification runtime?
   - Aucun code, modèle, route, vue?
   - Tests justifiés comme non requis?

5. **Vérifier Vocabulaire:**
   - Pas de nouveau Community/current_community/ResolveCommunity?
   - Organization = Tenant bien établi?
   - Loop ≠ Tenant bien établi?

**Recommandé:** Approbation doctrine avant toute implémentation runtime (T078.2).

**Points de Réserve (si applicable):**
- [ ] Concepts instables
- [ ] Règles anti-dérive insuffisantes
- [ ] Implications futures floues
- [ ] Tenant safety compromis
- [ ] Vocabulaire dérive

**Statut Review:** PENDING OPENAI DOCUMENTARY AUDIT

## 2026-05-20 20:30:00 Europe/Paris

**Correction COCKPIT ROADMAP:**

T077.4 reste sur sa branche. Aucun merge. Aucune intervention CODE supplémentaire avant review OPENAI.

**Risque Identifié:**

La doctrine a été créée dans un nouveau fichier `docs/boucles-product-doctrine.md` sans audit préalable de la documentation existante.

Risques:
- Duplication documentaire possible
- Contradiction possible avec ROADMAP.md
- Contradiction possible avec ai/context/routing-strategy.md
- Absence de décision sur l'emplacement canonique de la doctrine
- Risque de créer une vérité documentaire parallèle

**Décision COCKPIT:**

T077.4 passe en attente de review documentaire OPENAI.

**Questions pour OPENAI (Documentary Audit):**

OPENAI doit auditer la documentation existante et répondre:

1. **Emplacement Canonique:**
   - `docs/boucles-product-doctrine.md` doit-il rester comme fichier canonique?
   - Certains passages doivent-ils être intégrés dans ROADMAP.md?
   - Certains passages doivent-ils être intégrés dans ai/context/routing-strategy.md?
   - Certains passages doivent-ils être intégrés dans docs existants?
   - Certains passages doivent-ils être intégrés dans TASK files?

2. **Contradictions Documentaires:**
   - Y a-t-il des contradictions avec ROADMAP.md?
   - Y a-t-il des contradictions avec ai/context/routing-strategy.md?
   - Y a-t-il des contradictions avec d'autres docs existants?

3. **Intégration Documentaire:**
   - CODE doit-il faire une passe d'intégration documentaire après review OPENAI?
   - Si oui, quelles actions spécifiques?

4. **Décision:**
   - Approver la doctrine telle quelle (fichier canonique: docs/boucles-product-doctrine.md)
   - Demander des ajustements avant approbation
   - Demander une intégration documentaire dans fichiers existants

**Statut OPS:** DONE + UNLOCKED
**Statut COCKPIT:** PENDING OPENAI DOCUMENTARY AUDIT
**Statut Global:** EN ATTENTE REVIEW DOCUMENTAIRE

**Action Requise:**
- OPENAI: Auditor la documentation existante et décider de l'emplacement canonique
- CODE: Aucune intervention supplémentaire avant décision OPENAI

## 2026-05-21 08:12:30 Europe/Paris

**Verdict OPENAI:** REQUEST CHANGES.

**Raison du verdict:**

- La doctrine T077.4 était utile mais présentée comme une documentation Boucles autonome.
- Risque de vérité documentaire parallèle avec `docs/specs/T077.0-boucles-product-surface-spec.md`, `docs/06-DOMAIN_ARCHITECTURE_V2.md` et `docs/07-GLOSSARY.md`.
- Contradiction active avec `ai/context/routing-strategy.md`, qui disait encore que `/boucles` était legacy à déprécier.

**Corrections appliquées:**

- Déplacé `docs/boucles-product-doctrine.md` vers `docs/specs/T077.4-boucles-flux-signaux-journal-doctrine.md`.
- Ajouté une section `Hiérarchie documentaire` en tête du document T077.4.
- Précisé que T077.4 consolide uniquement Flux / Signaux / Journal et ne remplace pas le canon global Boucles.
- Corrigé la formulation tenant: `Organization-scoped ; tenant boundary = Organization. Une Boucle n'est jamais une frontière tenant.`
- Ajouté une section `Décision /boucles` clarifiant l'ancien usage legacy et la route française canonique cible.
- Corrigé `ai/context/routing-strategy.md` pour indiquer que `/boucles` est désormais la route cible des vraies Boucles, Organization-scopée et fail-closed.
- Ajouté une note courte dans `docs/07-GLOSSARY.md` sur Flux, Signaux et Journal comme sous-concepts produit liés aux Loops / Interactions.

**Fichiers modifiés:**

- `docs/specs/T077.4-boucles-flux-signaux-journal-doctrine.md`
- `docs/boucles-product-doctrine.md` (supprimé par déplacement)
- `ai/context/routing-strategy.md`
- `docs/07-GLOSSARY.md`
- `TODO/TASK-108-t077-4-boucles-product-doctrine-flux-signaux-journal.md`

**Décisions et limites:**

- `ROADMAP.md` non modifié sans instruction explicite de Cyril.
- Aucun code runtime modifié.
- Aucun fichier `app/`, `routes/`, `resources/`, `database/`, `config/` modifié.
- Aucun merge.
- Aucun changement ALPHA, main ou PROD.
- Public ≠ Global préservé.
- Organization = Tenant préservé.
- Loop ≠ Tenant préservé.

**Validation effectuée:**

- `ai/scripts/check-task.sh` exécuté avant corrections: PASS.
- Vérification de présence de `Hiérarchie documentaire`: PASS.
- Vérification de présence de `Décision /boucles`: PASS.
- Vérification de correction tenant: PASS.
- Vérification déplacement fichier vers `docs/specs/`: PASS.

**Statut final:** DONE.

**Lock:** UNLOCKED.

**Handoff review:** prêt pour nouvelle review documentaire OPENAI après commit/push.
