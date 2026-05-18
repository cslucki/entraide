---
task_id: TASK-098
title: t075-19-boucles-partners-product-routing-arbitration

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-098-t075-19-boucles-partners-product-routing-arbitration

priority: HIGH

created_at: 2026-05-18 00:44:55 Europe/Paris
updated_at: 2026-05-18 04:55:21 Europe/Paris

labels:
  - T075
  - product-arbitration
  - routing
  - documentation

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create a product/routing arbitration task for the T75 migration stream to fix the trajectory of:

- `/boucles`
- `/boucles/creer`
- `/partenaires`
- `/partenaires/demande`
- current navigation
- ambiguous visible vocabulary
- legacy admin surfaces still named Community

This is a documentation/arbitration task only. It must produce clear decisions and handoff notes before any future runtime, UI, admin, DB/runtime, or Playwright work starts.

---

# Strict Scope

Allowed in this task:

- create this TASK file
- create the dedicated task branch
- document product/routing arbitration questions
- document architecture rules and non-negotiables
- document decisions, future tasks, and handoffs when arbitration is complete

Forbidden in this task:

- no runtime code changes
- no route creation
- no controller changes
- no view changes
- no test changes
- no migration
- no API changes
- no policy changes
- no middleware changes
- no PROD change
- no complete Partner module creation
- no `/partenaires` implementation
- no `/partenaires/demande` implementation
- no `/boucles` implementation change
- no `/loops` removal in this task
- no `/organisation` implementation
- no transformation of Partner into Tenant
- no transformation of Loop into Tenant
- no reintroduction of Community as an active product concept

---

# Architecture Rules

- Organization = Tenant.
- Loop != Tenant.
- Partner != Tenant.
- Partner = co-branding / distribution.
- Public != global.
- Root domain is not tenantless.
- No public URL in English.
- No public UI copy in English.
- Internal architecture/code concepts may remain in English when needed.
- Public Loop wording becomes `boucle` / `boucles`.
- Public Partner wording becomes `partenaire` / `partenaires`.
- Public Organization wording becomes `organisation`.
- Public English URLs are forbidden: `/loops`, `/partners`, `/organization`.
- `/boucles` is currently an ambiguous legacy Community surface, but Option A reserves it as the future canonical French public route for true Boucles.
- `/boucles` must be progressively recovered for true Boucles, not abandoned to legacy Community and not redirected toward partners.
- `/partenaires` is a possible target route, but must not become a complete module in T075.19.
- `/partenaires/demande` is the recommended future partner request route.
- `current_organization` is the canonical runtime source.
- `organization_id` is canonical for new code.
- `community_id` remains only a legacy transition DB column.
- Do not introduce new Community vocabulary as an active product concept.

---

# Arbitration Questions

- Should `/boucles` remain temporarily accessible?
- Should `/boucles` be progressively recovered as the canonical French route for true Boucles?
- How should `/loops` be removed/replaced publicly in a future French routing task?
- Should `/boucles/creer` become a partner request flow, move to `/partenaires/demande`, or be disabled?
- Should `/partenaires` and `/partenaires/demande` be created during a future French UI/routing task?
- What is the minimum viable change to reduce confusion without creating a complete Partner module?
- Which Community-named admin routes must remain legacy until the DB migration is complete?
- Which visible vocabulary corrections must be reserved for T076 UI?
- Which Playwright tests must cover this decision in T075.20 or a dedicated task?
- Which final handoff must be transmitted to the T75 Final Handoff?

---

# Required Decisions Before Completion

- [x] decision on `/boucles`
- [x] decision on `/boucles/creer`
- [x] decision on `/partenaires`
- [x] decision on current navigation
- [x] decision on visible vocabulary
- [x] decision on legacy admin naming
- [x] recommended future tasks
- [x] explicit out-of-scope list confirmed
- [x] handoff to T076 UI if needed
- [x] handoff to T076 Admin if needed
- [x] handoff to DB/runtime task if needed
- [x] handoff to Playwright tests task if needed

---

# Planned Actions

- [x] verify `develop` is current and contains T075.18 merge commit `c972a01`
- [x] create official task branch via `ai/scripts/create-task.sh`
- [x] create and enrich this TASK file with strict scope, architecture rules, questions, and deliverables
- [x] arbitrate `/boucles`, `/boucles/creer`, `/partenaires`, `/partenaires/demande`, `/loops`, navigation, visible vocabulary, and admin legacy naming
- [x] document final decisions and future task handoffs
- [x] document that no runtime files were modified

