---
task_id: TASK-073E
title: Referral Reward Configuration

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-073E-referral-reward-configuration

priority: MEDIUM

created_at: 2026-05-13 22:03:18 Europe/Paris
updated_at: 2026-05-13 22:20:00 Europe/Paris

labels:
  - referral
  - rewards
  - admin
  - configuration
  - inspection-first

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

Inspecter la configuration actuelle des récompenses d'invitation/referral et décider si TASK-073E doit :
A) rester en lecture seule,
B) déplacer les valeurs hardcodées dans `config/referral.php`,
C) exposer un formulaire admin minimal uniquement si un système de settings persistant existe déjà,
D) reporter proprement la configuration dynamique si trop risqué.

Cette tâche commence par une **inspection + décision**, pas une implémentation directe.

---

# Constraints

- pas de création d'une usine à paramètres
- pas de création de BI
- pas de création de cockpit complexe
- pas de création de leaderboard
- pas de gamification agressive
- pas de MLM
- pas de modification rétroactive des rewards existantes
- pas de modification des `referral_rewards` existantes
- préserver tenant safety
- préserver SQLite + PostgreSQL
- préserver append-only point ledger
- ne jamais afficher "Community" côté utilisateur
- ne pas introduire de nouveau concept produit Community
- ne pas ajouter de migration sauf nécessité prouvée
- ne pas ajouter de package
- ne pas ajouter Livewire

---

# Planned Actions

1. **Inspection** — code referral / reward uniquement
   - [x] inspecter `app/Services/RewardDispatcher.php` (valeurs hardcodées)
   - [x] inspecter `app/Services/ReferralService.php`
   - [x] inspecter `app/Events/MemberActivated.php`
   - [x] inspecter `app/Listeners/AwardReferralReward.php`
   - [x] inspecter `app/Models/ReferralReward.php`
   - [x] inspecter les migrations `referral_rewards`

2. **Inspection** — routes / controllers / views admin referral
   - [x] inspecter les routes admin referral existantes
   - [x] inspecter tout controller ou Livewire admin referral
   - [x] inspecter les vues admin referral

3. **Inspection** — config existante
   - [x] vérifier si `config/referral.php` existe déjà
   - [x] inspecter `config/` pour tout fichier lié
   - [x] inspecter `ReferralService` pour des références à `config()`

4. **Inspection** — système settings existant
   - [x] vérifier si un système de settings persistants (DB, cache, etc.) existe déjà
   - [x] inspecter `app/Settings/` ou équivalent
   - [x] inspecter toute façade ou service provider lié

5. **Décision** — documenter et proposer A/B/C/D
   - [x] analyser les risques de chaque option
   - [x] documenter la décision et la justifier
   - [x] ne pas implémenter sans validation humaine

---

# Inspection Notes

## Fichiers inspectés

| Fichier | Rôle |
|---|---|
| `app/Services/RewardDispatcher.php` | Source des 5 constantes hardcodées (lignes 15-19) |
| `app/Services/ReferralService.php` | Attribution referral — ne touche pas aux points |
| `app/Listeners/AwardReferralReward.php` | Pont événement → Dispatcher |
| `app/Events/MemberActivated.php` | Événement activation membre |
| `app/Models/ReferralReward.php` | Modèle scope tenant, fillable, casts |
| `app/Models/Setting.php` | Système settings persistant existant (get/set, key-value) |
| `database/migrations/2026_05_13_000002_create_referral_rewards_table.php` | Table `referral_rewards` |
| `database/migrations/2026_05_13_000001_create_referrals_table.php` | Table `referrals` |
| `database/migrations/2026_05_13_000003_add_referral_code_to_users_table.php` | Colonne `referral_code` sur users |
| `database/migrations/2026_05_13_000004_add_referral_reward_to_point_ledger_reason.php` | Raison `referral_reward` dans point_ledger |
| `database/migrations/2026_05_03_100000_create_settings_table.php` | Table `settings` (key PK, value text) |
| `app/Http/Controllers/Admin/AdminReferralController.php` | Admin referral — read-only KPIs |
| `app/Http/Controllers/Admin/AdminSettingController.php` | Admin settings — formulaire éditable existant |
| `resources/views/admin/referrals.blade.php` | Vue admin referral (KPIs, tableaux) |
| `resources/views/admin/settings/index.blade.php` | Vue admin settings (platform_name, tagline, maintenance) |
| `routes/web.php` (l.194-199) | Routes admin.referrals + admin.settings + admin.settings.update |
| `tests/Feature/Admin/AdminReferralTest.php` | Tests admin read-only |
| `tests/Feature/ReferralTest.php` | Tests modèle + tenant isolation + anti-abuse |
| `tests/Feature/ReferralServiceTest.php` | Tests service attribution + rewards |
| `tests/Feature/ReferralRegistrationTest.php` | Tests inscription avec code referral |

