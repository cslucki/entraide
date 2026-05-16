---
task_id: TASK-073D
title: Referral Admin UX

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-073D-referral-admin-ux

priority: HIGH

created_at: 2026-05-13 20:53:00 Europe/Paris
updated_at: 2026-05-13 20:53:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Créer une UX admin minimale pour permettre à un Organization Admin / admin de suivre l'état du système d'invitation referral :

- KPIs referral sobres
- invitations récentes
- activations récentes
- points d'invitation distribués
- contributeurs principaux sans classement agressif
- historique simple si déjà disponible

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] validate UI
- [ ] final review

---

# Constraints

- pas de refonte admin
- pas de changement layout/navigation global
- pas de nouvelle direction artistique
- pas de dashboard dense
- pas de leaderboard
- pas de "Top parrain"
- pas de "classement"
- pas de growth hacking
- pas de MLM
- pas de graph social
- pas d'analytics BI
- pas de package
- pas de migration sauf nécessité prouvée
- pas de Livewire sauf nécessité absolue
- pas de nouvelle permission complexe si l'existant suffit
- préserver tenant safety
- préserver SQLite + PostgreSQL
- préserver Playwright
- ne jamais afficher "Community" côté utilisateur

---

# Wording

## Recommandé

- Invitations
- Membres invités
- Activations
- Contributions
- Dynamique d'invitation
- Membres entrés dans la boucle
- Points d'invitation
- Suivi des invitations

## Interdit

- Community côté utilisateur
- Parrainez et gagnez !
- Boostez votre réseau
- Classement
- Top parrain
- Ambassadeur numéro 1
- Growth hacking
- MLM

---

# Progress Log

## 2026-05-13 20:53:00 Europe/Paris — OPENCODE

Tâche créée manuellement (pas via create-task.sh).

Actions effectuées :
- branche `TASK-073D-referral-admin-ux` créée depuis `develop` (working tree propre)
- fichier `TODO/TASK-073D-referral-admin-ux.md` créé
- status : IN_PROGRESS
- lock : LOCKED (OPENCODE)

Aucune implémentation réalisée.
Aucun fichier applicatif modifié.

---

## 2026-05-13 20:53:00 Europe/Paris — OPENCODE (inspection)

Inspection complète réalisée — aucun fichier applicatif modifié.

### Fichiers inspectés

**Routes :**
- `routes/web.php` (lignes 132-222) — groupe admin

**Controllers :**
- `app/Http/Controllers/Admin/AdminController.php` (540 lignes)
- `app/Http/Controllers/Admin/AdminCommunityController.php`
- `app/Http/Controllers/Admin/AdminMessageController.php`
- `app/Http/Controllers/Admin/AdminBlogController.php`
- `app/Http/Controllers/Admin/AdminSettingController.php`
- `app/Http/Controllers/Admin/AdminEmailLogsController.php`

**Vues :**
- `resources/views/layouts/admin.blade.php` (layout + sidebar)
- `resources/views/admin/dashboard.blade.php` (dashboard + stats cards)
- `resources/views/admin/transactions.blade.php` (table filtrée)
- `resources/views/admin/reports.blade.php` (table + statuts)

**Modèles/DB :**
- `app/Models/Referral.php` — `HasOrganizationId`, `BelongsToTenantScope`, relations referrer/referred/rewards
- `app/Models/ReferralReward.php` — `HasOrganizationId`, `BelongsToTenantScope`
- `app/Models/User.php` — `sentReferrals()`, `receivedReferrals()`, `referralRewards()`, `referral_code` auto-généré
- `app/Models/PointLedger.php` — `reason = 'referral_reward'`
- `app/Models/Scopes/BelongsToTenantScope.php` — filtre par `community_id`
- `app/Support/Tenancy/CurrentOrganization.php` — résolution tenant

**Services :**
- `app/Services/ReferralService.php` — `attributeByCode()`
- `app/Services/RewardDispatcher.php` — points : 10/10/5/20/10
- `app/Services/ReferralCodeGenerator.php`

**Événements :**
- `app/Events/MemberInvited.php`, `app/Events/MemberActivated.php`
- `app/Listeners/AwardReferralReward.php`

**Middleware :**
- `app/Http/Middleware/AdminMiddleware.php` — `auth()->user()->is_admin`
- `app/Http/Middleware/ResolveOrganization.php` (alias de ResolveCommunity, PAS appliqué aux routes admin)

### Découvertes

**1. Routes admin existantes (61 routes dans le groupe)**
- Préfixe : `/admin`, nom : `admin.*`, middleware : `['auth', 'admin']`
- Dashboard → AdminController::dashboard()
- Users, Services, Transactions, Requests, Categories, Reports → AdminController
- Communities, Messages, Blog, Settings, Email → controllers dédiés
- Pas de route referral admin existante

