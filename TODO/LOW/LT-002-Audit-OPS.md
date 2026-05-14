# LT-002 — Synchronisation main / develop / branches avant reprise T074

**Statut :** AUDIT — Aucun changement applicatif
**Date :** 2026-05-14
**Auteur :** OPS (Cyril)

---

## 1. Audit branches / main / develop

### Source : confirmation OPENAI

| Branche | Contient |
|---------|----------|
| **main** | LT-001 (admin password reset link) — commits `5c4132c`, `f224f01`, `ef4e372`, `3208419` |
| **develop** | Ne contient **pas** LT-001 |
| **develop** | Contient TASK-071, TASK-072, TASK-073A→G, TASK-072-media-pull, TASK-072-production-postgresql-mirror-workflow, TASK-069-runtime-organization-compatibility-layer |
| **main** | Ne contient **pas** les tâches ci-dessus |
| **TASK-074** | Basée sur `develop` actuel (merge-base = `145090d`) |

### Divergence actuelle

```
main → develop (5 commits) :
  5c4132c Merge branch 'LT-001-admin-send-password-reset-link'
  f224f01 feat(auth): log public password reset requests
  ef4e372 fix(admin): polish password reset email flow
  3208419 feat(admin): send password reset link
  c301fcc Merge pull request #27 from cslucki/TASK-069-runtime-organization-compatibility-layer

develop → main (33+ commits, extraits principaux) :
  145090d docs(task): update TASK-073 STATUS for T073G
  357618a Merge branch 'TASK-073G-referral-future-proofing-contribution-architecture-notes'
  3397df7 Merge branch 'TASK-073A-referral-foundations' into develop
  98def6d fix(ops): URL-decode media paths in media-pull.sh
  80ca025 Merge branch 'TASK-072-media-pull' into develop
  96b0132 Merge branch 'TASK-072-production-postgresql-mirror-workflow' into develop
  06184bc Merge branch 'TASK-067-organization-runtime-adoption' into develop
  2acabb3 Merge branch 'TASK-068-document-official-tooling-workflow' into develop
  b4b1f6f feat(organization): runtime organization compatibility layer
  ... et ~24 autres commits (referral, media, tooling, organization)
```

### File: TASK-074

- Branche : `TASK-074-t074-0-technical-audit-current-messaging-mobile-issues-reverb-readiness`
- Merge-base avec `develop` : `145090d`
- La branche est à jour par rapport à `develop`.

---

## 2. Audit tenant / security — Findings OPS (hors scope LT-002)

### 2.1 API routes sans scope tenant

**Fichier :** `routes/api.php`

Toutes les routes API utilisent `auth:sanctum` mais **aucune n'applique de middleware tenant/community**. Aucun filtrage par `community_id` ou `organization_id` n'est effectué côté routes API.

