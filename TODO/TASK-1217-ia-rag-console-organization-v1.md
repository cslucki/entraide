---
task_id: TASK-1217
title: IA RAG Console Organization V1

status: DONE

owner: OPUS

contributors: []

branch: TASK-1217-ia-rag-console-organization-v1

# validation_level: MICRO_UX | STANDARD | SENSITIVE | NO_APP_CI
# Definitions courtes -> AGENTS.md, section "Validation Levels".
validation_level: SENSITIVE

priority: MEDIUM

created_at: 2026-08-16 19:31:29 Europe/Paris
updated_at: 2026-08-16 19:31:29 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPUS
  since: 2026-08-16 19:31:29 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Console RAG Organization V1, read-only : permettre à un Admin Organization de
comprendre « quelles connaissances BouclePro peut utiliser dans mon
Organization, qu'est-ce qui est indexé, et est-ce que le RAG est sain ? ».

Page `organization.admin.ai-knowledge` (« Mes connaissances IA ») : résumé,
état par source (Article et fichier TXT/Markdown), diagnostics techniques en
second plan. Aucune écriture, aucune réindexation, aucun appel provider.

Hors scope strict : SuperAdmin, consommation IA, pricing embeddings, crédits,
PDF/DOCX/OCR, HNSW/IVFFlat, recherche hybride, mémoire, prompt governance,
refonte `dossier_chunks`, flake TASK1209, quota DossierFileController.

---

# Planned Actions

## Audit (fait)
- [x] Zone admin Organization : routes `organization.admin.*` (préfixe
      `admin`, middleware `OrgAdminMiddleware`), contrôleur unique
      `OrgAdminController`, section IA existante (`ai`, `ai-supervision`,
      `member-ai-profiles`, `ai-interactions`), navigation
      `layouts/org-admin.blade.php` groupe `$orgIaItems`
- [x] Conventions UI : Blade pur + `<x-org-admin-layout>`, tableau markup
      répété (aucun composant réutilisable), convention `—` déjà établie
      pour la valeur absente
- [x] Permissions : `OrgAdminMiddleware` (admin_id OU is_admin global),
      bornage tenant par route model binding + `where('organization_id')`
      explicite
- [x] **Décision architecture** : console V1 faisable en requêtes/read
      models locaux — aucune refonte `dossier_chunks`, aucune migration,
      aucune nouvelle architecture de permissions, aucun changement
      `ContextBuilder`/pipeline RAG. **FABLE non nécessaire.**
- [x] **États non déterminables identifiés** (voir Review Notes) :
      `pending`, `erreur par source`, `périmé` — volontairement non
      affichés

## Implémentation
- [x] `OrganizationRagOverview` : read model dédié (summary / sources /
      diagnostics), tout borné par `organization_id`
- [x] `OrgAdminController::aiKnowledge()` : policy `view` appliquée ligne
      par ligne pour le lien « Ouvrir » (portée ≠ sujet)
- [x] Route `organization.admin.ai-knowledge` + entrée navigation dans le
      groupe IA existant
- [x] Vue `admin/org/ai-knowledge.blade.php` : résumé, tableau des
      sources, `<details>` diagnostics en second plan
- [x] Traductions FR/EN alignées (149 clés chacun, 31 nouvelles)

## Tests / validation
- [x] 12 tests ciblés (tenant, permissions, états, provenance,
      diagnostics) verts sur SQLite ET PostgreSQL réel
- [x] Régression ciblée : SQLite 586 verts / PostgreSQL 582 verts
- [x] Playwright WSL (console, isolation tenant, responsive, 0 erreur)
- [x] Chrome spot-check (rendu, diagnostics, 0 erreur console)
- [x] Pint scoped + git diff --check
- [x] bump VERSION, DONE/UNLOCKED, check-task, finalize-task
- [ ] **PRE-PR REVIEW** : push branche seul, PAS de PR, PAS de CI — MASTER
      inspecte le diff GitHub avant création de PR

---
# Progress Log


## 2026-08-16 19:31:29 Europe/Paris

Task created.

Owner:
OPUS

Branch:
TASK-1217-ia-rag-console-organization-v1

Status:
IN_PROGRESS

## 2026-08-16 — Implémentation console V1 (OPUS)

### Architecture retenue
Read model dédié `OrganizationRagOverview` (summary / sources /
diagnostics) plutôt que des requêtes dans le contrôleur : testable
isolément, et la frontière est nette entre « calculer l'état » (le read
model, borné par `organization_id`) et « décider ce qu'on a le droit
d'ouvrir » (le contrôleur, via `DossierPolicy` ligne par ligne).

Aucune migration, aucun changement de schéma, aucune modification du
pipeline RAG. La console lit ce qui existe déjà.

### Doctrine « ne jamais inventer un statut »
Trois états ont été **délibérément écartés** faute de preuve fiable :
- **`pending`** : `jobs`/`job_batches` sont transitoires (la ligne
  disparaît au traitement) et ne portent ni `organization_id` ni
  `source_id` exploitable. Aucun statut persistant sur `DossierFile` /
  `BlogPost`.
- **`erreur` par source** : `AdminAiInteraction` trace bien
  `status='failed'`, mais `RecordSdkEmbeddingsInvocation::recordFailure()`
  ne reçoit que provider+model — le rattachement à UNE source précise
  n'est pas garanti. Affirmer « cette source est en erreur » serait une
  supposition.
- **`périmé`** : comparer `content_hash` exigerait de ré-extraire et
  re-chunker chaque source à l'affichage (lecture disque pour les
  fichiers). Pas déterminable par requête.

