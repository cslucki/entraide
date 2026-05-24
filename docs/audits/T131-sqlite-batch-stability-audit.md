# T131 — SQLite Batch Stability Audit

**Date :** 2026-05-24 Europe/Paris
**Référence :** TASK-131-t131-sqlite-batch-stability-audit
**Branche develop :** `e7b12c0` (post-merge T130)

---

## Résumé exécutif

Le batch SQLite est **entièrement stable**. Les "échecs SQLite batch récurrents" observés dans les tâches précédentes étaient 100% attribuables à des CSRF 419 failures (149 tests), corrigées en **TASK-121** (commit `bcd3ced`). Aucun problème SQLite-spécifique n'a été trouvé.

**Résultat batch actuel : 725 passed / 1585 assertions — 2 runs consécutifs stables.**

---

## Méthode d'audit

### Phase A — Batch complet SQLite

```bash
php artisan test --configuration=phpunit.xml
```

- Run 1 : **725 passed / 1585 assertions / 88.15s**
- Run 2 : **725 passed / 1585 assertions / 96.46s**

Aucun échec, aucune flakiness.

### Phase B — Isolation diagnostique

Aucune isolation nécessaire : tous les tests passent en batch.

### Phase C — Analyse des causes potentielles

| Vecteur | Statut | Détail |
|---|---|---|
| CSRF 419 failures | **RÉSOLU** (T121) | `PreventRequestForgery::class` dans `TestCase::setUp()` |
| LazilyRefreshDatabase | **N/A** | 0 usage — tous utilisent `RefreshDatabase` |
| ILIKE / ::text / jsonb | **SAFE** | Un seul usage ILIKE, dans `SearchController::30`, gardé par `getDriverName()` |
| Migrations manquantes SQLite | **N/A** | Aucune migration conditionnelle PostgreSQL-only trouvée |
| Seeders incompatibles SQLite | **N/A** | DemoSeeder hors runtime tests |
| Ordre des tests | **N/A** | Aucune dépendance inter-test détectée (RefreshDatabase isole chaque test) |
| Dépendance PostgreSQL implicite | **N/A** | Aucun raw SQL PostgreSQL-only dans les tests |

---

## Configuration de test

### phpunit.xml (SQLite, défaut)

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

### phpunit.pgsql.xml (PostgreSQL, CI/local)

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<!-- DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD depuis .env / CI -->
```

### tests/TestCase.php

```php
use RefreshDatabase;

protected function setUp(): void
{
    parent::setUp();
    $this->withoutMiddleware(PreventRequestForgery::class);
}
```

`RefreshDatabase` sur SQLite `:memory:` : la base est recréée à chaque test via les migrations. Comportement correct et stable.

---

## Historique des échecs batch

### Avant TASK-121 (≤ commit `bcd3ced`)

**149 tests en échec avec status 419 (CSRF).**

Cause racine : `withoutMiddleware(ValidateCsrfToken::class)` silencieusement inefficace.  
Laravel 11 enregistre `PreventRequestForgery::class` dans le pipeline web, pas `ValidateCsrfToken::class` (qui étend PreventRequestForgery). Le no-op enregistré pour `ValidateCsrfToken` n'interceptait pas la résolution de `PreventRequestForgery`.

Fix : `$this->withoutMiddleware(PreventRequestForgery::class)` dans `setUp()`.

**Résultat post-T121 :** 692 passed / 1526 assertions.

### Après TASK-121 jusqu'à TASK-130

Série T122→T130 : 33 nouveaux tests ajoutés (tenant isolation, withoutGlobalScope guards, profile reviews, desync community/org).

**Résultat actuel :** 725 passed / 1585 assertions.

---

## Compatibilité SQLite — Points de vigilance futurs

| Vecteur | Risque | Action recommandée |
|---|---|---|
| `ILIKE` dans de nouvelles requêtes | MOYEN | Toujours utiliser le pattern `getDriverName() === 'pgsql' ? 'ilike' : 'like'` |
| `withoutGlobalScopes()` global dans de nouveaux tests | FAIBLE | Utiliser `withoutGlobalScope(BelongsToTenantScope::class)` ciblé |
| Casts PostgreSQL (`::text`, `jsonb`) | ÉLEVÉ si introduits | Interdits dans du code partagé SQLite/PostgreSQL |
| `LazilyRefreshDatabase` | N/A | Acceptable sur SQLite `:memory:` mais non nécessaire |
| Migrations avec `DB::statement` PostgreSQL-only | ÉLEVÉ si introduits | Utiliser `$this->onlyOnce()` ou guard `DB_CONNECTION` |

---

## Verdict

**CAS 1 : aucun problème actif.**

Le batch SQLite est stable. Les échecs historiques étaient des CSRF 419, résolus en T121. Aucun patch SQLite nécessaire.

---

## Recommandation

**Option : maintenir le statu quo.**

- SQLite `:memory:` + `RefreshDatabase` = stack de test locale rapide et stable.
- PostgreSQL CI via `phpunit.pgsql.xml` = parité production.
- Aucune quarantine, aucun abandon SQLite batch nécessaire.

**Règle de gouvernance :** toute nouvelle migration ou requête raw doit être vérifiée SQLite-compatible. La checklist est dans le tableau ci-dessus.

---

## Contexte

- **CSRF fix :** `tests/TestCase.php` — commit `bcd3ced` (TASK-121)
- **Compatibilité SQL :** `app/Http/Controllers/SearchController.php:30`
- **withoutGlobalScope allowlist :** `docs/architecture/withoutGlobalScope-allowlist.md` (T129)
- **Audit PostgreSQL :** `TODO/TASK-060-postgresql-local-validation.md`
