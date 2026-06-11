---
task_id: TASK-240
title: AI interactions storage strategy plan

status: PLAN_COMPLETE

owner: OPENCODE

contributors: []

branch: TASK-240-ai-interactions-storage-strategy-plan

priority: MEDIUM

created_at: 2026-06-11 05:41:22 Europe/Paris
updated_at: 2026-06-11 05:55:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 05:55:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# AI Interactions Storage Strategy Plan

## Executive Summary

**Recommandation : Option A — Reporter le stockage DB, conserver le JSONL metrics-only pour le MVP et la bêta.**

Le JSONL metrics-only actuel couvre tous les besoins de benchmark et d'observabilité pour la phase bêta. Une table `ai_interactions` n'ajouterait pas de valeur immédiate et introduirait une complexité disproportionnée (migrations, indexes, rétention, GDPR, coût DB).

Une future migration additive `ai_interactions` pourra être planifiée quand les besoins suivants seront confirmés par l'usage réel :
- Dashboard analytics temps réel (agrégations SQL)
- Quota management (suivi des appels par Organization)
- Debug rétroactif (recherche par scenario/provider/date)
- Facturation (coût cumulé par Organization)
---

## Section 1 — État actuel du JSONL metrics-only

### Ce qui est déjà en place (T237)

- **`AiBenchmarkLogger.php`** : écriture append-only de lignes JSON dans `storage/logs/ai-benchmarks/{scenario_id}.jsonl`
- **`LoggingSupervisionProvider.php`** : decorator autour du `SupervisionProvider` par défaut, capture les métriques
- **`AdminAiSupervisionController.php`** : log manuel pour `clarify_help_request`
- **`STRIP_KEYS`** : 8 clés bannies — aucun contenu brut stocké

### Données actuellement loggées par appel

| Champ | Description |
|---|---|
| `timestamp` | ISO 8601 |
| `scenario_id` | supervision_content / clarify_help_request |
| `model` | gpt-4o-mini, ollama/..., openrouter/... |
| `input_tokens` | int |
| `output_tokens` | int |
| `latency_ms` | float |
| `cost_usd` | float (estimation) |
| `content_length` | int |
| `status` | success / error |

### Lacunes identifiées

- `user_id` / `organization_id` — non capturés
- `provider` — implicite via scénario, pas un champ explicite
- `error_type` — non capturé en cas d'échec
- Pas de dashboard, pas de requêtage, pas d'agrégation

---

## Section 2 — Analyse : JSONL vs DB

### Avantages du JSONL actuel

1. **Zéro DB** — pas de migration, pas de table, pas d'index, pas de backup
2. **Zéro schéma** — flexible, évolutif
3. **Zéro impact runtime** — écriture fichier asynchrone, `LOCK_EX`
4. **Zéro coût GDPR** — pas de contenu utilisateur (STRIP_KEYS)
5. **Simple** — `cat`, `wc -l`, `jq`, `grep` pour analyse ad-hoc
6. **Rotatif** — logrotate standard, pas besoin de purge applicative
7. **Déjà en place** — testé (7 tests unitaires), merge into develop

### Inconvénients du JSONL actuel

1. **Pas d'agrégation SQL** — dashboard = parsing fichier
2. **Pas de requêtage** — pas de `WHERE date > X`, pas de `GROUP BY model`
3. **Pas de quota** — impossible de compter les appels par Organization sans DB
4. **Pas de rétention métier** — logrotate = perte de données après rotation

### Avantages d'une table `ai_interactions`

1. **Requêtage SQL** — analytics, dashboards, agrégations
2. **Rétention maîtrisée** — durée configurable, pas de rotation brutale
3. **Quota management** — `SELECT COUNT(*) WHERE organization_id = X AND date > now() - 1 month`
4. **Debug rétroactif** — filtre par provider, model, scenario, erreur

### Inconvénients d'une table `ai_interactions`

1. **Migration** — nouvelle table, indexes, FK, rétrocompatibilité
2. **Coût DB** — 1 ligne par appel AI → des milliers de lignes/mois en prod
3. **GDPR** — obligation stricte de ne pas stocker `input_content`/`output`/`content`/`prompt`/`response`
4. **Backup** — backup DB requis avant migration (procédure `synchro_pgsql-avant-migration/`)
5. **Monitorage** — nouvelle table à surveiller (taille, performances)
6. **Pas de besoin immédiat** — la bêta n'a pas de dashboard analytics, pas de quotas par Organization

---