Routes concernées :
- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/services`, `GET /api/services/{service}`
- `GET /api/requests`, `GET /api/requests/{serviceRequest}`
- `GET /api/users/{user}`
- `POST /api/transactions`, `GET /api/transactions`, `GET/POST /api/transactions/{transaction}/*`

### 2.2 TransactionController::store — withoutGlobalScope

**Fichier :** `app/Http/Controllers/TransactionController.php:32,37`

```php
$service = Service::withoutGlobalScope(BelongsToTenantScope::class)->findOrFail($data['service_id']);
// ou
$serviceReq = ServiceRequest::withoutGlobalScope(BelongsToTenantScope::class)->findOrFail($data['request_id']);
```

Le bypass de scope est **justifié** (lire un service par ID pour en extraire `community_id`), mais doit être vérifié :
- Le `community_id` extrait est ensuite utilisé pour créer la transaction → correct.
- L'API TransactionController (`app/Http/Controllers/Api/TransactionController.php`) utilise `findOrFail` **sans** `withoutGlobalScope`, ce qui signifie que si le global scope est actif côté API, la requête échouera ou retournera 404 si le service n'appartient pas au tenant courant. **C'est un risque cross-tenant si le scope n'est pas actif.**

### 2.3 DashboardController — aucun filtre tenant

**Fichier :** `app/Http/Controllers/DashboardController.php`

Toutes les requêtes (`Transaction`, `Service`, `ServiceRequest`) sont faites sans filtre `community_id` :
- `Transaction::where('buyer_id', ...)` ou `where('seller_id', ...)` — aucune vérification tenant
- `$user->services()->where('status', 'active')` — dépend de la relation, pas de scope
- `$user->servicesRequests()` — idem
- `Transaction::where(...)` pour propositions et messages récents — pas de filtre tenant

**Risque :** Si un user a des données dans plusieurs tenants (improbable mais possible en cas de migration ou bug d'assignation), le dashboard exposerait des données cross-tenant.

### 2.4 Policies sans vérification tenant

| Policy | Vérification | Risque |
|--------|-------------|--------|
| `TransactionPolicy` | Compare `user->id` avec `buyer_id`/`seller_id` uniquement | Un utilisateur d'un tenant pourrait agir sur une transaction d'un autre tenant si les IDs correspondent |
| `ServicePolicy` | Compare `user->id` avec `user_id` | Pas de check tenant |
| `ServiceRequestPolicy` | Compare `user->id` avec `user_id` | Pas de check tenant |
| `MessagePolicy` | Compare participation à la transaction | Pas de check tenant |
| `ReviewPolicy` | Vérifie participation + statut `completed` | Pas de check tenant |
| `BlogPostPolicy` | Vérifie `user_id` ou `is_admin` | Pas de check tenant |

**Note :** L'absence de `DashboardPolicy` est aussi notée.

### 2.5 Risques cross-tenant résumés

1. **API exposée** : aucun scope tenant sur les endpoints publics (`/api/services`, `/api/requests`, `/api/users/{user}`) — un appelant authentifié pourrait itérer sur tous les tenants.
2. **Transaction API Controller** : `findOrFail` sans scope global → dépend du middleware pour la sécurité.
3. **Dashboard** : données potentiellement cross-tenant.
4. **Policies** : toutes basées sur l'identité utilisateur seule, sans ancrage tenant.

---

## 3. Décision

> **Les findings tenant/API ci-dessus sont conservés pour une future tâche sécurité dédiée.**
> **Aucun correctif applicatif n'est introduit dans LT-002.**

LT-002 se limite strictement à la synchronisation Git (back-merge `main` → `develop`) et à la documentation.

---

## 4. État du worktree local

```
 M .env.pgsql      (modifié, tracké, dans .gitignore — fichier local généré)
 M .env.sqlite     (modifié, tracké, dans .gitignore — fichier local généré)
?? .obsidian/      (non tracké, non ignoré)
```

- `.env.pgsql` et `.env.sqlite` sont dans `.gitignore` mais restent marqués modifiés car ils étaient déjà trackés (`git ls-files` les confirme). Ils ne seront pas commités sauf `git add` explicite.
- `.obsidian/` n'est pas dans `.gitignore` et n'est pas tracké. Doit rester non tracké ou être ajouté au `.gitignore`.

### Stratégie recommandée pour le worktree

1. **Option A (recommandée) — Stash temporaire :**
   ```bash
   git stash --include-untracked   # stash .env.* modifiés + .obsidian/
   ```
   Poursuivre le merge, puis restaurer :
   ```bash
   git stash pop
   ```

2. **Option B — .gitignore uniquement :**
   Ajouter `.obsidian/` au `.gitignore`, laisser les `.env.*` tels quels (déjà ignorés, pas de risque de commit).

3. **Ne rien supprimer.** Aucune des modifications `.env.*` n'est critique — ce sont des configs locales.

---

## 5. Plan recommandé

| # | Étape | Commande proposée |
|---|-------|-------------------|
| 1 | Nettoyer/stasher le worktree local | `git stash --include-untracked` |
| 2 | Vérifier la divergence exacte | `git log --oneline main..develop` |
| 3 | **Back-merge `main` → `develop`** | `git checkout develop && git merge main` |
| 4 | Vérifier les conflits potentiels | Inspecter : `routes/web.php`, `AdminController`, flux password reset, docs TODO |
| 5 | Résoudre les conflits le cas échéant | Éditer les fichiers en conflit, `git add`, `git merge --continue` |
| 6 | Tester localement | Vérifier que le serveur démarre, routes accessibles |
| 7 | Pousser `develop` après validation | `git push origin develop` |
| 8 | Synchroniser TASK-074 avec `develop` | `git checkout TASK-074-... && git merge develop` |

### Commande exacte proposée (non exécutée)

```bash
# Étape 1 — Sécuriser le worktree
git stash --include-untracked

# Étape 2 — Basculer sur develop
git checkout develop

# Étape 3 — Merger main dans develop
git merge main

# Étape 4 — Inspecter les conflits (si retour non-vide de git status)
git status

# Étape 5 — Si conflits : résoudre, puis
# git add <fichiers_corrigés>
# git merge --continue

# Étape 6 — Vérification locale
# php artisan serve --env=local  (vérifier routes, admin, auth)

# Étape 7 — Pousser (uniquement après validation)
# git push origin develop

# Étape 8 — Synchroniser TASK-074
# git checkout TASK-074-t074-0-technical-audit-current-messaging-mobile-issues-reverb-readiness
# git merge develop
```

---

## 6. Fichiers créés / modifiés

| Fichier | Action |
|---------|--------|
| `TODO/LOW/LT-002-Audit-OPS.md` | **Créé** — Document d'audit LT-002 (OPS) |
| *(aucun autre fichier modifié)* | — |

---

## 7. Git status final

```
 M .env.pgsql
 M .env.sqlite
?? .obsidian/
```

Aucun commit, aucun push, aucun merge, aucun git add n'ont été effectués.

---

*Document généré dans le cadre de LT-002. Les findings sécurité tenant/API sont archivés ici pour référence future et seront traités dans une tâche dédiée.*