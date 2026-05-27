# T146 MASTER — Greenfield Playwright Business Interaction Suite

## Objective

Create a clean, documented, reusable Playwright test suite for critical BouclePro business flows.
Greenfield approach: no logical dependency on legacy community-transactions tests.

## Doctrine

- Organization = Tenant, Loop ≠ Tenant
- Member = user within an Organization
- Interaction = business action between members
- Community/community_id = legacy temporary — NOT used in new tests
- Use Organization / Loop / Member / Interaction vocabulary

## Accounts (from .env)

| Role | Login | Password |
|------|-------|----------|
| Admin | qa-admin@bouclepro.local | password123 |
| Member 1 | qa-member1@bouclepro.local | password123 |
| Member 2 | qa-member2@bouclepro.local | password123 |

## P0 Flows (T146 scope)

1. Login admin (qa-admin@bouclepro.local)
2. Login member 1 (qa-member1@bouclepro.local)
3. Login member 2 (qa-member2@bouclepro.local)
4. Create micro-service (member 1)
5. Verify micro-service in dashboard (member 1)
6. Verify micro-service in /explorer
7. Show micro-service
8. Edit micro-service
9. Create help request (member 1 or 2)
10. Verify help request in dashboard
11. Transaction between member 1 & member 2
12. Verify transaction status (buyer)
13. Verify transaction status (seller)
14. Transaction messaging
15. Create blog article
16. Verify article on /blog
17. Verify article in admin dashboard
18. Create a loop
19. Verify loop created
20. Verify admin pages

## Final Gates

- [ ] php artisan route:cache OK
- [ ] php artisan optimize OK
- [ ] npm run build OK
- [ ] php artisan test OK (or documented pre-existing reserves)
- [ ] New Playwright T146 suite OK
- [ ] No dependency on legacy accounts/UUIDs
- [ ] Screenshots/traces recorded
- [ ] Console errors analyzed
- [ ] T146-INTERACTION-MATRIX.md filled
- [ ] T146-PLAYWRIGHT-COVERAGE.md filled
- [ ] T146-FINAL-REPORT.md filled
- [ ] TASK file DONE only after gates passed

## Branch

`TASK-146-greenfield-playwright-business-interaction-suite`
