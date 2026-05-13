# TASK-073 STATUS

## Completed
- T073A — Referral Foundations (migrations, models, relations, tests SQLite+PG)

## Current
- T073B — Referral Logic (events, listeners, rewards, anti-abuse)

## Pending
- T073B
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

## T073A Important Constraints
- UNIQUE(community_id, referrer_user_id, referred_user_id) — dupe prevention
- `parent_referral_id` self-referential FK séparé pour PostgreSQL compat
- BelongsToTenantScope sur Referral et ReferralReward
- `referral_code` nullable unique sur users
- Factory helpers `forOrganization()` pour tests tenant-scopés
- SoftDeletes non utilisé (append-only)
- Events/Listeners/RewardDispatcher — PAS implémentés (scope T073B+)