## Section 3 — Règle de confidentialité permanente

**Quelles que soient les décisions futures (JSONL ou DB), la règle suivante est permanente :**

> No raw prompt/content/output stored in any AI interaction log.

`AiBenchmarkLogger::STRIP_KEYS` (8 clés bannies) reste l'autorité canonique :
- `input_content`, `content`, `output`, `prompt`, `response`, `raw_response`, `system_prompt`, `user_prompt`

Toute future table DB DOIT avoir cette règle encodée dans son schéma (pas de colonne pour ces données) et dans son modèle Eloquent (pas de `$fillable` qui les autorise).

---

## Section 4 — Schéma prospectif `ai_interactions` (pour référence future)

Si et seulement si le besoin est confirmé après la bêta, la table suivante est proposée :

```sql
CREATE TABLE ai_interactions (
    id BIGSERIAL PRIMARY KEY,
    organization_id BIGINT REFERENCES organizations(id) ON DELETE CASCADE, -- nullable: admin lab = null
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    scenario_id VARCHAR(100) NOT NULL,
    provider VARCHAR(50) NOT NULL,            -- openai / ollama / openrouter
    model VARCHAR(150) NOT NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    latency_ms DOUBLE PRECISION NOT NULL DEFAULT 0,
    cost_usd DOUBLE PRECISION NOT NULL DEFAULT 0,
    content_length INTEGER NULL,             -- longueur du prompt, pas le contenu
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    error_type VARCHAR(50) NULL,             -- exception / timeout / rate_limit / parse_error
    response_metadata JSONB NULL,            -- champs non-structurés (pas de prompt/output)
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX idx_ai_interactions_org_id ON ai_interactions(organization_id);
CREATE INDEX idx_ai_interactions_scenario ON ai_interactions(scenario_id);
CREATE INDEX idx_ai_interactions_provider ON ai_interactions(provider);
CREATE INDEX idx_ai_interactions_created_at ON ai_interactions(created_at);
CREATE INDEX idx_ai_interactions_status ON ai_interactions(status);
```

### Notes sur le schéma