---

# Future Handoff Targets

- T076 UI/routing français: visible navigation, French public URL, and vocabulary corrections that should not be implemented in T075.19.
- T076 Admin: admin surface naming cleanup where Community remains a legacy technical label.
- DB/runtime task: any future `community_id` to `organization_id` migration or runtime compatibility work.
- Playwright task: coverage for final route/navigation decisions in T075.20 or a dedicated test task.

---

# Out Of Scope Confirmation

T075.19 must remain product/routing arbitration only. It must not create `/partenaires`, create `/partenaires/demande`, alter `/boucles`, remove `/loops`, create `/organisation`, modify admin runtime behavior, or change tenant resolution.

---

# Decision Summary

T075.19 confirms the following arbitration without runtime implementation:

- Option A Cyril/COCKPIT is validated: `/boucles` becomes or remains the canonical French public route for the true Boucles concept.
- `/boucles` is currently an ambiguous legacy Community surface, but it is reserved for true Boucles and must be progressively recovered instead of abandoned to legacy Community.
- `/boucles/creer` remains temporarily unchanged. Its partner acquisition intent should move later toward `/partenaires/demande` with CTA "Devenir partenaire", or be deactivated if the product flow is not ready.
- `/partenaires` and `/partenaires/demande` must not be created in T075.19. They are future French public route candidates for co-branding/distribution and partner requests, not tenant modules.
- `/loops`, `/partners`, and `/organization` are public English URL debt/prohibited public URL forms. `/loops` must be removed or replaced publicly in a future task, not in T075.19.
- The visible navigation label "Boucles" is ambiguous today because public `/boucles` is legacy while authenticated navigation can point to English `/loops`. T076 UI/routing français must resolve this.
- Admin surfaces named Community remain legacy temporarily because they still protect active runtime behavior around `communities`, `community_id`, `Community`, and tested route names.
- No route, controller, view, test, migration, API, policy, middleware, or production runtime file is modified by this task.

---

# Current State

## Sources Reviewed

- `TODO/TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit.md`
- `docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md`
- `docs/06-DOMAIN_ARCHITECTURE_V2.md`
- `docs/07-GLOSSARY.md`
- `docs/08-COMMUNITY_MIGRATION_STRATEGY.md`
- `docs/09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`

## Existing Route State From T075.18

- `GET /boucles` uses `HomeController@boucles`, route name `boucles.index`.
- `GET /boucles/creer` uses `CommunityRequestController@create`, route name `boucles.request.create`.
- `POST /boucles/creer` uses `CommunityRequestController@store`, route name `boucles.request.store`.
- `/partners` does not currently exist in `routes/web.php` or `php artisan route:list` per T075.18, and must not become a public route because public English URLs are prohibited.
- `/partenaires` and `/partenaires/demande` do not exist yet and must not be created in T075.19.
- `/loops` exists as public English routing debt and must be removed/replaced publicly in a future task.
- Admin legacy route names include `admin.communities.*`, `admin.meta-community`, `admin.meta-community.update`, and `admin.users.assign-community`.

## Architecture Baseline

- Organization is the tenant and security boundary.
- Loop is an internal collaborative group inside an Organization, not a tenant.
- Partner is a co-branding/distribution entry, not a tenant.
- Public route/UI vocabulary must be French: Boucle, Partenaire, Organisation.
- Internal concepts may remain English in architecture/code where necessary.
- Public does not mean global; public business routes may still be Organization-scoped.
- Root domain is not tenantless for business routes.
- Community, `community_id`, and `current_community` remain temporary technical legacy terms only.
- `current_organization` is the canonical runtime source for future runtime work.

---

# Product Arbitration

## A. `/boucles`

Current status:

- `/boucles` is currently a public legacy surface that lists active `Community` records.
- The page currently presents "Les Boucles" while the underlying implementation and data still come from the legacy Community model/table.
- Option A changes the target trajectory: `/boucles` must be progressively recovered as the canonical French public route for true Boucles.
- `/boucles` must not become a partner route and must not redirect to `/partenaires` as a target strategy.

