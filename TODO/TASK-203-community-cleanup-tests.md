---
task_id: TASK-203
title: Cleanup legacy community references in tests

status: IN_PROGRESS

owner: SUPERVISOR

contributors: []

branch: TASK-203-community-cleanup-tests

priority: MEDIUM

created_at: 2026-06-02 15:00:00 Europe/Paris
updated_at: 2026-06-02 15:00:00 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: SUPERVISOR
  since: 2026-06-02 15:00:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Clean up legacy community references in test files — delete 2 files, rename community→org in 6 others.

---

# Planned Actions

- [x] DELETE tests/Feature/T1403CurrentCommunityFallbackGatesTest.php
- [x] DELETE tests/Feature/T1392KnownRisksTest.php
- [ ] MODIFY tests/Feature/T1392LegacyCharacterizationTest.php
- [ ] MODIFY tests/Feature/BelongsToOrganizationScopeTest.php
- [ ] MODIFY tests/Feature/ResolveUrlOrganizationTest.php
- [ ] MODIFY tests/Feature/Api/ApiTenantScopingTest.php
- [ ] MODIFY tests/Feature/T1404OrganizationParallelRoutesTest.php
- [ ] MODIFY tests/Feature/LoopMemberInvariantTest.php
- [ ] Run full test suite
- [ ] Commit + push + report

---

# Progress Log

## 2026-06-02 15:00:00 Europe/Paris

Created branch TASK-203-community-cleanup-tests from develop. Ready to implement.

---

# Handoffs

# Tests

# Test Results

---

# Review Notes

---

# Version Notes
