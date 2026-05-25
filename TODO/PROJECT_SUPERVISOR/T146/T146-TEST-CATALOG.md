# T146-TEST-CATALOG.md — Playwright Greenfield Test Catalog

## Summary

| Total | PASS | FAIL | BLOCKED | SKIPPED |
|-------|------|------|---------|---------|
| 37 | 37 | 0 | 0 | 0 |

## Auth Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-001 | Auth | P0 | qa-admin | /login | Login admin, verify authenticated | PASS | — | auth/admin-login.spec.js |
| T146-002 | Auth | P0 | qa-member1 | /login | Login M1, verify authenticated | PASS | — | auth/member1-login.spec.js |
| T146-003 | Auth | P0 | qa-member2 | /login | Login M2, verify authenticated | PASS | — | auth/member2-login.spec.js |
| T146-004 | Auth | P0 | qa-admin | /logout | Logout admin, verify session cleared | PASS | — | auth/admin-login.spec.js |
| T146-005 | Auth | P0 | Guest | /dashboard | Guest → /dashboard redirects | PASS | — | auth/guest-redirect.spec.js |
| T146-006 | Auth | P0 | Guest | /admin/dashboard | Guest → /admin redirects | PASS | — | auth/guest-redirect.spec.js |
| T146-046 | Auth | P0 | qa-member1 | /admin/dashboard | Member → /admin gets 403 | PASS | — | auth/guest-redirect.spec.js |

## Service Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-007 | Service | P0 | qa-member1 | /services/create | Access creation form | PASS | — | services/create.spec.js |
| T146-008 | Service | P0 | qa-member1 | /services/create, POST | Create micro-service | PASS | — | services/create.spec.js |
| T146-010 | Service | P0 | qa-member1 | /explorer | Verify service in explorer | PASS | — | services/explorer.spec.js |
| T146-011 | Service | P0 | qa-member1 | /services/{id} | Show service detail | PASS | — | services/explorer.spec.js |
| T146-012 | Service | P0 | qa-member1 | /services/{id}/edit | Edit own service | PASS | — | services/edit.spec.js |
| T146-014 | Service | P0 | qa-member2 | /services/{id}/edit | M2 cannot edit M1 service | PASS | — | services/edit.spec.js |

## Request Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-015 | Request | P0 | qa-member1 | /requests/create | Access creation form | PASS | — | requests/create.spec.js |
| T146-016 | Request | P0 | qa-member1 | /requests/create, POST | Create help request | PASS | — | requests/create.spec.js |

## Transaction Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-018 | Transaction | P0 | qa-member2 | /services/{id} | M2 views M1's service | PASS | — | transactions/full-cycle.spec.js |
| T146-019 | Transaction | P0 | qa-member2 | /messages/{tx} | M2 proposes transaction | PASS | — | transactions/full-cycle.spec.js |

## Blog Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-027 | Blog | P0 | qa-member1 | /blog/rediger/nouveau | Access article creation | PASS | — | blog/create.spec.js |
| T146-028 | Blog | P0 | qa-member1 | /blog/rediger/nouveau, POST | Create published article | PASS | — | blog/create.spec.js |
| T146-029 | Blog | P0 | Guest | /blog | Published article visible on /blog | PASS | — | blog/public.spec.js |

## Loop Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-033 | Loop | P0 | qa-member1 | /{slug}/loops/create | Access loop creation | PASS | — | loops/create.spec.js |
| T146-034 | Loop | P0 | qa-member1 | /{slug}/loops/create, POST | Create loop | PASS | — | loops/create.spec.js |

## Admin Dashboard Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-038 | Admin | P0 | qa-admin | /admin/dashboard | Admin dashboard loads | PASS | — | dashboard/admin.spec.js |
| T146-039 | Admin | P0 | qa-admin | /admin/users | Users page | PASS | — | dashboard/admin.spec.js |
| T146-040 | Admin | P0 | qa-admin | /admin/services | Services page | PASS | — | dashboard/admin.spec.js |
| T146-041 | Admin | P0 | qa-admin | /admin/requests | Requests page | PASS | — | dashboard/admin.spec.js |
| T146-042 | Admin | P0 | qa-admin | /admin/transactions | Transactions page | PASS | — | dashboard/admin.spec.js |
| T146-043 | Admin | P0 | qa-admin | /admin/blog | Blog management | PASS | — | dashboard/admin.spec.js |
| T146-044 | Admin | P0 | qa-admin | /admin/loops | Loop management | PASS | — | dashboard/admin.spec.js |
| T146-045 | Admin | P0 | qa-admin | /admin/settings | Settings page | PASS | — | dashboard/admin.spec.js |

## Member Dashboard Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-047 | Dashboard | P0 | qa-member1 | / | Member authenticated after login | PASS | — | dashboard/member.spec.js |
| T146-048 | Dashboard | P0 | qa-member1 | /explorer | Service visible in explorer | PASS | — | dashboard/member.spec.js |
| T146-049 | Dashboard | P0 | qa-member1 | /requests/create, POST | Request created successfully | PASS | — | dashboard/member.spec.js |

## Public Visitor Flows

| ID | Category | Prio | Account | Route(s) | Action Tested | Status | Bug | File |
|----|----------|------|---------|----------|---------------|--------|-----|------|
| T146-053 | Visitor | P1 | Guest | / | Homepage loads | PASS | — | smoke.spec.js |
| T146-054 | Visitor | P1 | Guest | /explorer | Explorer page loads | PASS | — | smoke.spec.js |
| T146-055 | Visitor | P1 | Guest | /membres | Members page loads | PASS | — | smoke.spec.js |
| T146-056 | Visitor | P1 | Guest | /register | Register page loads | PASS | — | smoke.spec.js |
| T146-057 | Visitor | P1 | Guest | /forgot-password | Password reset page loads | PASS | — | smoke.spec.js |
| T146-060 | Visitor | P2 | Guest | /, /explorer, /membres, /blog | No console errors on public pages | PASS | — | smoke.spec.js |