Short-term recommended decision:

- Keep `/boucles` accessible temporarily to avoid breaking existing links, tests, route names, and user expectations.
- Document clearly that the current implementation is legacy/ambiguous, while the route name is reserved for true Boucles.
- Do not abandon `/boucles` to legacy Community and do not move partners onto `/boucles`.

Medium-term recommended decision:

- Recover `/boucles` progressively as the canonical French public route for true Boucles.
- Replace public English `/loops` with French routing in a dedicated future task.
- Move partner acquisition/distribution intent away from `/boucles` toward `/partenaires` and `/partenaires/demande` in a future task.
- Do not redirect `/boucles` to `/partenaires`; their concepts must remain separate.

Risks of confusion:

- The French visible label "Boucles" maps to the canonical Loop concept, but the route does not display true Loops.
- Guest users see "Boucles" for `/boucles`; authenticated users can see "Boucles" for `/loops`, creating two different meanings for the same visible word.
- The page text still references "Communautés", adding a third concept to the same surface.
- `/loops` exposes a public English URL for a concept that must be public French `boucles`.
- Creating redirects or renames too early could break `boucles.index`, existing tests, SEO/bookmarks, and legacy `/{community}` assumptions.

Why this is not a true Loop screen:

- True Loops are collaborative groups inside an Organization.
- `/boucles` is root-level/public and lists legacy Community records rather than Organization-scoped Loop records.
- `/boucles` does not represent membership, internal collaboration, Loop-scoped permissions, or Organization-internal grouping.
- This is a current-state warning, not the target direction: the route `/boucles` remains reserved for the true Boucles concept.

## B. `/boucles/creer`

Current status:

- `/boucles/creer` is a legacy public request form rendered by `CommunityRequestController`.
- It collects a `boucle_name`, contact details, description, and context, then creates a `CommunityRequest`.
- The visible wording asks users to create a "boucle", which conflicts with both the current legacy implementation and the future true Boucles routing target.

Recommended decision:

- Keep `/boucles/creer` unchanged in T075.19.
- Do not redirect, delete, rename, or repurpose it in this task.
- Treat its partner acquisition intent as a future move to `/partenaires/demande` if Cyril validates the flow.
- Keep `/boucles/creer` out of the partner target strategy long term because `/boucles` is reserved for true Boucles.

Possible target wording for T076 UI:

- "Devenir partenaire" is the validated target CTA for a partner request flow.
- "Créer un espace partenaire" must be avoided because "espace" is ambiguous and can blur Organization / Tenant / Partner / Boucle.
- Temporary deactivation remains acceptable if the partner flow is not ready.

What must wait for T076 UI:

- Final label choice.
- Form copy and CTA rewrite.
- Route move/preparation toward `/partenaires/demande`.
- Navigation changes from "Créer ma boucle" toward "Devenir partenaire" where the intent is partner acquisition.
- Desktop/mobile/dark-mode validation and screenshots.
- Any public explanation that Partner is distribution/co-branding and not a tenant.

## C. `/partenaires`

Should T075.19 create `/partenaires` or `/partenaires/demande`?

- No.
- T075.19 must only document the target and future task boundaries.
- `/partenaires` and `/partenaires/demande` should only be created or prepared in a dedicated French UI/routing implementation task if Cyril validates product scope, route behavior, UI copy, and tests.
- `/partners` must not be created because public English URLs are prohibited.

Target role of `/partenaires` and `/partenaires/demande`:

- `/partenaires`: French public route candidate for co-branding/distribution discovery.
- `/partenaires/demande`: recommended French public route for partner requests.
- CTA target: "Devenir partenaire".
- A clear product separation from `/boucles`, which is reserved for true Boucles.
- A possible landing for organizations distributed through partner/co-branded contexts, without making Partner the tenant.

Why Partner is not a tenant:

- Organization remains the data, security, billing, and governance boundary.
- Partner may point to or promote an Organization, but does not own tenant isolation.
- Partner slug routing can resolve an Organization context, but the resolved tenant remains the Organization.

Why `/partenaires` must not become a complete business module now:

- There is no validated Partner runtime model, policy set, admin lifecycle, route matrix, or Playwright coverage in T075.19.
- Creating a module now would mix product arbitration, routing, UI, DB/runtime, admin, and tests in one task.
- Premature implementation would risk confusing Partner with Organization or Loop.
- French public routing must be designed as a dedicated task, not introduced opportunistically inside T075.19.