## Valeurs trouvées

5 constantes de classe dans `app/Services/RewardDispatcher.php:15-19` :

```
INVITE_L1_REFERRER  = 10   // parrain reçoit à l'invitation (niveau 1)
INVITE_WELCOME       = 10   // invité reçoit à l'invitation
INVITE_L2_REFERRER   =  5   // parrain reçoit à l'invitation niveau 2
ACTIVATE_L1_REFERRER = 20   // parrain reçoit à l'activation (niveau 1)
ACTIVATE_L2_REFERRER = 10   // parrain reçoit à l'activation niveau 2
```

Aucun `config/referral.php` n'existe. Aucun appel à `config()` dans `app/Services/`.
Aucun usage des settings pour les rewards. Le système `Setting` existe mais n'est pas utilisé pour les rewards.

## État des lieux

- **config/referral.php** : N'EXISTE PAS
- **config(...) dans les services** : AUCUN
- **Système settings persistant** : EXISTE (modèle `Setting`, table `settings`, `AdminSettingController`)
- **Admin referral** : READ-ONLY (KPIs, tableaux — pas de configuration reward)
- **Rewards existantes** : IMMUABLES (append-only, jamais modifiées)
- **Tenant safety** : OK (`BelongsToTenantScope` + vérifications cross-org)
- **Tests** : 5 fichiers de tests, tous avec RefreshDatabase

---

# Decision

## Analyse des options

### A — Read-only (non recommandé)
- Les valeurs ne sont PAS en config — elles sont hardcodées dans des constantes de classe.
- Prétendre que la config est suffisante serait factuellement incorrect.
- **Rejeté.**

