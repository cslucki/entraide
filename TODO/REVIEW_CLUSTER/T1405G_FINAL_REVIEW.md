# T140.5G Final Review — Production Readiness Audit

**Date**: 2026-05-25
**Review Cluster**: T140.5G
**Scope**: T140.5A through T140.5F (complete cycle)
**Status**: 🟡 ATTENTION — BLOCKING ISSUES DETECTED

---

## Executive Summary

The T140.5 migration cycle has completed successfully from a code organization standpoint, but **blocking security issues** remain that prevent production deployment.

### Verdict
- **Overall Status**: ⚠️ **NOT READY FOR PRODUCTION**
- **Blocking Issues**: **3 critical tenant safety violations**
- **Technical Debt**: 8 items (non-blocking)
- **Static Analysis**: 0 blocking errors, cosmetic style violations

### Critical Findings

#### 1. RewardDispatcher Cross-Organization Referral Processing (BLOCKING)
**Severity**: 🔴 CRITICAL
**Files**: `app/Services/RewardDispatcher.php:64, 89, 116`

**Issue**: Referral activation queries lack `organization_id` filtering, allowing cross-organization referral processing.

**Attack Vector**:
- User in Org A activates referral
- System finds referral by `referred_user_id` only
- If referral exists in Org B with same user ID (data corruption/migration edge case), rewards are incorrectly attributed

**Impact**: Cross-organization points leakage, reward system manipulation

**Recommendation**:
```php
// Line 64 - handleActivated()
Referral::where('referred_user_id', $event->user->id)
    ->where('status', 'pending')
    ->where('organization_id', $event->user->organization_id ?? $event->user->community_id) // ADD THIS
    ->first();

// Line 89 - handleL2Invite()
$parentReferral = Referral::where('referred_user_id', $event->user->id)
    ->where('status', 'activated')
    ->where('organization_id', $orgId) // ADD THIS
    ->first();

// Line 116 - handleL2Activation()
$l2Referral = Referral::where('parent_referral_id', $referral->id)
    ->where('depth', 2)
    ->where('status', 'pending')
    ->where('organization_id', $referral->organization_id) // ADD THIS
    ->first();
```

---

#### 2. Loop Model Binding Enumeration (BLOCKING)
**Severity**: 🟠 HIGH
**Files**: `app/Http/Controllers/LoopController.php:129, 161, 200, 232, 268, 326, 362`

**Issue**: Implicit Laravel model binding loads `Loop` by ID without organization scoping before performing authorization checks.

**Attack Vector**:
- Attacker enumerates loop IDs: `GET /loops/1`, `GET /loops/2`, `GET /loops/3`, etc.
- Model binding loads loop from DB before controller executes
- Authorization check then returns 404, but loop existence is disclosed
- Allows attacker to map organization size, loop count, and identify high-value targets

**Impact**: Information disclosure, reconnaissance capability for targeted attacks

**Recommendation**:

**Option A**: Implement `resolveRouteBinding()` on Loop model
```php
// app/Models/Loop.php
public function resolveRouteBinding($value, $field = null)
{
    $orgId = app(\App\Support\Tenancy\CurrentOrganization::class)->id()
        ?? auth()->user()->organization_id
        ?? auth()->user()->community_id;

    return $this->where($field ?? $this->getRouteKeyName(), $value)
        ->where('organization_id', $orgId)
        ->firstOrFail();
}
```

**Option B**: Convert to manual query with explicit scoping
```php
public function show(string $loopId): View
{
    $community = $this->resolveCommunity();
    $this->assertUserBelongsToCommunity($community);

    $loop = Loop::where('id', $loopId)
        ->where('organization_id', $community->id)
        ->firstOrFail(); // Explicit 404 without enumeration

    // ... rest of method
}
```

---

#### 3. WebSocket Channel Loop Enumeration (BLOCKING)
**Severity**: 🟠 HIGH
**File**: `routes/channels.php:10`

**Issue**: `Loop::find($loopId)` loads loop without organization check before membership verification.

**Attack Vector**:
- Attacker attempts WebSocket connection: `ws://app/loop.{loopId}`
- `Loop::find()` executes first, loading loop from DB
- If loop exists, continues to membership check
- Organization check happens AFTER membership check (line 25-28)
- Allows enumeration of all loop IDs across platform

