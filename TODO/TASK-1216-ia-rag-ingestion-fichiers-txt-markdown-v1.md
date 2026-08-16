---
task_id: TASK-1216
# NOTE : create-task.sh a d'abord attribue par erreur l'ID TASK-1215 (deja
# pris par la TASK OPS tooling TASK-1215-ops-adapter-merge-task-sh-au-
# develop-prot-g-par-pr, invisible au scan car ce fichier n'existe que sur
# cette autre branche, absente du detache origin/develop d'ou create-task.sh
# a ete lance). Corrige manuellement en TASK-1216 apres audit confirmant cet
# ID reellement libre (aucun TASK file, aucune branche, aucune PR).
title: IA RAG ingestion fichiers TXT Markdown V1

status: DONE

owner: OPUS

contributors: []

branch: TASK-1216-ia-rag-ingestion-fichiers-txt-markdown-v1

# validation_level: MICRO_UX | STANDARD | SENSITIVE | NO_APP_CI
# Definitions courtes -> AGENTS.md, section "Validation Levels".
validation_level: SENSITIVE

priority: MEDIUM

created_at: 2026-08-16 17:10:52 Europe/Paris
updated_at: 2026-08-16 18:15:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPUS
  since: 2026-08-16 18:15:00 Europe/Paris

handoff: false

pr:
  status: OPEN_DRAFT
  url: https://github.com/cslucki/entraide/pull/220
---

# Objective

Permettre qu'un fichier TXT/Markdown déposé dans un Dossier devienne une
source RAG exploitable exactement comme un Article (TASK-1213/1214) :
extraction locale déterministe → chunking existant → embeddings via
l'instance P4 de l'Organization (jamais de fallback plateforme, contrat
TASK-1214) → pgvector → `dossier.retrieval` → Ask the Folders → source
ouvrable. Aucune console dans cette TASK (Phase 2).

Hors scope strict : PDF/DOCX/OCR, console RAG Organization/SuperAdmin,
consommation IA, pricing embeddings, User BYOK, legacy migration.

---

# Planned Actions

## Audit (fait, résultat ci-dessous)
- [x] Modèle fichier : `App\Models\DossierFile` existe déjà intégralement
      (upload/CRUD/policy/MIME TXT+MD déjà acceptés). Aucun observer/event
      câblé pour l'indexation RAG (contrairement à Article) — à construire.
- [x] `dossier_chunks` : `blog_post_id` NOT NULL + FK stricte, aucune
      colonne polymorphique. Contrainte unique
      `[dossier_id, blog_post_id, chunk_index, embedding_provider, embedding_model]`.
- [x] `DossierArticleIndexer` : spécifique à `blog_post_id`, style "une
      classe par type de source" (pas de polymorphisme) — pattern à
      répliquer, pas à généraliser.
- [x] `DossierSemanticSearchService` : INNER JOIN `blog_posts` filtré
      `published` — un chunk `blog_post_id=NULL` serait aujourd'hui
      invisible (pas de casse, pas de découverte sans extension).
- [x] Policies : `DossierRetrievalSource::accessibleDossierIds()` filtre
      déjà par `Gate::allows('view', $dossier)` au niveau Dossier, avant
      toute recherche — une source fichier hérite automatiquement de la
      même protection tenant sans policy supplémentaire.
- [x] **Décision architecture (pas de refonte, FABLE non nécessaire)** :
      migration additive mineure (`blog_post_id` nullable + nouvelle
      colonne `dossier_file_id` nullable FK `dossier_files`) + nouveau
      service parallèle `DossierFileIndexer` (clone structurel de
      `DossierArticleIndexer`, celui-ci reste intouché — zéro risque de
      régression TASK-1214) + extension ciblée de
      `DossierSemanticSearchService` (LEFT JOIN parallèle `dossier_files`,
      condition OR, COALESCE titre/url).

## Implémentation
- [ ] Migration : `dossier_chunks.blog_post_id` nullable, ajouter
      `dossier_file_id` (nullable, FK `dossier_files` cascade delete),
      ajuster la contrainte unique pour couvrir les deux sources
- [ ] `FileContentExtractor` : extraction locale déterministe TXT/MD, sans
      LLM, jamais d'exécution du contenu Markdown ; gère vide/encodage
      invalide/binaire déguisé/MIME incompatible/taille excessive →
      diagnostic propre, aucun chunk partiel
- [ ] `DossierFileIndexer::synchronize()` : même doctrine que
      DossierArticleIndexer (alreadyIndexed hash+famille, instance P4
      tenant via ProviderResolver, sans P4 → deleteChunks, échec embed
      après changement → deleteChunks + rethrow)
- [x] Observers/events sur `DossierFile` (create/update/delete/detach) →
      job d'indexation asynchrone (afterCommit), miroir du pattern
      Article