- `organization_id` nullable → les appels du lab admin (pas de tenant) auront `NULL`
- `response_metadata` JSONB → pour des champs non-structurés (pas d'input/output, jamais)
- Pas de colonne `input_content`, `content`, `output`, `prompt`, `response`
- `error_type` enum libre → `exception`, `timeout`, `rate_limit`, `parse_error`, `validation_error`
- `content_length` → longueur en caractères, pas le contenu

### Modèle Eloquent prospectif

```php
class AiInteraction extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'scenario_id', 'provider', 'model',
        'input_tokens', 'output_tokens', 'latency_ms', 'cost_usd',
        'content_length', 'status', 'error_type', 'response_metadata',
    ];

    // PAS de input_content, content, output, prompt, response

    protected $casts = [
        'response_metadata' => 'array',
        'cost_usd' => 'float',
        'latency_ms' => 'float',
        'created_at' => 'datetime',
    ];

    // Pas de BelongsToTenantScope — l'Organization est explicite
    // Pas de GlobalScope automatique — le lab admin est tenantless
}
```

---

## Section 5 — Plan de migration additive future

**Déclencheur** : la migration ne sera planifiée que si au moins UN des besoins suivants est confirmé par l'usage réel en bêta :
1. Dashboard analytics temps réel demandé
2. Quota management par Organization nécessaire
3. Debug rétroactif impossible via JSONL seul
4. Facturation nécessitant des agrégats SQL

### Étapes de migration (ordre strict)

1. **Backup local** — `ai/scripts/backup-internal.sh` + pg_dump `bouclepro`
2. **Migration DB** — `php artisan make:migration create_ai_interactions_table` (additive, pas de `--seed`)
3. **Modèle** — `AiInteraction` Eloquent
4. **Logger DB** — `DatabaseBenchmarkLogger` (nouvelle classe, parallèle au JSONL)
5. **Provider DB** — `DatabaseLoggingSupervisionProvider` (decorator comme le JSONL)
6. **Tests** — unit + feature, vérification organisation isolation
7. **Backfill** — script optionnel pour importer le JSONL existant dans la DB
8. **Maintenir** le JSONL logger en parallèle (redondance)

### Rollback

- Supprimer la migration (down)
- Revenir au JSONL seul
- Pas de perte de données critiques (le JSONL reste le backup)

---

## Section 6 — Questions spécifiques

### Avons-nous réellement besoin d'une table `ai_interactions` maintenant ?

**Non.** Le MVP / bêta n'a pas de dashboard analytics, pas de quotas, pas de facturation. Le JSONL metrics-only couvre 100% des besoins d'observabilité et de benchmark actuels.

### Le JSONL metrics-only suffit-il pour la bêta ?

**Oui.** Les métriques clés (tokens, latence, coût, scénario, modèle) sont capturées. L'analyse ad-hoc est possible via `jq`, `grep`, `wc -l`. Aucun besoin métier ne justifie une DB maintenant.

### Quelles métriques doivent rester fichier ?

Aucune obligation de rester fichier. Le JSONL est un format de transport. Une future DB pourrait absorber ces métriques. Mais le JSONL sert aussi de backup brut redondant.

### Quelles données ne doivent jamais être stockées ?

**Permanentes (JSONL + DB) :** `input_content`, `content`, `output`, `prompt`, `response`, `raw_response`, `system_prompt`, `user_prompt` — la liste `STRIP_KEYS` est canonique.

### Quelles colonnes seraient nécessaires si table future ?

Voir Section 4 — schéma prospectif `ai_interactions`.

### Quel lien avec Organization, user admin, scenario, provider, model ?

- `organization_id` → FK nullable vers `organizations` (NULL pour lab admin)
- `user_id` → FK nullable vers `users` (admin qui a lancé l'appel)
- `scenario_id` → VARCHAR (pas de FK car `AiScenarioFactory` est un registry runtime, pas une table)
- `provider` → VARCHAR (openai / ollama / openrouter)
- `model` → VARCHAR

### Comment respecter "no raw prompt/content/output stored" ?

`AiBenchmarkLogger::STRIP_KEYS` strip les clés interdites avant écriture JSONL. Pour une future DB, le modèle Eloquent n'aura pas ces colonnes dans `$fillable`, et le logger DB appliquera le même filtre `array_diff_key`.

### Quel plan backup local avant future migration ?

```bash
ai/scripts/backup-internal.sh
pg_dump bouclepro > /tmp/bouclepro-backup-$(date +%Y%m%d-%H%M%S).sql
```

### Quels tests seraient nécessaires ?

Pour une future migration DB :
1. **Unit** : `DatabaseBenchmarkLoggerTest` — écriture DB, strip keys, transaction rollback
2. **Unit** : `DatabaseLoggingSupervisionProviderTest` — decorator DB
3. **Feature** : `AdminAiSupervisionWithDbLoggingTest` — intégration controller + DB logger
4. **Feature** : `AiInteractionsOrganizationIsolationTest` — vérifier qu'un admin d'une Organization ne voit pas les interactions d'une autre
5. **Feature** : `AiInteractionsTableTest` — vérifier que le schéma n'a pas de colonnes interdites

---

## Section 7 — Recommandation finale

**Option A — Reporter DB, conserver JSONL pour le MVP et la bêta.**

### Justification

1. ✅ Le JSONL couvre 100% des besoins actuels (benchmark, observabilité)
2. ✅ Zéro complexité additionnelle (pas de migration, pas de table, pas de model)
3. ✅ Zéro risque GDPR (STRIP_KEYS déjà en place)
4. ✅ La bêta validera (ou non) le besoin d'une DB
5. ✅ Le JSONL restera un backup redondant même avec une future DB

### Conditions de réévaluation

- Après 2-4 semaines de bêta avec usage réel
- Si un besoin confirmé de dashboard / quotas / facturation émerge
- Créer alors une TASK dédiée `ai_interactions` migration additive (TASK-2XX)

### Prochaine étape naturelle

Quand le besoin DB sera confirmé, créer une TASK dédiée suivant le plan de migration additive (Section 5). Le schéma prospectif (Section 4) servira de base.

---

# Progress Log

## 2026-06-11 05:41:22 Europe/Paris

Task created. Status: IN_PROGRESS, lock: OPENCODE.

## 2026-06-11 05:55:00 Europe/Paris

Plan rédigé (7 sections, 297 lignes). Recommandation : Option A — DB reportée, JSONL metrics-only pour MVP/bêta.

Status → PLAN_COMPLETE, lock → UNLOCKED. Prêt pour review VERIFICATOR.

# Scope

Rule: PLAN ONLY. Aucun code, aucune migration, aucune DB. Strictement documentaire et stratégique.

# Test Results

N/A — PLAN ONLY. Aucun code, aucun test à exécuter.

# Review Notes

En attente VERIFICATOR. Read-only review du plan stratégique demandée.