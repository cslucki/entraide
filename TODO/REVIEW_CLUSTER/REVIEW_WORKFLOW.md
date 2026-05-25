# Review Workflow — T140

## Phase de Bootstrap (actuelle)

1. ✅ Inventaire initial (outils installés)
2. ✅ Création branche dédiée
3. ✅ Création structure Review Cluster
4. 🔄 Configuration minimale (phpstan.neon, rector.php)
5. 🔄 Documentation Review Cluster
6. ⏳ Rapport BOOTSTRAP_REPORT.md
7. ⏳ Validation humaine (Cyril)

## Phase d'Audit T140.5

Une fois le cluster validé :

### Étape 1 — Compréhension

**Agent : REVIEW_ARCHITECT**

Objectif :
- Comprendre l'architecture de T140.5A-D
- Identifier les dépendances
- Documenter les flux métier

Outils :
- Laravel Boost MCP (database-schema, application-info)
- Grep ciblé sur T140
- Lecture de docs/architecture

Sortie : `AGENTS/REVIEW_ARCHITECT/REPORT.md`

### Étape 2 — Analyse statique

**Agent : STATIC_ANALYZER**

Objectif :
- PHPStan sur T140.5 code
- Détection de problèmes de typage
- Détection de bugs potentiels

Outils :
- PHPStan (phpstan.neon)
- Rector (dry-run pour détection)

Sortie : `AGENTS/STATIC_ANALYZER/REPORT.md`

### Étape 3 — Sécurité tenant

**Agent : TENANT_SAFETY_REVIEWER**

Objectif :
- Vérifier isolation Organization-scoped
- Vérifier tenant scopes
- Détection de fuites de données cross-tenant

Outils :
- Laravel Boost MCP (database-query)
- Grep sur `current_organization`, tenant scopes
- Relecture des policies

Sortie : `AGENTS/TENANT_SAFETY_REVIEWER/REPORT.md`

### Étape 4 — Conformité Laravel

**Agent : LARAVEL_REVIEWER**

Objectif :
- Conformité avec best practices Laravel
- Typing correct
- Namespaces cohérents
- Convention de nommage

Outils :
- Pint (test mode)
- Laravel Boost docs
- Readme patterns

Sortie : `AGENTS/LARAVEL_REVIEWER/REPORT.md`

### Étape 5 — Validation UI/Livewire

**Agent : PLAYWRIGHT_REVIEWER**

Objectif :
- Validation comportement UI
- Console errors
- DOM integrity
- Livewire hydration

Outils :
- Playwright ciblé
- Browser console
- Screenshots

Sortie : `AGENTS/PLAYWRIGHT_REVIEWER/REPORT.md`

## Phase de Synthèse

Une fois tous les rapports produits :

1. Synthèse des findings
2. Priorisation (critique/high/medium/low)
3. Recommandations d'amélioration
4. Proposition de plan de correction

## Règles de workflow

1. **Séquentiel** : Étapes 1-5 dans l'ordre
2. **Read-only** : Aucune modification du code
3. **Documentation** : Tout dans `AGENTS/*/REPORT.md`
4. **Coordination** : Chaque agent documente son travail
5. **Validation** : Arrêt après chaque phase si needed

## Interdits

- Modifier le code applicatif
- Commiter sur branches T140.5
- Merger vers develop/main
- Corriger les problèmes sans validation