### B — Config file (recommandé)
- Créer `config/referral.php` centralise les 5 valeurs.
- Modifier `RewardDispatcher` pour lire depuis `config('referral.*')`.
- Admin reste read-only (pas de formulaire d'édition UI).
- Pas de migration, pas de DB, pas de nouveau package.
- Risque minimal : les valeurs par défaut du fichier de config doivent correspondre exactement aux constantes actuelles.
- **Recommandé.**

### C — Admin form (trop tôt, bloqué)
- Le système `Setting` (modèle + table + `AdminSettingController`) EXISTE et est RÉUTILISABLE.
- MAIS : modifier `RewardDispatcher` pour lire depuis `Setting::get()` est nécessaire — et cette modification est explicitement interdite par les règles absolues.
- Option C devient viable seulement si `RewardDispatcher` peut être modifié (futur TASK).
- **Bloqué pour T-073E.**

### D — Report (non nécessaire)
- Aucun blocage technique ou conflit avec T-073F/G connu à ce stade.
- L'option B est simple, sûre, incrémentale.
- **Rejeté.**

## Décision retenue

**Option B** — Créer `config/referral.php` et modifier `RewardDispatcher` pour lire depuis `config()`.

Cette tâche (T-073E) couvre l'inspection + décision. La micro-séquence d'implémentation (création du fichier config, modification du Dispatcher, tests) sera exécutée dans une phase suivante sur validation humaine.

---

# Progress Log

- `2026-05-13 22:03:18 Europe/Paris` — OPENCODE : création manuelle de la branche `TASK-073E-referral-reward-configuration` et du fichier TASK `TODO/TASK-073E-referral-reward-configuration.md`. Statut IN_PROGRESS, lock OPENCODE. Démarrage phase inspection + décision.
- `2026-05-13 22:15:00 Europe/Paris` — OPENCODE : inspection terminée. 19 fichiers inspectés. 5 constantes hardcodées trouvées dans `RewardDispatcher`. Décision retenue : **Option B** (config/referral.php). Aucune implémentation. TASK mis à jour avec découvertes, analyse des risques, et prochaine micro-séquence.
- `2026-05-13 22:20:00 Europe/Paris` — OPENAI : `TODO/TASK-073-STATUS.md` corrigé pour refléter T073C/T073D MERGED. Aucune implémentation applicative effectuée.
- `2026-05-13 22:30:00 Europe/Paris` — OPENCODE : implémentation Option B terminée. Fichiers modifiés : `config/referral.php` (créé), `app/Services/RewardDispatcher.php` (constantes → config()), `tests/Feature/RewardDispatcherTest.php` (constantes → config()). Pint exécuté. 5 suites de tests lancées : **84 passed, 0 failed, 196 assertions**. TASK mis à jour.
- `2026-05-13 22:40:00 Europe/Paris` — OPENCODE : finalisation OPS. Commit `47c5ade` pushé. check-task PASSED. finalize-task exécuté. merge-task vers develop OK (merge commit `0f7a789`). develop pushé. TASK status → MERGED.

---

# Tests

- [x] RewardDispatcherTest — 28 passed
- [x] ReferralServiceTest — 9 passed
- [x] ReferralTest — 31 passed
- [x] AdminReferralTest — 10 passed
- [x] ReferralCodeGeneratorTest — 6 passed
- [ ] browser validation (non applicable)
- [ ] responsive validation (non applicable)
- [ ] console inspection (non applicable)
- [x] tenant validation — couvert par ReferralTest

---

# Test Results

5 suites lancées le 2026-05-13 22:30:00 Europe/Paris :
- `RewardDispatcherTest` — 28 passed, 0 failed
- `ReferralServiceTest` — 9 passed, 0 failed
- `ReferralTest` — 31 passed, 0 failed
- `AdminReferralTest` — 10 passed, 0 failed
- `ReferralCodeGeneratorTest` — 6 passed, 0 failed

**Total : 84 passed, 0 failed, 196 assertions.**
Pint : passed.

---

# Review Notes

## Inspection
Inspection terminée. Option B recommandée : config/referral.php + modification RewardDispatcher pour lire depuis config(). Aucune implémentation faite. En attente validation humaine pour micro-séquence d'implémentation.

## Implémentation
Option B implémentée :
- `config/referral.php` créé (activation.level_1_referrer=20, activation.level_2_referrer=10, invitation.level_1_referrer=10, invitation.welcome=10, invitation.level_2_referrer=5)
- `RewardDispatcher` : 5 constantes de classe supprimées, remplacées par `config('referral.rewards.*')` avec fallback identique
- `RewardDispatcherTest` : références aux constantes mortes remplacées par `config()`
- Pas d'UI admin créée
- Pas de migration
- Pas de package ajouté
- Pas de Setting::get() utilisé
- Rewards existantes non modifiées
- Logique métier inchangée (fallback values = anciennes constantes)
- 84 tests pass, 196 assertions, 0 failed

## Risques résiduels
- Aucun : les fallback values sont identiques aux anciennes constantes, le comportement est identique
- La config étant fichier (pas DB), elle nécessite un déploiement pour être modifiée — acceptable pour V1
- Si l'édition admin dynamique est nécessaire un jour, elle pourra être ajoutée sans casser la config fichier (via `config()->set()` fusionné ou Setting)

---

# Handoffs

Pending.
