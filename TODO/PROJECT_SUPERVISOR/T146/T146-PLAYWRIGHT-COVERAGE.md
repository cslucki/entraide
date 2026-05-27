# T146 Playwright Coverage Plan

## Test Structure

```
tests/e2e/t146/
├── helpers/
│   ├── auth.js              # Login helper (org-resolved, .env accounts)
│   ├── cleanup.js           # Data naming prefix QA-T146-[timestamp]
│   ├── screenshots.js       # Screenshot capture helper
│   └── console-errors.js    # Console error assertion helper
├── auth/
│   ├── admin-login.spec.js
│   ├── member1-login.spec.js
│   └── member2-login.spec.js
├── services/
│   ├── create.spec.js
│   ├── show.spec.js
│   ├── edit.spec.js
│   └── explorer.spec.js
├── requests/
│   ├── create.spec.js
│   └── show.spec.js
├── transactions/
│   ├── buyer-flow.spec.js
│   ├── seller-flow.spec.js
│   └── messaging.spec.js
├── blog/
│   ├── create.spec.js
│   └── public.spec.js
├── loops/
│   └── create.spec.js
├── dashboard/
│   ├── member.spec.js
│   └── admin.spec.js
└── smoke.spec.js            # Quick smoke: only visits business pages
```

## P0 Tests (T146 scope)

| # | File | Priority | Account | Data |
|---|------|----------|---------|------|
| 1 | auth/admin-login.spec.js | **P0** | qa-admin@bouclepro.local | — |
| 2 | auth/member1-login.spec.js | **P0** | qa-member1@bouclepro.local | — |
| 3 | auth/member2-login.spec.js | **P0** | qa-member2@bouclepro.local | — |
| 4 | services/create.spec.js | **P0** | qa-member1@bouclepro.local | QA-T146-[ts]-service |
| 5 | dashboard/member.spec.js | **P0** | qa-member1@bouclepro.local | Verify service in dashboard |
| 6 | services/explorer.spec.js | **P0** | qa-member1@bouclepro.local | Verify service in /explorer |
| 7 | services/show.spec.js | **P0** | qa-member1@bouclepro.local | Show detail page |
| 8 | services/edit.spec.js | **P0** | qa-member1@bouclepro.local | Edit service |
| 9 | requests/create.spec.js | **P0** | qa-member1@bouclepro.local | QA-T146-[ts]-request |
| 10 | dashboard/member.spec.js | **P0** | qa-member1@bouclepro.local | Verify request in dashboard |
| 11 | transactions/buyer-flow.spec.js | **P0** | qa-member1@bouclepro.local | Buy service from member 2 |
| 12 | transactions/buyer-flow.spec.js | **P0** | qa-member1@bouclepro.local | Verify buyer status |
| 13 | transactions/seller-flow.spec.js | **P0** | qa-member2@bouclepro.local | Verify seller status |
| 14 | transactions/messaging.spec.js | **P0** | Both | Transaction chat |
| 15 | blog/create.spec.js | **P0** | qa-member1@bouclepro.local | QA-T146-[ts]-article |
| 16 | blog/public.spec.js | **P0** | Visitor | Verify on /blog |
| 17 | dashboard/admin.spec.js | **P0** | qa-admin@bouclepro.local | Verify article in admin |
| 18 | loops/create.spec.js | **P0** | qa-member1@bouclepro.local | QA-T146-[ts]-loop |
| 19 | dashboard/member.spec.js | **P0** | qa-member1@bouclepro.local | Verify loop in dashboard |
| 20 | dashboard/admin.spec.js | **P0** | qa-admin@bouclepro.local | Admin pages |

## P1 Tests (future)

| # | Flow | Reason |
|---|------|--------|
| 21 | Registration | Outside T146 scope |
| 22 | Password reset | Outside T146 scope |
| 23 | Loop member management | P1 variant |
| 24 | Service delete | P1 variant |
| 25 | Request response flow | P1 variant |
| 26 | Favorites | P1 variant |
| 27 | Points history | P1 variant |
| 28 | Profile edit | P1 variant |
| 29 | Admin settings | P1 variant |

## Non-Goals (Legacy / Out of scope)

- community-transactions/* tests (legacy)
- Community-prefixed URLs (`/org/{slug}/...`)
- Old test accounts (`test@example.com`, `admin@example.com`)
- Old UUID references
