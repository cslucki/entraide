---
task_id: TASK-181
title: rename CommunityRequest model to OrganizationRequest

status: MERGED

owner: SUPERVISOR

contributors: []

branch: TASK-181-rename-communityrequest-model-to-organizationrequest

priority: MEDIUM

created_at: 2026-05-31 12:39:49 Europe/Paris
updated_at: 2026-05-31 13:52:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED

handoff: false

pr:
  status: NOT_READY
  url: null

---

# Objective

Rename CommunityRequest model to OrganizationRequest without destructive DB migration. Only align PHP class name with current Organization terminology while keeping table `community_requests` legacy.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI (not required - backend-only change)

---

# Progress Log

## 2026-05-31 12:42:00 Europe/Paris

Task completed successfully.

### Implementation Summary

Renamed PHP model `App\Models\CommunityRequest` to `App\Models\OrganizationRequest` with minimal scope changes:

1. **Model Renamed**: `app/Models/CommunityRequest.php` → `app/Models/OrganizationRequest.php`
   - Class renamed from `CommunityRequest` → `OrganizationRequest`
   - Added explicit `protected $table = 'community_requests';` to maintain table legacy compatibility
   - All fillables and relations preserved identically

2. **Controller Updated**: `app/Http/Controllers/OrganizationRequestController.php`
   - Import changed: `use App\Models\CommunityRequest;` → `use App\Models\OrganizationRequest;`
   - Usage changed: `CommunityRequest::create($data);` → `OrganizationRequest::create($data);`

3. **Tests Added**: `tests/Feature/OrganizationRequestTest.php`
   - `test_can_create_organization_request_via_post`: Validates POST /partenaires/demande
   - `test_organization_request_model_exists_and_works`: Validates model instantiation and table mapping

4. **Verification**: `rg "CommunityRequest" --type php` → No references found

### Tests Executed

- `php artisan test --filter=OrganizationRequest`: **2 passed** (7 assertions)
- `php artisan test --filter=CommunityRequest`: **0 tests** (no references remain)
- `php artisan test`: **826 passed, 11 skipped** (1756 assertions)

### Files Modified

**Created:**
- `app/Models/OrganizationRequest.php`
- `tests/Feature/OrganizationRequestTest.php`

**Modified:**
- `app/Http/Controllers/OrganizationRequestController.php`

**Deleted:**
- `app/Models/CommunityRequest.php`

**TASK File:**
- `TODO/TASK-181-rename-communityrequest-model-to-organizationrequest.md`

### No Migration Required

- No database structure changes
- Table `community_requests` remains legacy
- Model explicitly maps to legacy table via `$table` property

---

## 2026-05-31 13:51:00 Europe/Paris

Task merged into develop.

### Merge Workflow

1. **Check Task**: ✅ PASS (Status DONE, Lock UNLOCKED, Clean working tree)
2. **Finalize Task**: ✅ PASS (No uncommitted changes)
3. **Merge Task**: ✅ PASS (Merge into develop successful)

### Merge Details

**Merge Commit:** `f644436`
**Strategy:** `--no-ff` (explicit merge commit)
**Target Branch:** `develop`
**Files in Merge:** 4 files changed, 231 insertions(+), 4 deletions(-)

**Merge Summary:**
- `TODO/TASK-181-rename-communityrequest-model-to-organizationrequest.md` (created)
- `app/Models/OrganizationRequest.php` (renamed from CommunityRequest.php)
- `app/Http/Controllers/OrganizationRequestController.php` (modified)
- `tests/Feature/OrganizationRequestTest.php` (created)

### Push Status

**Pending:** Push to origin/develop
**Action Required:** `git push origin develop`

---

# Handoffs

None.

---

# Tests

- [x] feature tests (2 new tests added)
- [ ] browser validation (not required - backend-only change)
- [ ] responsive validation (not required - backend-only change)
- [x] console inspection (full suite: 826 passed)
- [ ] tenant validation (not required - public route, not tenant-scoped)

---

# Test Results

**Baseline:** 824 passed, 11 skipped (TASK-180)
**Current:** 826 passed, 11 skipped (TASK-181)
**Delta:** +2 passed (new OrganizationRequestTest)

### OrganizationRequest Filter
- `test_can_create_organization_request_via_post`: ✓ PASS
- `test_organization_request_model_exists_and_works`: ✓ PASS
- Total: 2 passed, 0 failed, 7 assertions

### CommunityRequest Filter
- No tests found (model renamed, no legacy tests)

### Full Suite
- 826 passed
- 11 skipped
- 1756 assertions
- Duration: 29.38s

---

# Review Notes

**Scope Compliance:**
- ✓ Only PHP model renaming, no DB migration
- ✓ Table `community_requests` remains legacy with explicit `$table` property
- ✓ No search/replace global; targeted changes only
- ✓ No historical migrations modified
- ✓ No forbidden files touched (ResolveUrlOrganization, policies, etc.)

**Architecture Compliance:**
- ✓ Organization terminology preferred over Community
- ✓ No new Community terminology introduced
- ✓ Consistent with Laravel best practices (namespace, naming)
- ✓ Model follows existing patterns (HasUuids, fillables, BelongsTo)

**Code Quality:**
- ✓ Minimal change footprint (3 files touched, 2 created, 1 deleted)
- ✓ Tests added to validate functionality
- ✓ No references to old model remain
- ✓ Full test suite passes

**Safety:**
- ✓ No destructive changes
- ✓ Backward compatible (table name preserved)
- ✓ No tenant isolation concerns (public route)
- ✓ No policy or middleware changes required

**VERIFICATOR Verdict: CHANGES_REQUESTED** - TASK file update required only (now done).

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`