**Impact**: WebSocket endpoint reconnaissance, mapping platform activity

**Recommendation**:
```php
Broadcast::channel('loop.{loopId}', function ($user, string $loopId) {
    $orgId = $user->organization_id ?? $user->community_id;

    $loop = Loop::where('id', $loopId)
        ->where('organization_id', $orgId) // ADD THIS
        ->first();

    if (! $loop) {
        return false;
    }

    $isActiveMember = LoopMember::where('loop_id', $loopId)
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->exists();

    return $isActiveMember;
});
```

---

## Detailed Agent Findings

### REVIEW_ARCHITECT
**Confidence**: A
**Architecture Quality**: B
**Migration Coherence**: Good
**Tenant Isolation**: Good

**True Risks (2)**:
1. RewardDispatcher:64 - Missing organization_id on activation query (medium)
2. RewardDispatcher:89 - Missing organization_id on L2 parent lookup (medium)

**False Positives (3)**:
1. LoopMessageService:72 - Guard-before-query pattern is acceptable
2. routes/channels.php:16 - Guard-before-query pattern is acceptable
3. ResolveApiOrganization:72 - Legacy compatibility layer is intentional

**Technical Debt (2)**:
1. LoopController:26 - `resolveCommunity()` name confusion (should be `resolveOrganization()`)
2. User.php:35 - Both `community_id` and `organization_id` fields (migration compatibility)

---

### TENANT_SAFETY_REVIEWER
**Confidence**: B
**Tenant Safety**: Needs Improvement
**Cross-Org Vectors**: 5
**Blocking Issues**: 3

**True Risks (13)**:

**Model Binding Enumeration (8)**:
- LoopController:129, 161, 200, 232, 268, 326, 362 - Implicit binding loads before auth
- Loop.php:13 - No `resolveRouteBinding()` implementation
- routes/channels.php:10 - `Loop::find()` without org scoping

**RewardDispatcher Cross-Org Processing (3)**:
- RewardDispatcher:64 - Referral activation without organization scope
- RewardDispatcher:89 - L2 parent lookup without organization scope
- RewardDispatcher:116 - L2 activation without organization scope

**False Positives (2)**:
1. LoopMessageService:74 - Membership check on already-scoped loop is safe
2. LoopMember.php:11 - LoopMember protected by Loop model guards

**Recommendation Priority**:
1. **URGENT**: Add organization scopes to RewardDispatcher queries (3 locations)
2. **URGENT**: Implement Loop::resolveRouteBinding() or manual scoping (7 locations)
3. **URGENT**: Fix channels.php Loop::find() enumeration (1 location)

---

### LARAVEL_REVIEWER
**Confidence**: A
**Code Quality**: Good
**Blocking Issues**: 0
**Technical Debt Items**: 8

**Technical Debt (8)**:
1. LoopService:106 - Missing return type on `generateUniqueSlug()`
2. ReferralService:13 - Query result missing type hint
3. ReferralService:57 - Query result missing type hint
4. RewardDispatcher:89 - Query result missing type hint
5. RewardDispatcher:116 - Query result missing type hint
6. LoopController:25 - Validation inline (should be Form Requests)
7. LoopController:73 - Authorization inline (should use Policies)
8. User.php:124, 140 - N+1 query potential in model methods

**Cosmetic (1)**:
1. routes/channels.php:10 - Using `find()` instead of `findOrFail()` (minor inconsistency)

**Verdict**: No Laravel blocking issues. All findings are technical debt, not production blockers.

---

### STATIC_ANALYZER
**Confidence**: A
**PHPStan**: 0 errors ✅
**Pint**: Cosmetic violations only ✅
**Blocking Issues**: 0

**PHPStan Results**:
- **Total Errors**: 0
- **Blocking Errors**: 0
- **Technical Debt**: 0

**Pint Results**:
- **Total Violations**: 80+ (cosmetic)
- **T140.5 Files**: Minimal violations
- **Category**: Style/formatting (never blocking)
- **Example Files**: factories, migrations, seeders, test files