---

# Routing Arbitration

- Keep `/boucles` as a legacy route for now.
- Keep `/boucles/creer` as a legacy route for now.
- Reserve `/boucles` as the future canonical French public route for true Boucles.
- Treat `/loops` as public English routing debt to remove/replace in a future task.
- Do not create `/partenaires` in T075.19.
- Do not create `/partenaires/demande` in T075.19.
- Do not create `/organisation` in T075.19.
- Do not add redirects in T075.19.
- Do not modify reserved slugs, route names, middleware, controllers, or views in T075.19.
- Future `/partenaires`, if approved, should be treated as a platform-global French route candidate, not as an Organization-scoped business module by default.
- Future partner request flow should target `/partenaires/demande` with CTA "Devenir partenaire".
- Future partner slug routes remain separate from `/partenaires`: `/{partnerSlug}/{feature}` can resolve the Organization linked to a partner slug, while `/partenaires` itself can be a platform-global discovery/acquisition page.
- `/organization` is prohibited publicly; `/organisation` and `/organisation/demande` may be considered later only in French routing/product tasks, not T075.19.

---

# Admin Legacy Arbitration

Legacy admin surfaces to keep temporarily:

- `admin.communities.*`
- `AdminCommunityController`
- `admin.meta-community`
- `AdminMetaCommunityController`
- `admin.users.assign-community`
- Admin views under `resources/views/admin/communities/*`
- Admin user assignment UI that still exposes Community wording and `community_id`
- Legacy tests around `AdminCommunitiesTest` and `AdminUsersTest`

Why they must not be renamed brutally now:

- They still manipulate `Community`, `communities`, `community_id`, and compatibility synchronization with `organization_id`.
- Route names are referenced by Blade, controllers, and tests.
- A route/controller rename before DB/runtime migration would create aliases, duplication, or broken links without reducing conceptual risk.
- The migration plan requires layer-by-layer changes; views/admin naming should not jump ahead of database/runtime readiness.

Future tasks for admin renaming:

- T076 Admin should define target admin IA and labels for Organization/Partner/Platform settings.
- A DB/runtime task should first reduce direct dependence on `community_id` and `Community` where safe.
- A later admin implementation task may introduce Organization-named routes/controllers with compatibility aliases only if required.
- Playwright/admin feature tests should be updated after runtime route decisions are implemented, not during T075.19.

---

# UI Vocabulary Handoff

Ambiguities remaining:

- "Boucles" means legacy `/boucles` for guests but true `/loops` for authenticated users.
- "Communautés" appears on the public `/boucles` surface and in admin screens although Community is no longer an active product concept.
- "Meta-Communauté" likely represents platform-level settings rather than a tenant or active product concept.
- `/loops`, `/partners`, and `/organization` are public English URL forms and must not remain target public routing.
- "Créer un espace partenaire" is ambiguous and should not be used as target CTA.

Navigation decision:

- The visible public navigation label "Boucles" may remain if it points to the true Boucles concept after cleanup.
- Public partner/acquisition navigation should move toward "Partenaires" / "Devenir partenaire" only through `/partenaires` and `/partenaires/demande`.
- Authenticated true Boucles navigation must stop depending on public English `/loops` in a future routing task.
- Partner navigation must not reuse `/boucles`.

What belongs to T076 UI:

- Public nav label and URL changes with French-only public routing.
- Landing/home CTAs such as "Créer ma boucle".
- `/boucles` page title/subtitle/card wording so it reflects true Boucles after cleanup.
- `/boucles/creer` form title, CTA, explanatory copy, move to `/partenaires/demande`, or potential deactivation message.
- `/partenaires` and `/partenaires/demande` visible copy if those routes are approved.
- Verification that no public UI copy remains in English.
- Visual validation across desktop, mobile, and dark mode.

What belongs to T076 Admin:

- Admin sidebar labels "Communautés", "Boucles", and "Meta-Communauté".
- Admin Communities CRUD wording.
- Admin Users assignment wording.
- Platform settings naming for the current Meta-Communauté surface.

---

# Future Tasks

