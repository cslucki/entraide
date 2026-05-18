---
task_id: TASK-103
title: T077.0 — Boucles Product Surface Spec

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-103-t077-0-boucles-product-surface-spec

priority: MEDIUM

created_at: 2026-05-18 16:54:03 Europe/Paris
updated_at: 2026-05-18 17:31:37 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-05-18 17:31:37 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Rédiger la spec produit T077.0 — Boucles Product Surface Spec.

Périmètre strict:

- Documentation/spec only.
- Ne pas modifier `app/`, `routes/`, `resources/`, `database/`, `config/`.
- Ne pas coder.
- Ne pas créer de migration.
- Ne pas lancer de refactor.
- Ne pas modifier le runtime.
- Ne pas lancer ChatLoop.
- Ne pas ajouter d'IA.

Livrables:

- Compléter cette TASK.
- Créer `docs/specs/T077.0-boucles-product-surface-spec.md`.

Vocabulaire obligatoire:

- `Organization = Tenant`.
- `Loop ≠ Tenant`.
- `Partner ≠ Tenant`.
- `Member`.
- `Interaction`.
- `/boucles` = future surface française canonique des vraies Boucles.
- Aucune URL publique en anglais.
- `Community`, `community_id`, `current_community` uniquement pour documenter une dette legacy existante.

---

# Planned Actions

- [x] Inspecter la TASK existante.
- [x] Inspecter les documents produit / architecture pertinents.
- [x] Identifier le dossier de specs adapté.
- [x] Créer la spec produit T077.0.
- [x] Mettre à jour la TASK avec les décisions, tests et handoff.
- [x] Vérifier le diff documentation-only.

---
# Progress Log


## 2026-05-18 16:54:03 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-103-t077-0-boucles-product-surface-spec

Status:
IN_PROGRESS

## 2026-05-18 17:16:13 Europe/Paris

CODE / OpenCode a repris la TASK sur la branche `TASK-103-t077-0-boucles-product-surface-spec`.

Contexte inspecté:

- `TODO/TASK-103-t077-0-boucles-product-surface-spec.md`
- `docs/specs/T074.2-chatloop-center-ia-assisted-interactions.md`
- `docs/02-PRODUCT_PRINCIPLES.md`
- `docs/07-GLOSSARY.md`
- `docs/06-DOMAIN_ARCHITECTURE_V2.md`

Décision de périmètre:

- Travail limité à la documentation.
- Aucun fichier runtime modifié.
- Aucune route, migration, classe PHP, vue, config ou ressource applicative ajoutée.

Fichier créé:

- `docs/specs/T077.0-boucles-product-surface-spec.md`

Décisions produit documentées dans la spec:

- `/boucles` est la future surface française canonique des vraies Boucles.
- Aucune URL publique anglaise ne doit être introduite pour cette surface.
- Une Boucle est un contexte collaboratif interne à une Organization.
- Une Boucle n'est jamais un tenant.
- `Organization = Tenant`.
- `Loop ≠ Tenant`.
- `Partner ≠ Tenant`.
- `Community`, `community_id` et `current_community` restent uniquement une dette legacy technique existante.
- Le MVP T077.1 doit rester lecture/orientation, mobile-first, sans IA, sans ChatLoop et sans refactor runtime.

Questions ouvertes documentées:

- Faut-il afficher des exemples publics de Boucles aux visiteurs non connectés ?
- Qui peut créer ou proposer une Boucle: Organization Admin uniquement ou certains Members ?
- `Mes invités` doit-il rester une vraie Boucle système ou devenir une carte produit spéciale ?
- Le détail d'une Boucle en T078 doit-il prioriser ChatLoop ou une page d'activité structurée plus large ?

## 2026-05-18 17:18:06 Europe/Paris

Vérification finale effectuée:

- `git status --short --branch` confirme uniquement deux fichiers non suivis dans le périmètre autorisé.
- Fichiers concernés: la TASK et la spec `docs/specs/T077.0-boucles-product-surface-spec.md`.
- Aucun fichier `app/`, `routes/`, `resources/`, `database/` ou `config/` modifié.
- Tâche non finalisée, non mergée, non commitée conformément à la demande.

## 2026-05-18 17:31:37 Europe/Paris

Review OPENAI intégrée: APPROVE WITH NOTES, aucun blocking issue.

Micro-ajustements documentaires appliqués dans `docs/specs/T077.0-boucles-product-surface-spec.md`:

- clarification du rôle visiteur: comprendre et consulter les informations publiques;
- clarification du rôle Member: consulter ses Boucles;
- clarification du rôle Organization Admin: gérer les Boucles selon ses droits;
- clarification T077.1: aucune création de Boucle incluse sauf décision produit séparée.

Statut passé à DONE et lock passé à UNLOCKED pour finalisation OPS.

# Handoffs

## 2026-05-18 17:16:13 Europe/Paris

Handoff vers T077.1:

- Implémenter seulement après validation produit de cette spec.
- Garder `/boucles` comme surface française canonique.
- Préserver le scoping Organization.
- Ne pas introduire `/loops` comme URL publique produit.
- Ne pas transformer la surface Boucles en Slack, WhatsApp, chatbot ou marketplace type Malt.

Handoff vers T078 ChatLoop:

- ChatLoop doit être traité comme une expérience à l'intérieur d'une Boucle, pas comme la définition de la Boucle.
- Les Interactions restent structurées, Organization-scopées et validées côté serveur.
- L'IA éventuelle doit rester une aide à l'action, pas une conversation libre par défaut.

# Tests

- [x] Documentation review
- [x] Scope review documentation-only
- [x] Diff review documentation-only
- [ ] feature tests — N/A documentation-only
- [ ] browser validation — N/A documentation-only
- [ ] responsive validation — N/A documentation-only
- [ ] console inspection — N/A documentation-only
- [ ] tenant validation — N/A documentation-only

---

# Test Results

- Laravel Boost application info inspected.
- No automated tests run: T077.0 is documentation-only and must not modify runtime.
- `git status --short --branch` exécuté: uniquement `TODO/TASK-103-t077-0-boucles-product-surface-spec.md` et `docs/specs/T077.0-boucles-product-surface-spec.md` apparaissent comme fichiers non suivis.

---

# Review Notes

- Spec created under `docs/specs/`, matching existing documentation structure.
- Scope respected so far: only `TODO/` and `docs/specs/` touched.
- T077.0 intentionally does not finalize the task, merge, implement code, create migrations, add IA, or start ChatLoop.
- OPENAI review: APPROVE WITH NOTES, no blocking issue.
- Notes addressed by documentation-only clarifications on visitor, Member, Organization Admin, and T077.1 no-creation scope.
