# TASK-073 STATUS

## Completed
- T073A — Referral Foundations (migrations, models, relations, tests SQLite+PG) — MERGED
- T073B — Referral Logic (events, listeners, rewards, anti-abuse) — MERGED
- T073C — Referral Member UX (register ?ref=, dashboard invitation card, copy link, referral points label, tests + screenshots) — MERGED
- T073D — Referral Admin UX (admin referrals route, controller, view, navigation, KPIs, recent invitations, recent activations, contributions, tests) — MERGED
- T073E — Referral Reward Configuration (config/referral.php, RewardDispatcher → config(), tests) — MERGED

## Pending
- T073F
- T073G

## Constraints
- Organization = Tenant
- Loop != Tenant
- additive-only
- SQLite + PostgreSQL
- Playwright-safe
- max_depth = 2 MVP

## Do Not Touch
- auth core
- tenant middleware
- onboarding runtime

## T073A Deliverables
- `database/migrations/2026_05_13_000001_create_referrals_table.php`
- `database/migrations/2026_05_13_000002_create_referral_rewards_table.php`
- `database/migrations/2026_05_13_000003_add_referral_code_to_users_table.php`
- `app/Models/Referral.php` — with all relations
- `app/Models/ReferralReward.php` — with all relations
- `database/factories/ReferralFactory.php`
- `database/factories/ReferralRewardFactory.php`
- `app/Models/User.php` — +referral_code, +sentReferrals(), +receivedReferrals(), +referralRewards()
- `tests/Feature/ReferralTest.php` — 31 tests / 54 assertions

## T073B Deliverables
- `app/Events/MemberInvited.php`
- `app/Events/MemberActivated.php`
- `app/Listeners/AwardReferralReward.php`
- `app/Services/ReferralCodeGenerator.php` — collision-safe code generation
- `app/Services/ReferralService.php` — orchestration + anti-abuse
- `app/Services/RewardDispatcher.php` — L1/L2 reward via PointLedger
- `database/migrations/2026_05_13_000004_add_referral_reward_to_point_ledger_reason.php`
- `database/migrations/2026_05_13_000005_drop_point_ledger_reason_check_constraint.php`
- `app/Models/User.php` — +referralCode(), +referredBy(), auto referral_code generation
- `app/Providers/AppServiceProvider.php` — ReferralCodeGenerator binding
- `tests/Feature/ReferralServiceTest.php` — 16 tests
- `tests/Feature/RewardDispatcherTest.php` — 4 tests
- `tests/Unit/ReferralCodeGeneratorTest.php` — 3 tests

## T073D Deliverables
- GET /admin/referrals
- route admin.referrals
- AdminReferralController dédié
- resources/views/admin/referrals.blade.php
- navigation admin “Invitations”
- KPIs sobres
- invitations récentes
- activations récentes
- contributions sans leaderboard
- AdminReferralTest OK
- Referral tests OK
- OpenAI review OK

## T073A Important Constraints
- UNIQUE(community_id, referrer_user_id, referred_user_id) — dupe prevention
- `parent_referral_id` self-referential FK séparé pour PostgreSQL compat
- BelongsToTenantScope sur Referral et ReferralReward
- `referral_code` nullable unique sur users
- Factory helpers `forOrganization()` pour tests tenant-scopés
- SoftDeletes non utilisé (append-only)
- Events/Listeners/RewardDispatcher — PAS implémentés (scope T073B+)

## T073B Important Notes
- point_ledger reste non tenant-scopé par design existant
- Migration enum → string(50) validée SQLite + PostgreSQL
- Risque concurrence double activation non durci, accepté MVP
- Pas d'UX/admin/badges/WhatsApp dans T073B

## T073D Important Notes
- Admin UX minimale uniquement
- Pas de leaderboard
- Pas de gamification agressive
- Pas de modification du système de rewards
- Pas de "Community" côté utilisateur
- UX sobre conforme Organization Admin
- T073D mergée dans develop avec CI verte

## T073E Deliverables
- `config/referral.php` — configuration centralisée des rewards
- `app/Services/RewardDispatcher.php` — constantes de classe → `config('referral.rewards.*')`
- `tests/Feature/RewardDispatcherTest.php` — constantes → config(), 28 tests
- Option B implémentée et vérifiée
- 84 passed / 196 assertions / 0 failed
- Pint OK
- OpenAI review OK
- Déploiement nécessaire pour modifier les valeurs (config fichier, pas DB)

## T073E Important Notes
- Option B retenue : config fichier, pas de Setting::get(), pas d'UI admin
- Pas de migration, pas de package, pas de Livewire
- Rewards existantes non modifiées (append-only préservé)
- Fallback values = anciennes constantes (comportement identique)
- Risque résiduel nul
- T073E mergée dans develop avec CI verte