**2. Controllers — pattern dédié récent**
- `AdminController` (540 lignes, monolithique) gère 7 domaines
- Les controllers plus récents (AdminBlogController, AdminEmailLogsController, AdminEmailTemplatesController) sont dédiés (Single Responsibility)
- Convention : méthodes GET retournent `: View`, mutations retournent `: RedirectResponse`
- Flash messages : `->with('success'/'error', ...)` en français
- Pagination : `->latest()->paginate(20)->withQueryString()`

**3. Vues — trois patterns**
- Cartes stats : `grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4` (7 cartes sur le dashboard)
- Tableaux filtrés : formulaire GET + table + pagination (transactions, reports, etc.)
- Layout : `<x-admin-layout title="...">`, sidebar hardcodée dans `admin.blade.php` (16 items)
- Pas de composant `<x-stat-card>` réutilisable — HTML dupliqué
- Pas de charting library
- Conventions Tailwind : `bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700`

**4. Navigation sidebar**
- Tableau `$navItems` hardcodé dans `layouts/admin.blade.php` (lignes 26-42)
- Rendering boucle avec icônes SVG + active state `bg-indigo-600 text-white`
- Badge signalements via `$pendingReportsCount`
- Lien "Retour à l'app" en bas

**5. Données referral disponibles**
- `Referral` — id, community_id, organization_id, referrer_user_id, referred_user_id, parent_referral_id, depth, status (pending/activated), activated_at
- `ReferralReward` — id, referral_id, user_id, source_user_id, event_type, level, points, metadata (json)
- `PointLedger` — entries with `reason = 'referral_reward'`
- `User.referral_code` — unique, auto-généré
- `sentReferrals()` / `receivedReferrals()` relations sur User

**6. Tenant safety**
- `BelongsToTenantScope` appliqué sur Referral et ReferralReward → filtre par `community_id = current_organization_id`
- Mais le middleware `ResolveOrganization`/`ResolveCommunity` n'est PAS appliqué aux routes admin
- Donc dans le contexte admin : `current_organization` n'est pas bind → scope ne filtre PAS → toutes les données sont visibles (global)
- Pattern existant : `AdminController::dashboard()` utilise `User::count()` global (pas scoped)
- Pour un éventuel scope org-admin : utiliser `Community::where('admin_id', auth()->id())`

**7. Compatibilité DB**
- Toutes les requêtes Eloquent pures — pas de raw SQL
- Pas de fonctions de fenêtrage ou grouping complexe
- SQLite + PostgreSQL compatibles sans changements

### Recommandation d'emplacement minimal

**Route :** `GET /admin/referrals` → `admin.referrals` (dans le groupe existant)

**Controller :** Nouveau `AdminReferralController` (pattern dédié, pas d'ajout à l'AdminController monolithique)

**Vue :** `resources/views/admin/referrals.blade.php` (fichier unique)

**Navigation :** 1 entrée dans `$navItems` du layout admin

**Données :**
- KPIs : total invitations, en attente, activées, points distribués, inviteurs actifs
- Tableau invitations récentes (20) : referrer → referred, date, statut
- Tableau activations récentes (20) : referrer → referred, date activation
- Contributeurs principaux (top 10) : user + count, sans classement agressif

**Requêtes :**
```php
// stats
Referral::count();
Referral::where('status', 'pending')->count();
Referral::where('status', 'activated')->count();
ReferralReward::sum('points');
User::whereHas('sentReferrals')->count();

// listes
Referral::with(['referrer', 'referred'])->latest()->paginate(20);
Referral::with(['referrer', 'referred'])->whereNotNull('activated_at')->latest('activated_at')->paginate(20);
User::withCount('sentReferrals')->having('sent_referrals_count', '>', 0)->orderByDesc('sent_referrals_count')->limit(10)->get();
```

### Tests recommandés (implémentation future)
- `tests/Feature/AdminReferralTest.php`
  - accès page admin referral OK pour admin
  - accès 403 pour non-admin
  - KPIs cohérents
  - tenant safety (cross-organization invisible)
  - pagination fonctionnelle

---

## 2026-05-13 20:53:00 Europe/Paris — OPENCODE (implémentation)

Implémentation minimale réalisée.

### Finalisation

- status : DONE
- lock : UNLOCKED
- OpenAI review : OK avec réserve (workflow uniquement, aucun correctif code)
- Route `admin.referrals` → `AdminReferralController@index`
- Vue `admin/referrals.blade.php` — 4 KPIs + 3 sections
- Navigation "Invitations" ajoutée dans sidebar admin
- AdminReferralTest 10/10 PASS
- Tous les tests Referral 65/65 PASS, aucune régression

### Fichiers modifiés/créés

| Fichier | Action |
|---------|--------|
| `app/Http/Controllers/Admin/AdminReferralController.php` | CRÉÉ — controller dédié, 63 lignes |
| `routes/web.php` | MODIFIÉ — import + route `GET /admin/referrals` |
| `resources/views/admin/referrals.blade.php` | CRÉÉ — vue avec KPIs + tables |
| `resources/views/layouts/admin.blade.php` | MODIFIÉ — entrée "Invitations" dans sidebar |
| `tests/Feature/Admin/AdminReferralTest.php` | CRÉÉ — 10 tests, 29 assertions |
| `TODO/TASK-073D-referral-admin-ux.md` | MODIFIÉ — progress log |