Ce qui reste est démontrable : `indexé` / `non indexé` (présence de
lignes dans `dossier_chunks`), compteurs, provider/modèle réels, mismatch
de famille d'embedding (plusieurs familles dans un même index = les
vecteurs ne se comparent plus, c'est un fait). L'inconnu s'affiche `—`.

### Portée ≠ sujet
Vérifié dans `DossierPolicy` : `admin_id` n'y apparaît nulle part. Un
Admin Organization n'hérite donc d'aucun droit de lecture sur un Dossier
privé. La console montre l'**état** de l'index de ce Dossier (il existe,
il est indexé, il pèse N extraits) sans donner accès au **contenu** : le
lien « Ouvrir » n'est rendu que si `Gate::allows('view', $dossier)` passe
réellement pour cet utilisateur.

### Écart de critère relevé (non corrigé, hors scope)
`DossierArticleIndexer` retient un Article via le scope `published()`
(qui inclut `listed_in_blog`), alors que le SQL de
`DossierSemanticSearchService` ne filtre pas sur `listed_in_blog`. La
console décrit l'**ingestion**, elle s'aligne donc sur l'indexeur. Cet
écart existait avant cette TASK et n'est pas de son ressort.

Status:
DONE

# Handoffs

# Tests

- [x] feature tests (12 TASK1217RagConsoleTest, verts SQLite + PostgreSQL réel)
- [x] browser validation (Playwright WSL + Chrome spot-check)
- [x] responsive validation (390px : aucun débordement horizontal)
- [x] console inspection (0 erreur console, 0 page error, 0 requestfailed,
      0 HTTP >= 400 inattendu)
- [x] tenant validation (compteurs, sources et diagnostics strictement
      cloisonnés ; vérifié aussi en réel entre ArtSciLab et org-a)

---

# Test Results

**Tests ciblés — 12/12 verts sur les deux drivers** (`TASK1217RagConsoleTest`) :
tenant strict (compteurs + sources + diagnostics), permissions (membre non
admin → 403, admin d'une autre Organization → 403), portée ≠ sujet (état
d'un Dossier privé visible, `can_open` false), source éligible sans chunk →
« non indexé » sans valeur inventée, fichiers TXT/MD listés, PDF non
présenté comme source, brouillon non éligible, aucun path disque ni
credential dans le HTML, diagnostics reflétant les providers réels et
signalant un vrai mismatch de famille.

**Régression ciblée** (`Dossier|TASK121[3-7]|TASK1200|OrgAdmin`) :
- SQLite : **586 verts**, 12 échecs = liste connue pgvector (inchangée)
- PostgreSQL réel : **582 verts**, 2 échecs quota pré-existants
  (`DossierFileTest`, bug `FOR UPDATE`+agrégat TASK-1131, hors scope)

**Playwright WSL** : console atteignable, 29 sources listées (11 Articles
indexés + 18 fichiers non indexés), diagnostics repliés par défaut puis
ouverts, aucun path/credential exposé, 29 liens « Ouvrir », responsive
390px sans débordement (`body=390 viewport=390`), 0 erreur navigateur.

**Isolation tenant vérifiée en réel** : ArtSciLab affiche 16 Folders /
11 Articles / 18 Files / 11 indexés ; org-a affiche 1 Dossier / 1 Article /
0 Fichier / 0 indexé / « Dernière indexation — » et **aucun** titre
ArtSciLab. Les deux locales rendues correctement (EN pour ArtSciLab, FR
pour org-a).

**Chrome spot-check** : rendu desktop conforme, sources et états lisibles,
`—` affiché pour les valeurs absentes, panneau diagnostics ouvert par vrai
clic, note honnête affichée, 0 erreur console.

**Pint scoped** : PASS. **git diff --check** : PASS.

---

# Review Notes

**Ce que la console montre** : un résumé (Dossiers, Articles, Fichiers,
sources indexées, extraits, dernière indexation), une ligne par source
éligible avec son état réel, et un panneau « Diagnostics techniques »
replié par défaut (extraits, sources distinctes, famille/modèle
d'embedding, providers enregistrés, alerte si plusieurs familles
cohabitent).

**Ce qu'elle ne montre pas, volontairement** : `pending`, `erreur par
source`, `périmé` — non déterminables sans inventer (justification
détaillée dans le Progress Log). Une note explicite le dit à l'écran :
« Seuls les états démontrables sont affichés. Une source sans extrait
apparaît "non indexée" : cela ne signifie pas qu'elle est en erreur. »

**Comportement à connaître (existant, non introduit ici)** : un
utilisateur `is_admin` global accède à la console de n'importe quelle
Organization — c'est le comportement de `OrgAdminMiddleware`, partagé par
**toutes** les pages admin Organization, pas propre à cette page. Les
données restent strictement cloisonnées : vérifié en réel, un admin
global consultant la console d'org-a n'y voit aucune donnée ArtSciLab.

**Dettes constatées, non corrigées (hors scope)** :
- `DossierFileController:159` : `lockForUpdate()->sum()` échoue sur
  PostgreSQL (`FOR UPDATE` + agrégat), pré-existant TASK-1131
- `TASK1209ContextBuilderTest` : flake d'isolation de suite, pré-existant
- écart `listed_in_blog` entre indexeur et retrieval (voir Progress Log)

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- Run `ai/scripts/bump-version.sh` on the task branch BEFORE `finalize-task.sh`
- `merge-task.sh` verifies VERSION format but does NOT bump it
- Footer always displays `config('app.version')`