**Rector Status**:
- **Configured**: Yes
- **Active Rules**: 0
- **Dry Run**: No changes

**Verdict**: Static analysis passes. No blocking issues. Style violations can be addressed in separate cleanup task.

---

## Cross-Agent Conflict Resolution

### Conflict: LoopMember Queries Without Organization Scope
- **REVIEW_ARCHITECT**: False positive (guard-before-query is acceptable)
- **TENANT_SAFETY_REVIEWER**: False positive (LoopMember queries are scoped to Loop first)
- **Resolution**: ✅ **AGREED** - All LoopMember queries are protected by upstream Loop guards

### Conflict: RewardDispatcher Organization Scoping
- **REVIEW_ARCHITECT**: True risk (medium severity, defense in depth)
- **TENANT_SAFETY_REVIEWER**: True risk (high severity, cross-org vector)
- **Resolution**: ✅ **AGREED** - This is a blocking issue requiring immediate fix

### Conflict: Model Binding Enumeration
- **REVIEW_ARCHITECT**: Not identified
- **TENANT_SAFETY_REVIEWER**: True risk (high severity)
- **LARAVEL_REVIEWER**: Technical debt
- **Resolution**: ⚠️ **UPGRADED TO BLOCKING** - Model binding enumeration is a security issue, not just technical debt

---

## Tenant Safety Analysis

### Protected Patterns (✅ Working)
1. **Guard-before-query**: Loop checks before LoopMember queries
2. **Explicit organization checks**: `$loop->organization_id !== $community->id`
3. **Broadcast channel guards**: Organization verification before WebSocket authorization
4. **Service layer protection**: LoopService enforces org matching in `addMember()`

### Vulnerability Vectors (❌ Blockers)
1. **Model binding enumeration**: 8 locations in LoopController
2. **WebSocket enumeration**: 1 location in channels.php
3. **Cross-org referral processing**: 3 locations in RewardDispatcher

### Risk Assessment
| Vector | Severity | Exploitability | Impact | Priority |
|--------|----------|----------------|--------|----------|
| Model binding enumeration | HIGH | HIGH (trivial enumeration) | MEDIUM (info disclosure) | URGENT |
| WebSocket enumeration | HIGH | HIGH (trivial enumeration) | MEDIUM (info disclosure) | URGENT |
| RewardDispatcher cross-org | HIGH | LOW (requires data corruption) | HIGH (points leakage) | HIGH |

---

## Technical Debt Summary

### Migration-Related Debt (Intentional)
- `User.php`: Both `organization_id` and `community_id` fields (migration compatibility)
- `ResolveApiOrganization.php`: Dual binding of `current_organization` and `current_community`
- `LoopController:26, 52`: Methods named with "Community" terminology

### Code Quality Debt (Future Work)
- Missing type hints on service methods (5 locations)
- Inline validation in controllers (2 locations)
- N+1 query potential in model methods (2 locations)
- Unused import in LoopController:6

### Style Debt (Pint)
- 80+ cosmetic style violations across factories, migrations, tests
- All non-blocking, can be fixed in separate task

---

## Production Readiness Checklist

| Category | Status | Notes |
|----------|--------|-------|
| **Security** | ❌ BLOCKED | 3 blocking tenant safety issues |
| **Tenant Isolation** | ⚠️ PARTIAL | Guards exist but enumeration possible |
| **Type Safety** | ✅ PASS | PHPStan clean, minor type hints missing |
| **Code Quality** | ✅ PASS | Laravel conventions followed |
| **Static Analysis** | ✅ PASS | PHPStan 0 errors |
| **Migration Coherence** | ✅ PASS | Organization-scoping consistent |
| **Data Integrity** | ❌ RISK | Cross-org referral processing possible |
| **Reconnaissance** | ❌ RISK | Model binding enumeration possible |

---

## Recommendations

### Critical (Must Fix Before Production)
1. **RewardDispatcher organization scoping** (3 lines)
   - Add `where('organization_id', ...)` to all Referral queries
   - Estimated effort: 5 minutes

2. **Loop model binding scoping** (7 controller methods)
   - Implement `Loop::resolveRouteBinding()` OR convert to manual queries
   - Estimated effort: 15 minutes

