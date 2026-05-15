---
task_id: TASK-074.2
title: Product Spec ChatLoop Center IA assisted interactions

status: MERGED

owner: OPENCODE

contributors: []

branch: T074.2-t074-2-product-spec-chatloop-center-ia-assisted-interactions

priority: MEDIUM

created_at: 2026-05-15 12:24:22 Europe/Paris
updated_at: 2026-05-15 14:10:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Rediger la Product Spec T074.2 pour ChatLoop Center + IA-assisted Interactions, a partir de T074.0, T074.1, T074.1A, T074-assets-index, et des regles produit/architecture.

Documentation/spec only. Aucun code, migration, package, Reverb ou OpenAI.

---

# Planned Actions

- [x] create T074.2 branch via create-task.sh
- [x] create docs/audits/T074-assets-index.md
- [x] create docs/specs/T074.2-chatloop-center-ia-assisted-interactions.md
- [x] initialiser skeleton de la spec
- [x] lire tous les fichiers sources (audits, docs produit, architecture, glossaire)
- [x] rediger la spec complete avec toutes les sections requises
- [x] mettre a jour le TASK file
- [x] verifier que seuls les fichiers autorises sont modifies
- [x] OPENAI review corrections: .gitignore unstaged, Mes invites nuance, OrgAdmin beta/post-beta, Loop isolation nuance, fallback wording, CTA distinction

---

# Progress Log

## 2026-05-15 12:24:22 Europe/Paris

Task created.

## 2026-05-15 12:26:00 Europe/Paris

Created docs/audits/T074-assets-index.md:
- documented purpose of T074 visual assets
- documented role of T074.1-assets and T074.1A-assets
- listed 4 canonical screens for T074.2
- added rules and T74-MASTER current decision

## 2026-05-15 12:28:00 Europe/Paris

Created docs/specs/T074.2-chatloop-center-ia-assisted-interactions.md skeleton:
- status, purpose, sources, constraints
- .gitignore pre-existing, not staged

## 2026-05-15 12:45:00 Europe/Paris — 13:20:00 Europe/Paris

Redaction complete de la spec T074.2.

## 2026-05-15 14:00:00 Europe/Paris

Corrections ciblees post-review OPENAI appliquees:

1. **.gitignore unstaged** — `git restore --staged .gitignore` execute. Fichier desormais non stage (M local preserve).
2. **Critere d'acceptation** — "TASK file a jour" coche.
3. **Mes invites** — section enrichie: Organization-scoped, cross-tenant interdit, Members meme Organization uniquement, non supprimable mais masquable/sourdine, pas de pression relationnelle.
4. **OrgAdmin beta/post-beta** — section Creation de Loop (beta) corrigee: creation possible via OrgAdmin retiree, remplacee par Loops systeme/preconfigurees + report T074.8.
5. **Loop isolation nuance** — "Loop ne porte pas l'isolation de securite" remplace par: LoopMember est frontiere d'autorisation applicative, Organization reste frontiere tenant.
6. **Fallback wording** — "Questions de clarification supplementaires" remplace par "Etape de clarification bornee".
7. **CTA distinction** — "Continuer / Publier cette demande" separe en deux etapes distinctes: "Valider et continuer" (validation draft) → "Publier cette demande" (action finale). Composants, etats et CTA mis a jour.
8. **Role IA fallback** — wording aligne sur "etape de clarification bornee".

Fichiers lus:
- AGENTS.md (complet — multi-agent orchestration, task lifecycle, vocabulary rules)
- CLAUDE.md (complet — project overview, migration context, runtime tenant resolution)
- docs/01-UI_RULES.md (complet — UI philosophy, mobile-first, conversational UX, dark mode)
- docs/02-PRODUCT_PRINCIPLES.md (complet — git workflow, testing, AI architecture, product philosophy)
- docs/03-COMPONENT_LIBRARY.md (lu — contenu: TO DO uniquement)
- docs/04-ENGINEERING_RULES.md (complet — layout, empty states, loading, icons, dashboard philosophy)
- docs/06-DOMAIN_ARCHITECTURE_V2.md (complet — vision, platform hierarchy, Organization=Tenant, Loop≠Tenant)
- docs/07-GLOSSARY.md (complet — vocabulary stabilization, legacy mapping, forbidden synonyms)
- docs/08-COMMUNITY_MIGRATION_STRATEGY.md (complet — migration philosophy, phases, anti-patterns)
- docs/audits/T074.0-technical-audit-current-messaging-mobile-reverb-readiness.md (complet — file map, mobile risks, Reverb readiness)
- docs/audits/T074.1-ux-chatloop-mobile-desktop-admin.md (complet — navigation validee, parcours IA, ecrans OrgAdmin)
- docs/audits/T074.1A-ia-solution-spike-chatloop-assisted-interactions.md (complet — contrat JSON, FakeAIProvider, fallback, Lab IA)
- docs/audits/T074-assets-index.md (relu — canonical screens, image rules)
- docs/specs/T074.2-chatloop-center-ia-assisted-interactions.md (skeleton existant, complete)
- TODO/TASK-074-t074-2-product-spec-chatloop-center-ia-assisted-interactions.md (existant, mis a jour)

Fichiers verifies (assets):
- docs/audits/T074.1A-assets/000-Mesboucles_Black.png (existe)
- docs/audits/T074.1A-assets/01-qui-peut-maider-reference.png (existe)
- docs/audits/T074.1A-assets/02-demande-clarifiee-reference.png (existe)
- docs/audits/T074.1A-assets/00-reseautage-reference.png (existe)

