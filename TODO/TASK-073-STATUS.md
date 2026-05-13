# TASK-073 STATUS

## Completed
- T073A — Referral Foundations (migrations, models, relations, tests SQLite+PG) — MERGED
- T073B — Referral Logic (events, listeners, rewards, anti-abuse) — DONE / ready for finalize

## Current
- T073B — ready for merge

## Pending
- T073C
- T073D
- T073E
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