- Future French UI/routing task: remove/replace public `/loops`, create or prepare `/partenaires`, create or prepare `/partenaires/demande`, clean `/boucles` for true Boucles, and verify no public English UI/URL remains.
- T076 UI: decide and implement public vocabulary/navigation changes around `/boucles`, `/boucles/creer`, `/partenaires`, `/partenaires/demande`, and "Devenir partenaire".
- T076 Admin: define and implement controlled admin wording/IA changes without breaking legacy runtime route names prematurely.
- Dedicated `/partenaires` routing task: create `/partenaires` and `/partenaires/demande` only after product validation, route behavior definition, and test plan approval.
- DB/runtime Community migration task: continue `communities` / `community_id` / `current_community` migration toward Organization-native runtime.
- Playwright tests task: add route/navigation/product vocabulary coverage after implementation tasks exist.
- T75 Final Handoff: include this arbitration as the canonical decision that T075.19 produced no runtime change, no `/partenaires` implementation, no `/partenaires/demande` implementation, no `/boucles` change, no `/loops` removal, and no admin rename.

---

# Out of Scope

Confirmed out of scope for T075.19:

- Creating `/partenaires`.
- Creating `/partenaires/demande`.
- Modifying `/boucles`.
- Modifying `/boucles/creer`.
- Removing or replacing `/loops`.
- Creating `/organisation` or `/organisation/demande`.
- Adding redirects.
- Renaming `admin.communities`.
- Renaming `AdminCommunityController`.
- Changing controllers, routes, views, tests, migrations, APIs, policies, middleware, or production runtime files.
- Launching Community to Organization DB/runtime migration.
- Introducing Community as a new active product concept.
- Turning Partner into a tenant.
- Turning Loop into a tenant.
- Creating any public English URL target such as `/loops`, `/partners`, or `/organization`.

---

# Tests / Validation

## Executed In T075.19

- Documentation-only review of the listed task/docs.
- No PHPUnit tests run because no runtime, route, controller, view, or test file changed.
- No Playwright tests run because no UI/runtime behavior changed.

## Future Playwright Tests To Create Later

- Guest `/boucles` current legacy behavior remains stable until cleaned in a dedicated task, then `/boucles` reflects true Boucles.
- Future `/partenaires` route, if created, displays partenaire/co-branding/distribution wording and does not imply tenant ownership.
- Future `/partenaires/demande` route, if created, exposes CTA "Devenir partenaire".
- `/partenaires` does not replace or conflict with Organization tenant resolution.
- `/boucles` true Boucles behavior does not get confused with partner surfaces.
- `/loops` is absent or replaced publicly after the future French routing task.
- Navigation labels distinguish partner/acquisition surfaces from true Boucles.
- Guest and authenticated access rules remain explicit for `/boucles`, future `/partenaires`, future `/partenaires/demande`, and replacement of `/loops`.
- Public UI/URLs contain no English public routing vocabulary.
- No console errors and stable responsive rendering for desktop/mobile/dark mode after T076 UI changes.

---

# Review Notes

- OPENAI review verdict: APPROVE WITH NOTES.
- Blocking issues: none.
- T075.19 is an arbitration task only.
- Option A Cyril/COCKPIT supersedes the earlier `/partners` direction: public routing and UI must be French.
- The recommended direction is conservative: preserve current runtime short term, avoid runtime changes, and separate product decisions from implementation.
- The strongest residual risk is visible vocabulary/routing confusion until T076 UI/routing français executes the handoff.
- Cyril has validated "Devenir partenaire" as the target CTA; "Créer un espace partenaire" should be avoided because it is ambiguous.
- Future implementation must use `/partenaires` and `/partenaires/demande`, not `/partners`.
- `/organisation` and `/organisation/demande` are only future possibilities.
- Do not create any public `organisation` flow without dedicated product arbitration, to avoid blurring Organization = Tenant with Partner / Boucle.
- Accepted risk: until T076 UI/routing is completed, `/boucles` remains a legacy ambiguous surface despite its target arbitration.
- This TASK file is the only intended modification for T075.19.

---
# Progress Log


## 2026-05-18 00:44:55 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-098-t075-19-boucles-partners-product-routing-arbitration

Status:
IN_PROGRESS

## 2026-05-18 00:45:12 Europe/Paris

Verified `develop` before task creation:

