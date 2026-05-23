# T123 — Blog & Public Surface Tenant Scope Hardening — Audit Report

**Date :** 2026-05-23
**Tâche :** TASK-123
**Branche :** TASK-123-t123-blog-public-surface-tenant-scope-hardening
**Statut :** DONE (non mergé — validation cockpit requise)

---

## 1. Synthèse CAO

CAO (CLI Agent Orchestrator) a été utilisé pour lancer 4 agents d'audit read-only. Résultats mitigés :

| Agent | Session | Provider | Statut |
|-------|---------|----------|--------|
| 1 — Blog scope | cao-9a2dfa18 | claude_glm | ❌ Échoué (2 MCP servers failed → 4/5 startup requests) |
| 2 — Public surfaces | cao-audit-surfaces-2 | claude_glm | ✅ Rapport complet reçu |
| 3 — Scope/policies | — | — | ✅ Résultat du fallback OpenCode |
| 4 — Doctrine/docs | — | — | ✅ Résultat du fallback OpenCode |

**Problèmes CAO documentés :**
- `--auto-approve` ne fonctionne pas en environnement non-TTY (OpenCode, CI)
- `yes \| cao launch` ne contourne pas (stdin ignoré, lecture directe terminal)
- Solution : `--yolo` contourne la confirmation mais ne débloque pas tous les providers
- `opencode_cli` provider inadapté au mode `--async` ; préférer `claude_glm`
- Lancements parallèles peuvent saturer `cao-server`

*Voir `ai/orchestrator/README.md` pour documentation détaillée des workarounds.*

---

## 2. Constats retenus

### Patch unique appliqué

| Fichier | Lignes | Risque | Correction |
|---------|--------|--------|------------|
| `app/Http/Controllers/ProfileController.php:show()` | +5 | P0 — Fuite cross-Organization | Guard `currentOrganization()`. Si `$user->community_id !== $organization->id`, abort(404). |

**Comportement :** 404 (pas 403) — ne révèle pas si l'utilisateur existe dans une autre org. Cohérent avec `BlogController::show()`.

---

## 3. Constats écartés (après analyse)

| Constat initial | Décision | Justification |
|----------------|----------|---------------|
| Ajouter BelongsToTenantScope à BlogPost | ❌ Écarté | Le scope global casserait AdminBlogController (platform admin global). Le filtrage explicite par `community_id` dans BlogController public est suffisant. |
| Filtrer AdminBlogController par org | ❌ Écarté | AdminMiddleware vérifie `is_admin` (platform admin global). L'admin doit voir tous les posts cross-org pour la modération. |
| Ajouter BelongsToTenantScope à User | ⏸️ Reporté | Trop large : impacterait auth, admin, et toutes les queries User système. Nécessite analyse dédiée. Aucune fuite P0 confirmée sur User. |

---

## 4. Classification des risques

| Priorité | Risque | Fichier | Décision |
|----------|--------|---------|----------|
| **P0** | Cross-org data leak (profile public) | ProfileController::show() | **Corrigé** |
| **P1** | Pas de scope global BlogPost | BlogPost model | **Écarté** (mitigé par filtrage explicite dans BlogController) |
| **P1** | Admin voit tous les posts cross-org | AdminBlogController | **Écarté** (platform admin global, comportement intentionnel) |
| **P1** | Pas de scope global User | User model | **Reporté** |
| **P2** | Routes /explorer, /membres sans org | web.php | **Déféré** |
| **P3** | BlogController public déjà bien scopé | BlogController | Positif |

---

## 5. Tests

### Tests créés

`tests/Feature/T0757ProfileOrganizationScopingTest.php` (3 scénarios) :

1. `test_profile_show_returns_user_in_resolved_organization` — ✅
2. `test_profile_show_blocks_cross_organization` — ✅
3. `test_profile_show_fails_without_organization` — ✅

### Tests existants vérifiés

- `tests/Feature/T0756BlogOrganizationScopingTest` — 9 tests ✅
- `tests/Feature/Policies/BlogPostPolicyTest` — 12 tests ✅

**Total : 24 tests passés.**

---

## 6. Fichiers modifiés

```
M  app/Http/Controllers/ProfileController.php    (+5 lignes : guard org)
A  tests/Feature/T0757ProfileOrganizationScopingTest.php  (3 scénarios)
M  ai/orchestrator/README.md                      (documentation CAO)
```

**Aucune régression identifiée.**

---

## 7. Dettes reportées

- `BelongsToTenantScope` filtre sur `community_id` (pas `organization_id`) — dette technique
- Routes `/explorer`, `/membres` sans contexte org
- Multiples `withoutGlobalScope()` patterns non audités
- `User` model sans `BelongsToTenantScope`
- `AdminBlogController` cross-org par design (platform admin)
- Community terminology dans code legacy

## 8. Pistes futures (non planifiées, à arbitrer)

> Ces pistes sont des propositions issues de l'audit. Leur priorisation et planification sont à valider.

1. **Migration BelongsToTenantScope** de `community_id` vers `organization_id`
2. **Audit withoutGlobalScope** résiduels (classifications B/C/D)
3. **Contextualisation org** des routes `/explorer`, `/membres`
4. **Renommage Community → Organization** (documentation + code)
5. **Ajout colonne org** dans admin blog index (visibilité cross-org)

---

## 9. Recommandation

**TASK-123 est terminée et prête pour merge** sur develop après validation cockpit.

Le patch est minimal (5 lignes), ciblé (ProfileController::show()), couvert par des tests (3 scénarios), et ne présente aucun risque de régression identifié.

Le rapport détaillé local (non tracké) est conservé dans `@DOCS/06-audit_scope_communityVSorganization.md`.