Decisions T74-MASTER integrees:
1. ChatLoop devient le centre vivant de BouclePro
2. Navigation mobile: Boucles · Echanges · Objectifs · Actus
3. Phrase coeur: intention floue → demande structuree → bonne boucle
4. "Qui peut m'aider ?" = Interaction structuree, pas message libre
5. IA clarifie/reformule/oriente/resume/aide a agir — ne publie pas
6. IA ≠ chatbot gadget
7. Mes invites = Loop system liee au parrainage
8. Loops = mini-reseaux de coordination
9. OrgAdmin officialise les Loops plus tard
10. Reverb-ready avec fallback polling, pas Reverb en T074.2

Structure de la spec (toutes les sections obligatoires presentes):
- Resume executif ✓
- Vision produit ✓
- Concepts stabilises ✓
- Navigation cible ✓
- Parcours coeur "Qui peut m'aider ?" ✓
- Role exact de l'IA ✓
- Contrat produit IA ✓
- UX Implementation Brief (4 ecrans) ✓
- Mes invites comme Loop system ✓
- Loops comme mini-reseaux de coordination ✓
- OrgAdmin (post-beta) ✓
- Reverb-ready + fallback polling ✓
- Priorisation beta / post-beta ✓
- Non-objectifs explicites ✓
- Risques et mitigations ✓
- Preparation des taches suivantes (T074.3 a T074.11) ✓
- Criteres d'acceptation T074.2 ✓

---

# Handoffs

None. Task complete, no handoff needed.

---

# Tests

- [x] documentation only (no code tests required)

Verification validation:
- [x] .gitignore unstaged (git restore --staged) — reste modifie localement hors scope
- [x] docs/audits/T074-assets-index.md present et attendu (fichier nouveau, cree precedemment)
- [x] docs/specs/T074.2-chatloop-center-ia-assisted-interactions.md nouveau ou modifie selon l'etat Git
- [x] TODO/TASK-074-t074-2-product-spec-chatloop-center-ia-assisted-interactions.md mis a jour
- [x] aucun app/ modifie
- [x] aucun resources/ modifie
- [x] aucun routes/ modifie
- [x] aucun database/ modifie
- [x] aucun composer.json/package.json modifie

---

# Test Results

Etat Git reel (fichiers non trackes presents, git diff ne les voit pas):

git status --short:
 M .gitignore (pre-existing, unstaged via git restore --staged, hors scope)
 ?? TODO/TASK-074-t074-2-product-spec-chatloop-center-ia-assisted-interactions.md
 ?? docs/audits/T074-assets-index.md
 ?? docs/specs/

Note: docs/specs/ contient la spec T074.2. TODO/ et docs/audits/ sont non trackes.
.gitignore a ete unstaged avec git restore --staged — local modification preservee.

Fichiers attendus:
1. TODO/TASK-074-t074-2-product-spec-chatloop-center-ia-assisted-interactions.md ✓
2. docs/audits/T074-assets-index.md ✓ (nouveau, attendu)
3. docs/specs/T074.2-chatloop-center-ia-assisted-interactions.md ✓ (nouveau, attendu)

---

# Review Notes

## Points d'arbitrage

1. **docs/03-COMPONENT_LIBRARY.md** — contient "TO DO" uniquement. Aucun composant reutilisable n'est encore documente. Spec preparee pour s'y referer une fois le fichier complete.

2. **Navigation mobile** — la spec reprend la navigation validee "Boucles · Echanges · Objectifs · Actus" de T074.1. Aucun ecart.

3. **Assets references** — les 4 images canoniques de T074.1A-assets sont bien presentes et referencees. Aucun code n'a ete infere des images. Chaque ecran est converti en composants, etats, donnees et criteres.

4. **Contrat IA** — le contrat JSON de T074.1A est integre comme contrat produit stable. Le format exact n'est pas impose pour T074.2, seuls les champs sont stables.

5. **Scope** — la spec couvre exactement le perimetre demande. Aucune derivation vers T074.3, aucun code, aucune migration.

## OPENAI Review

**Review: PASS WITH CHANGES** — corrections ciblees appliquees.

Corrections appliquees:
1. .gitignore unstaged (git restore --staged)
2. Critere d'acceptation "TASK file a jour" coche
3. Mes invites: Organization-scoped, masquable, pas de pression relationnelle
4. OrgAdmin beta: creation via OrgAdmin retiree, Loops preconfigurees, report T074.8
5. Loop isolation nuancee: LoopMember = frontiere applicative, Organization = tenant
6. Fallback: "etape de clarification bornee"
7. CTA: "Valider et continuer" ≠ "Publier cette demande" (deux etapes distinctes)

## Conformite

- Organization = Tenant: explicite et repete
- Loop ≠ Tenant: explicite et repete
- Community/community_id legacy: accepte comme dette temporaire
- Aucun app/ modifie ✓
- Aucun resources/ modifie ✓
- Aucun routes/ modifie ✓
- Aucun database/ modifie ✓
- Aucun package installe ✓
- Aucun Reverb ✓
- Aucun OpenAI reel ✓
- .gitignore unstaged (git restore --staged, local mod preservee) ✓

## Prochaine etape recommandee

Validation finale T074.2, puis ouverture de T074.3.

## Final validation

T074.2 validated after OPENAI PASS WITH CHANGES and CODE targeted corrections.