- [x] `DossierSemanticSearchService` : LEFT JOIN `dossier_files` parallèle
      à `blog_posts`, condition
      `(blog_post_id IS NOT NULL AND published) OR (dossier_file_id IS NOT NULL)`,
      COALESCE titre/slug/url selon la source — scoping tenant identique
      sur les deux branches
- [x] Provenance Ask the Folders : source lisible filename + Dossier +
      Open pour les chunks fichier

## Tests / validation
- [x] tests ciblés SQLite (safe-test.sh) + PostgreSQL/pgvector réel ciblé
- [x] régression TASK-1213 (retrieval) + TASK-1214 (ingestion Article)
- [x] Playwright WSL (TXT + Markdown + lifecycle + cross-tenant)
- [x] Chrome spot-check final (5-10 min)
- [x] Pint scoped + git diff --check
- [x] bump VERSION (1.160), DONE/UNLOCKED, check-task PASS, finalize-task
      (push `816b0cc`), PR #220 draft ouverte
      (https://github.com/cslucki/entraide/pull/220), label
      validation:sensitive appliqué
- [x] CI SQLite+PostgreSQL SUCCESS sur `816b0cc` (HEAD réellement poussé
      et testé) — confirmé : SQLite CI SUCCESS, PostgreSQL CI SUCCESS.
      Les commits locaux suivants (corrections TASK file pures, sans
      code applicatif) ne sont pas repoussés pour éviter un cycle CI
      complet redondant (~15 min) sans valeur fonctionnelle — le code
      réellement testé et validé par CI est exactement celui de `816b0cc`
- [x] STOP avant merge — rapport MASTER

---
# Progress Log


## 2026-08-16 17:10:52 Europe/Paris

Task created.

Owner:
OPUS

Branch:
TASK-1216-ia-rag-ingestion-fichiers-txt-markdown-v1

Status:
IN_PROGRESS

## 2026-08-16 — Implémentation + recette réelle (OPUS)

### Architecture (audit fork + lecture directe)
- `App\Models\DossierFile` existait déjà intégralement (upload/CRUD/policy/
  MIME TXT+MD déjà acceptés) — aucun observer câblé pour le RAG. Décision :
  migration additive mineure (`blog_post_id` nullable + `dossier_file_id`
  nullable FK dédiée, pas de polymorphisme) + nouveau service parallèle
  `DossierFileIndexer` (clone structurel de `DossierArticleIndexer`, celui-
  ci intouché) + extension ciblée de `DossierSemanticSearchService`.
  **FABLE non nécessaire** — aucune refonte transversale.
- `DossierSemanticSearchService::search()`/`searchAcrossDossiers()` :
  INNER JOIN blog_posts → LEFT JOIN blog_posts + LEFT JOIN dossier_files,
  condition OR. `source_type` distingue les deux sans jamais les mélanger.
  `DossierRetrievalSource::collect()` : provenance/URL générique (filename
  pour fichier via `organization.dossiers.files.show`, titre/slug pour
  Article via `organization.blog.show`). Le template Blade
  (`loops/show.blade.php`) était déjà source-agnostique : **zéro
  changement frontend**.

### Implémentation (3 commits)
- `6a4261e` feat: add TXT and Markdown dossier RAG ingestion — migration,
  `FileContentExtractor` (extraction locale déterministe, jamais de LLM,
  jamais d'exécution du Markdown, garde-fous vide/encodage invalide/
  binaire déguisé/taille excessive/MIME non supporté), `DossierFileIndexer`
  (même doctrine P4/staleness que TASK-1214), `IndexDossierFileChunks` job,
  `DossierFileIndexingDispatcher`, `DossierFileObserver` (create/update
  content/move=detach+attach/delete/restore), enregistrement
  AppServiceProvider.
- `9c096eb` feat: enforce file RAG lifecycle and provenance in retrieval —
  extension JOIN + provenance.
- `5a92876` test: validate native file RAG end to end — 14 tests
  `TASK1216FileIngestionTest` + 2 e2e réels ajoutés à
  `PgvectorDossierRetrievalSourceTest` + fixtures TASK-1213/Pgvector mises
  à jour pour le contrat de ligne étendu.

### Tests
- SQLite (safe-test.sh) : régression large `Dossier|TASK121[3-6]|TASK1200`
  → 12 échecs = liste connue pgvector inchangée (aucune régression),
  571 verts.
- PostgreSQL réel (phpunit.pgsql.xml) : même filtre → **566 verts**,
  2 échecs `DossierFileTest` (quota upload) **pré-existants, hors scope**
  (bug PostgreSQL `FOR UPDATE` + fonction d'agrégat `SUM()`, non supporté ;
  code `DossierFileController.php:159` jamais touché par cette TASK,
  dernier commit TASK-1131 ; confirmé par instrumentation directe puis
  `git diff HEAD` vide sur ce fichier). Documenté comme dette hors TASK-
  1216, pas corrigé ici.
- `TASK1216FileIngestionTest` (14) + `PgvectorDossierRetrievalSourceTest`
  (+2 e2e) : 100% verts sur les deux drivers.

### Recette réelle
- **Playwright WSL** (script `pw/task1216-file-rag-recette.mjs`, banc 8010,
  worker relancé sur le code TASK-1216, migration appliquée sur
  `bouclepro_ai_validation`) : upload TXT (23 violets/mardi) → Ask the
  Folders confirme avec source → clic Open (téléchargement réel) →
  cross-tenant (org-a, aucun P4 configuré → 0 fuite, 0 source) → delete +
  reupload (29/mercredi, jamais 23/mardi) → delete final ("not found",
  0 génération) → Markdown (18 modules verts, syntaxe # et ** correctement
  dépouillée) → cleanup. **0 console error, 0 page error, 0 requestfailed
  inattendu** sur toute la recette.
- **Chrome (spot-check final, ~8 min)** : login maya@artscilab-demo.test →
  upload TEST-station-orion.txt via UI réelle → Emergence → vrai clic
  "Ask the Folders" → vraie question tapée → vrai clic "Answer" → réponse
  générée en direct "23 panneaux violets... mardi matin [S1]" avec source
  `TEST-station-orion.txt` → vrai clic "Open" → téléchargement réel
  confirmé (cohérent avec Playwright) → 0 erreur console → suppression
  réelle du fichier via l'UI ("File deleted.") → chunks revenus à 11.
- Nettoyage : aucune donnée ArtSciLab réelle touchée, aucun fichier TEST
  résiduel (vérifié par requête SQL), `dossier_chunks` = 11 (baseline
  identique avant/après).

Status:
DONE

# Handoffs

# Tests

- [x] feature tests (14 TASK1216FileIngestionTest + 2 e2e pgvector, 100% verts)
- [x] browser validation (Playwright WSL complet + Chrome spot-check)
- [x] responsive validation (non applicable — aucun changement UI/CSS)
- [x] console inspection (0 erreur, 0 page error, 0 requestfailed inattendu)
- [x] tenant validation (cross-tenant org-a : 0 fuite, 0 source, 0 génération)

---

# Test Results

**SQLite (safe-test.sh)** — filtre `Dossier|TASK121[3-6]|TASK1200` :
571 verts, 12 échecs = liste connue pgvector (`.github/sqlite-known-failures.txt`),
strictement identiques avant/après cette TASK.

**PostgreSQL réel (phpunit.pgsql.xml)** — même filtre :
566 verts, 2 échecs pré-existants hors scope (`DossierFileTest` quota,
bug `FOR UPDATE`+agrégat, code jamais touché par TASK-1216).

**TASK1216FileIngestionTest** (14/14) + **PgvectorDossierRetrievalSourceTest**
e2e fichier + mixte Article/fichier (2/2) : verts sur les deux drivers.

**Playwright WSL** : recette complète TXT+MD+lifecycle+cross-tenant,
0 erreur navigateur.

**Chrome spot-check** : upload → Ask the Folders (vrai clic Answer) →
réponse+source → vrai clic Open (téléchargement confirmé) → suppression
réelle. 0 erreur console.

---

# Review Notes

**Portée livrée** : ingestion RAG native TXT/Markdown via le pipeline
existant (chunking, P4/ProviderResolver, pgvector, retrieval), lifecycle
complet (create/update/delete/move=detach+attach/unsupported/idempotence),
provenance/Open, coexistence Article+fichier dans le même retrieval.
Aucune console — conforme au scope strict de cette TASK (Phase 2).

**Non fait (hors scope explicite)** : console RAG Organization, PDF/DOCX/
OCR, consommation IA, pricing embeddings, User BYOK — décisions déjà
prises en amont par MASTER, non rouvertes ici.

**Dette découverte, non corrigée (hors scope)** : `DossierFileController`
ligne 159 — `lockForUpdate()->sum('size_bytes')` échoue sur PostgreSQL réel
(`FOR UPDATE is not allowed with aggregate functions`), pré-existant
(TASK-1131), affecte `test_upload_allows_under_quota` et
`test_upload_validates_quota`. À corriger dans une TASK dédiée (remplacer
par un verrou explicite sur une ligne dédiée, ou une sous-requête).

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- Run `ai/scripts/bump-version.sh` on the task branch BEFORE `finalize-task.sh`
- `merge-task.sh` verifies VERSION format but does NOT bump it
- Footer always displays `config('app.version')`
