---
task_id: TASK-161
status: MERGED
owner: OpenCode
branch: TASK-161-fix-6-remaining-test-failures
lock:
  status: UNLOCKED
  agent: none
  since: null
---

# TASK-161 — Fix 6 remaining test failures

## Résultat final
825 ✅ / 0 ❌ / 11 ⏭️ (1741 assertions, 32.60s)

## Fichiers modifiés
- `tests/Feature/OrganizationCompatibilityTest.php` — table assertion + factory (2 fixes)
- `tests/Feature/T1392LegacyCharacterizationTest.php` — table assertion + legacy sync tests (3 fixes)
- `tests/Feature/RewardDispatcherTest.php` — user creation without org context (1 fix)
- `tests/Feature/T1403CurrentCommunityFallbackGatesTest.php` — allowlist (1 fix)