3. **WebSocket channel enumeration** (1 file)
   - Add organization filter to `Loop::find()` in channels.php
   - Estimated effort: 2 minutes

### High Priority (Should Fix Soon)
4. **LoopController Policies** (7 methods)
   - Extract authorization logic to `LoopPolicy`
   - Estimated effort: 1 hour

5. **Form Request Validation** (2 controller methods)
   - Extract validation to Form Request classes
   - Estimated effort: 30 minutes

### Medium Priority (Technical Debt)
6. **Type hints** (5 service methods)
   - Add return types to service methods
   - Estimated effort: 15 minutes

7. **Method naming** (2 controller methods)
   - Rename `resolveCommunity()` to `resolveOrganization()`
   - Estimated effort: 5 minutes

### Low Priority (Cosmetic)
8. **Pint style fixes** (80+ files)
   - Run `./vendor/bin/pint`
   - Estimated effort: Automated, 1 minute

---

## Next Steps

### Option A: Fix Critical Issues (Recommended)
Create T140.5H task to fix 3 blocking issues:
1. RewardDispatcher organization scoping
2. Loop model binding enumeration
3. WebSocket channel enumeration

Estimated total effort: 30 minutes

### Option B: Defect Fixes to Production Team
Hand off blocking issues to production team with clear documentation and code examples.

### Option C: Accept Risk (NOT RECOMMENDED)
Document acceptance of cross-org referral processing and enumeration risks.
⚠️ **This is strongly discouraged** - violations allow reconnaissance and data leakage.

---

## Appendix: Blocking Issue Code Examples

### Issue 1: RewardDispatcher:64
```php
// BEFORE (VULNERABLE)
$referral = Referral::where('referred_user_id', $event->user->id)
    ->where('status', 'pending')
    ->where('depth', 1)
    ->first();

// AFTER (FIXED)
$orgId = $event->user->organization_id ?? $event->user->community_id;
$referral = Referral::where('referred_user_id', $event->user->id)
    ->where('status', 'pending')
    ->where('depth', 1)
    ->where('organization_id', $orgId) // ADD THIS
    ->first();
```

### Issue 2: LoopController:129 (Implicit Binding)
```php
// BEFORE (VULNERABLE - enumeration)
public function show(Loop $loop): View  // Loads loop by ID first!
{
    $community = $this->resolveCommunity();
    $this->assertUserBelongsToCommunity($community);

    if ($loop->organization_id !== $community->id) {
        abort(404);  // Too late, loop already loaded
    }
    // ...
}

// AFTER (FIXED - no enumeration)
public function show(string $loopId): View
{
    $community = $this->resolveCommunity();
    $this->assertUserBelongsToCommunity($community);

    $loop = Loop::where('id', $loopId)
        ->where('organization_id', $community->id)
        ->firstOrFail();  // 404 without loading cross-org loops

    // ...
}
```

### Issue 3: channels.php:10
```php
// BEFORE (VULNERABLE - enumeration)
$loop = Loop::find($loopId);  // Loads loop by ID first!

if (! $loop) {
    return false;
}

// ... membership checks ...

if ($loop->organization_id !== $orgId) {
    return false;  // Too late, loop already loaded
}

// AFTER (FIXED - no enumeration)
$orgId = $user->organization_id ?? $user->community_id;
$loop = Loop::where('id', $loopId)
    ->where('organization_id', $orgId)  // ADD THIS
    ->first();

if (! $loop) {
    return false;
}

// ... membership checks (org already guaranteed)
```

---

## Sign-Off

**Review Cluster Lead**: REVIEW_CLUSTER (T140.5G)
**Agents Utilized**:
- REVIEW_ARCHITECT ✅
- TENANT_SAFETY_REVIEWER ✅
- LARAVEL_REVIEWER ✅
- STATIC_ANALYZER ✅

**Overall Verdict**: 🟡 **ATTENTION**
**Ready for Production**: ❌ **NO**
**Estimated Time to Fix**: **30 minutes** (critical issues only)

**Decision Required**: Create T140.5H to fix 3 blocking security issues OR defer to production team with explicit risk acceptance documentation.