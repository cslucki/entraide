# Tooling — Review Cluster

## Outils statiques

### PHPStan / Larastan

**Version** : 2.1.55 (via Laravel Boost)

**Usage** :
```bash
vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress
```

**Mode** : analyse statique, lecture seule, aucun changement

**Configuration** : `phpstan.neon` (minimale, niveau 5 par défaut)

### Rector

**Version** : 2.4.4 (via Laravel Boost)

**Usage** :
```bash
vendor/bin/rector process --dry-run --config=rector.php
```

**Mode** : **DRY-RUN SEULEMENT**, aucun changement automatique

**Configuration** : `rector.php` (détection de problèmes, sans application)

### Laravel Pint

**Version** : 1.29.1

**Usage** :
```bash
vendor/bin/pint --test
```

**Mode** : test uniquement, aucun changement

## O introspection Laravel

### Laravel Boost MCP

Outils MCP disponibles :
- `laravel-boost_application-info` — info PHP, Laravel, packages
- `laravel-boost_database-schema` — structure DB
- `laravel-boost_database-query` — requêtes read-only
- `laravel-boost_search-docs` — documentation Laravel
- `laravel-boost_get-absolute-url` — URLs
- `laravel-boost_read-log-entries` — logs

**Règle** : utiliser MCP avant toute commande `rg` massif pour comprendre l'architecture.

## Outillage de test

### PHPUnit

**Usage ciblé** :
```bash
php artisan test --filter=PatternTest
```

### Playwright

**Usage ciblé** :
- UI/web/Livewire uniquement
- Console errors
- DOM inspection
- Screenshots

```bash
npx playwright test --grep="pattern"
```

## Règles d'usage

1. **Read-only** : aucun outil ne modifie le code
2. **Dry-run** : Rector et Pint en mode test/inspection
3. **MCP first** : utiliser Laravel Boost MCP avant grep
4. **Ciblé** : tests et analyses ciblées sur T140.5
5. **Documentation** : tous les résultats dans `AGENTS/*/REPORT.md`

## Interdits

- `vendor/bin/rector process` (sans --dry-run)
- `vendor/bin/pint` (sans --test)
- Modification directe de fichiers runtime
- Commit sur branches T140.5
- Merge vers develop/main