- current branch before creation: `develop`
- `origin/develop`: up to date
- base commit: `c972a01`
- T075.18 merge present: `c972a01 Merge branch 'TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit' into develop`

Created T075.19 task via official script:

```bash
./ai/scripts/create-task.sh "t075-19-boucles-partners-product-routing-arbitration" OPENCODE
```

Task creation result:

- task ID: `TASK-098`
- branch: `TASK-098-t075-19-boucles-partners-product-routing-arbitration`
- status: `IN_PROGRESS`
- lock: `LOCKED by OPENCODE`

Added strict documentation scope, architecture rules, arbitration questions, required decision checklist, explicit out-of-scope constraints, and future handoff targets.

No runtime implementation started.
No routes, controllers, views, tests, migrations, APIs, policies, middleware, or PROD files were modified.

## 2026-05-18 00:49:17 Europe/Paris

### OPENCODE — Product/routing arbitration documented

- Read T075.19 TASK file and the scoped reference documents requested by Cyril.
- Used T075.18 audit findings as the route/admin/current-state baseline.
- Added documentation-only arbitration sections: Decision Summary, Current State, Product Arbitration, Routing Arbitration, Admin Legacy Arbitration, UI Vocabulary Handoff, Future Tasks, Out of Scope, Tests / Validation, and Review Notes.
- Confirmed `/boucles` remains legacy short term and is not a true Loop screen.
- Confirmed `/boucles/creer` remains unchanged in T075.19 and should be repositioned only later if Cyril validates Partner wording or deactivation.
- Confirmed `/partners` must not be created in T075.19 and is prohibited as a public English URL; the French future target is `/partenaires` and `/partenaires/demande`.
- Confirmed admin Community surfaces remain legacy until DB/runtime and admin rename tasks are ready.
- No runtime files modified.
- No route, controller, view, test, migration, API, policy, middleware, or PROD file modified.

## 2026-05-18 04:46:29 Europe/Paris

### OPENCODE — Option A Cyril/COCKPIT integrated

- Integrated the Cyril/COCKPIT Option A arbitration into this TASK file only.
- Documented that public URLs and public UI must be French.
- Documented that `/boucles` is currently ambiguous/legacy but reserved for the true Boucles concept and should be progressively recovered.
- Documented `/loops` as public English routing debt to remove/replace in a future task, not in T075.19.
- Replaced the future `/partners` target with `/partenaires` and `/partenaires/demande`.
- Documented target CTA "Devenir partenaire".
- Documented that "Créer un espace partenaire" must be avoided because "espace" is ambiguous.
- Confirmed no runtime modification, no route creation, no `/partenaires`, no `/partenaires/demande`, no `/boucles` change, no `/loops` removal, no commit, no push, and no finalize.

## 2026-05-18 04:53:50 Europe/Paris

### OPENCODE — Finalization after OPENAI review

- Integrated OPENAI review verdict: APPROVE WITH NOTES.
- Confirmed blocking issues: none.
- Confirmed validated points: `/boucles` reserved as future canonical French public route for true Boucles, `/boucles` must not redirect to partners by default, public English URLs `/loops`, `/partners`, and `/organization` are prohibited, future partner targets are `/partenaires` and `/partenaires/demande`, and CTA "Devenir partenaire" is validated.
- Added OPENAI notes that `/organisation` and `/organisation/demande` are only future possibilities and must not become a public organization flow without dedicated product arbitration.
- Documented accepted residual risk: until T076 UI/routing is implemented, `/boucles` remains a legacy ambiguous surface despite its target arbitration.
- Confirmed final handoffs to T076 UI/routing français, T076 Admin, DB/runtime, Playwright, and T75 Final Handoff.
- Marked task status `DONE` and unlocked the task.
- No runtime files modified.
- No route, controller, view, test, migration, API, policy, middleware, or PROD file modified.
- No `/partenaires` created, no `/boucles` modified, no `/loops` removed, and no `/organisation` created.

## 2026-05-18 04:55:21 Europe/Paris

### OPENCODE — Final validation scripts executed

