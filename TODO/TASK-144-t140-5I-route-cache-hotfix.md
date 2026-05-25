---
task_id: TASK-144-t140-5I
title: T140.5I Route Cache Serialization Hotfix

status: IN_PROGRESS

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5I-route-cache-hotfix

priority: HIGH

created_at: 2026-05-25 14:41:31 Europe/Paris
updated_at: 2026-05-25 14:41:31 Europe/Paris

labels:
  - route
  - cache
  - hotfix

---

# T140.5I — Route Cache Serialization Hotfix

## Objectif

Corriger les noms de routes dupliqués qui empêchent `php artisan optimize` et `route:cache` de fonctionner.

## Root cause

Dans `routes/web.php:385-388`, les routes GET et POST de l'org-scoped auth group avaient toutes les deux `->name('login')` et `->name('register')`. Laravel ne peut pas sérialiser des noms dupliqués pour `route:cache`.

## Fix

- `routes/web.php:386` — retiré `->name('login')` de la route POST `/login`
- `routes/web.php:388` — retiré `->name('register')` de la route POST `/register`

Pattern suivi : seul le verbe GET garde le nom (utilisé pour les redirections/liens). La route POST n'a pas de nom — le formulaire résout via la même URI.

## Vérification

- `php artisan optimize` : ✅ config, events, routes, views
- `php artisan route:list --name=organization.login` : ✅ 1 route (GET)
- `php artisan test` : 826 passed, 11 skipped, 0 failed

## Pourquoi ce contrôle manquait

`php artisan optimize` n'est jamais exécuté dans le test suite ou le CI. Les tests utilisent `php artisan test` qui utilise le routage en mémoire et ne rencontre jamais l'erreur de sérialisation. Aucun smoke gate ne testait `route:cache` ou `optimize` — c'est un prérequis d'infrastructure non couvert.

## Modified Files

- `routes/web.php` — 2 lignes modifiées

## Tests

826 passed, 11 skipped, 0 failed