### Tests exécutés

```bash
php artisan test --filter=AdminReferralTest  # 10/10 PASS (29 assertions)
php artisan test --filter=Referral           # 65/65 PASS (146 assertions)
```

Aucune régression. Tous les tests referral préexistants passent.

### Choix d'implémentation

**Controller :** `AdminReferralController` dédié (pattern AdminBlogController, pas d'ajout à l'AdminController monolithique de 540 lignes). Méthode unique `index(): View`. Utilise `withoutGlobalScope(BelongsToTenantScope::class)` pour afficher les données globales (comme le dashboard admin existant).

**Route :** `GET /admin/referrals` → `admin.referrals`, dans le groupe admin existant, après Reports.

**Vue :** `resources/views/admin/referrals.blade.php` — 3 sections :
1. 4 KPIs (invitations, en attente, activations, points)
2. Invitations récentes + Activations récentes (2 colonnes)
3. Contributions (table sans numérotation, ni rang, ni classement)

Patterns Blade/Tailwind repris de `transactions.blade.php` et `dashboard.blade.php`.

**Navigation :** Entrée "Invitations" dans `$navItems`, entre Signalements et Templates Email. Icône user-add.

**Test :** `tests/Feature/Admin/AdminReferralTest.php` — namespace `Tests\Feature\Admin`, 10 tests :
- accès guest → redirect login
- accès non-admin → 403
- accès admin → 200
- KPIs affichés (titre, labels)
- données referral visibles (nom invitant, invité, statut)
- activations visibles
- points KPI
- contributors section
- wording interdit absent (7 termes vérifiés)
- empty states

### Limites assumées

- Admin voit toutes les données (pas de scope par organisation). Cohérent avec le dashboard existant.
- Contributors sans somme de points (disponible dans ReferralReward mais pas dans la query).
- Pas de pagination — `limit(20)` simple.
- Pas de filtre/recherche sur les listes.
- Pas de Livewire, pas de JS, pas de chart.
- Compatible SQLite + PostgreSQL (Eloquent pur).

# Handoffs

# Tests

```bash
php artisan test --filter=AdminReferralTest
# 10/10 PASS, 29 assertions (0 failures)
```

```bash
php artisan test --filter=Referral
# 65/65 PASS, 146 assertions (0 failures)
```

Aucune régression sur les tests referral préexistants.

## AdminReferralTest (10 tests, 29 assertions)

- test_guest_cannot_access_referrals ✓
- test_non_admin_cannot_access_referrals ✓
- test_admin_can_view_referrals_page ✓
- test_admin_sees_page_title_and_kpis ✓
- test_admin_sees_referral_data ✓
- test_admin_sees_activated_referrals ✓
- test_admin_sees_referral_points_kpi ✓
- test_admin_sees_contributors_section ✓
- test_page_does_not_contain_forbidden_wording ✓
- test_page_shows_empty_state_when_no_data ✓

---

# Review Notes

- Controller utilise `withoutGlobalScope(BelongsToTenantScope::class)` pour être explicite sur le scope admin global
- Vue utilise `assertSee(..., false)` pour les chaînes avec apostrophes (assertSee HTML-encode par défaut)
- Pas de wording interdit vérifié par test automatisé
- Aucune modification des fichiers existants hors scope
- OpenAI review : OK avec réserve
  - aucun correctif code obligatoire
  - `withoutGlobalScope(BelongsToTenantScope::class)` jugé cohérent avec admin global existant
  - `/admin/referrals` protégé par `auth` + `admin` middleware
  - pas de N+1 évident
  - pas de raw SQL fragile
  - wording interdit absent
  - section Contributions non assimilable à leaderboard

### Limites assumées

- Admin global voit les données globales, cohérent avec dashboard admin existant
- Pas d'analytics BI
- Pas de leaderboard
- Pas de scope Organization Admin fin ajouté dans cette tâche
- Pas de pagination sur les listes (limit(20))
- Contributors sans somme de points individuelle

---

# Validation

## Vérifications manuelles

| Vérification | Statut |
|---|---|
| Status DONE / lock UNLOCKED | ✅ |
| Route `GET /admin/referrals` nommée `admin.referrals` | ✅ |
| Controller `AdminReferralController::index()` retourne `View` | ✅ |
| Vue `<x-admin-layout>` utilisée | ✅ |
| Navigation "Invitations" dans sidebar | ✅ |
| Test 10/10 PASS | ✅ |
| --filter=Referral 65/65 PASS | ✅ |
| Pas de wording interdit dans la vue | ✅ |
| Pas de raw SQL | ✅ |
| Pas de N+1 (`->with()` sur toutes les relations) | ✅ |
| Pas de Livewire | ✅ |
| Pas de nouveau package | ✅ |
| Pas de migration | ✅ |