- Ran `./ai/scripts/check-task.sh TODO/TASK-098-t075-19-boucles-partners-product-routing-arbitration.md`: passed with status `DONE` and lock `UNLOCKED`.
- Ran `yes n | ./ai/scripts/finalize-task.sh TODO/TASK-098-t075-19-boucles-partners-product-routing-arbitration.md`: passed; script check passed and commit, push, and CI inspection were intentionally skipped so the explicit requested commit/push commands could be executed manually after this TASK update.
- Worktree still contains only this TASK file before commit.

# Handoffs

## Final Handoff Targets

- T076 UI/routing français: implement visible wording/navigation and public route changes; keep true Boucles separate from public partner/acquisition surfaces.
- T076 Admin: plan controlled admin label/IA cleanup for Community-named surfaces without breaking legacy route names prematurely.
- DB/runtime Community migration task: continue replacing `communities`, `community_id`, and `current_community` dependencies with Organization-native runtime layers in the required migration order.
- Playwright tests task: create future coverage for `/boucles` true Boucles recovery, future `/partenaires`, future `/partenaires/demande`, `/loops` replacement, navigation labels, no public English UI/URLs, and guest/auth access rules after implementation tasks exist.
- T75 Final Handoff: record that T075.19 produced arbitration only, no runtime change, no `/partenaires`, no `/partenaires/demande`, no `/boucles` change, no `/loops` removal, no admin rename, and no migration.
- OPENAI review handoff: include APPROVE WITH NOTES, no blocking issues, `/organisation` and `/organisation/demande` as future-only possibilities, and the accepted residual `/boucles` ambiguity risk until T076 UI/routing is done.

# Tests

- [x] No tests run: task creation and documentation/arbitration setup only.
- [x] Future Playwright coverage defined after arbitration.
- [x] Final validation remains documentation-only: no runtime change.

---

# Test Results

- No PHPUnit tests run: documentation-only task, no runtime files changed.
- No Playwright tests run: no UI/runtime behavior changed.
- `git status --short --branch` run after arbitration: only `TODO/TASK-098-t075-19-boucles-partners-product-routing-arbitration.md` is untracked/modified in the worktree.
- `./ai/scripts/check-task.sh TODO/TASK-098-t075-19-boucles-partners-product-routing-arbitration.md` run after arbitration: failed as expected because status remains `IN_PROGRESS`, lock remains `LOCKED`, and the TASK file is intentionally uncommitted. No finalize/commit/push requested.
- `./ai/scripts/check-task.sh TODO/TASK-098-t075-19-boucles-partners-product-routing-arbitration.md` run after final review update: passed with status `DONE` and lock `UNLOCKED`.
- `yes n | ./ai/scripts/finalize-task.sh TODO/TASK-098-t075-19-boucles-partners-product-routing-arbitration.md` run after final review update: passed; commit, push, and CI inspection skipped inside the script by non-interactive `n` responses, because commit/push are executed explicitly afterward.

---

# Review Notes

- OPENAI review verdict: APPROVE WITH NOTES.
- Blocking issues: none.
- OPENAI validated `/boucles` as the future canonical French public route for true Boucles.
- OPENAI validated that `/boucles` must not redirect to partners by default.
- OPENAI validated `/loops`, `/partners`, and `/organization` as prohibited public URLs.
- OPENAI validated `/partenaires` and `/partenaires/demande` as future French partner targets.
- OPENAI validated CTA "Devenir partenaire" and confirmed "Créer un espace partenaire" should be avoided.
- OPENAI validated that T075.19 remains documentation/arbitration only with no runtime modification.
- OPENAI validated handoffs to T076 UI/routing français, T076 Admin, DB/runtime, Playwright, and T75 Final Handoff.
- `/organisation` and `/organisation/demande` are only future possibilities.
- Do not create a public `organisation` flow without dedicated product arbitration, to avoid blurring Organization = Tenant with Partner / Boucle.
- Accepted risk: until T076 UI/routing is completed, `/boucles` remains a legacy ambiguous surface despite its target arbitration.
- T075.19 is intentionally not a runtime implementation task.
- Any future implementation must preserve: Organization = Tenant, Loop != Tenant, Partner != Tenant.
- `/boucles` remains identified as an ambiguous legacy Community surface today, but Option A reserves it for the true Boucles concept.
- `/loops`, `/partners`, and `/organization` are public English URL forms to remove/avoid in future public routing.
- `/partenaires` and `/partenaires/demande` remain future French route targets only and must not be implemented in this task.
