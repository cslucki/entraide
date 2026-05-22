# Documentation Index

`docs/` is the canonical project memory for BouclePro / Cyberworkers.

## Source Separation

- `docs/` = what the project is: product doctrine, domain architecture, active specs, migration strategy, historical audits and reference assets.
- `ai/` = how agents work: scripts, tooling, workflows, prompts and operational summaries.
- `@DOCS/` = private human documentation, not tracked, not committed, and not a repository source for agents.

## Truth Hierarchy

When documents conflict, use this order:

1. `docs/README.md`
2. Active specs in `docs/specs/`
3. `docs/05-DOMAIN_ARCHITECTURE.md`
4. `docs/06-GLOSSARY.md`
5. Architecture decisions in `docs/architecture/`
6. Migration and transition docs in `docs/migration/`
7. Legacy docs in `docs/legacy/` as historical evidence only
8. Historical audits in `docs/audits/`
9. `ai/context/*` only as agent summaries, never as durable project canon

## Critical Current Rules

- Organization = Tenant.
- Loop != Tenant.
- Partner != Tenant.
- Public != Global.
- `Community`, `community_id` and `current_community` are temporary legacy technical vocabulary.
- `/partenaires` is the canonical French public partner route; `/partenaires/demande` is the partner request route; `/partners` is legacy redirect / compatibility only.
- `/boucles` is the target canonical French surface for true Boucles according to `docs/specs/02-T077.0-boucles-product-surface-spec.md` and `docs/specs/03-T077.4-boucles-flux-signaux-journal-doctrine.md`.
- True Boucles remain Organization-scoped. A public route can still require an Organization.
- ChatLoop is not Boucles.
- Flux, Signaux and Journal are Boucles product doctrine concepts, not generic notifications, logs or workflows.

## Active Root Documents

| Document | Status | Role |
|---|---|---|
| `docs/01-UI_RULES.md` | ACTIVE CANON | UI and product engineering rules. |
| `docs/02-WORKFLOW_AND_ENGINEERING_PRINCIPLES.md` | ACTIVE CANON | Workflow, git discipline, multi-agent coordination and engineering principles. |
| `docs/03-COMPONENT_LIBRARY.md` | EMPTY / TODO | Placeholder for the future component library. |
| `docs/04-ENGINEERING_RULES.md` | ACTIVE CANON | Additional UI and product engineering rules. |
| `docs/05-DOMAIN_ARCHITECTURE.md` | ACTIVE CANON | Domain architecture and Organization-native model. |
| `docs/06-GLOSSARY.md` | ACTIVE CANON | Canonical vocabulary for docs, code, UI and AI prompts. |
| `docs/07-REFERRAL_CONTRIBUTION_FUTURE_PROOFING.md` | ACTIVE CANON | Referral and contribution architecture boundaries. |

## Architecture

| Document | Status | Role |
|---|---|---|
| `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md` | ARCHITECTURE DECISION | Root-domain Organization resolution strategy. Older `/boucles` legacy readings are superseded by T077.0 and T077.4 for true Boucles. |
| `docs/architecture/02-PROD_LOCAL_SYNC_STRATEGY.md` | ARCHITECTURE / OPERATIONS SAFETY | Production-to-local database and media sync safety protocol. Does not authorize real dump/import/sync commands. |

## Migration

| Document | Status | Role |
|---|---|---|
| `docs/migration/01-COMMUNITY_MIGRATION_STRATEGY.md` | MIGRATION / TRANSITION | Strategic Community to Organization migration document. |
| `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md` | MIGRATION / TRANSITION | Operational execution plan for the Organization migration. Older `/boucles` legacy readings are superseded by T077.0 and T077.4 for true Boucles. |

## Active Specs

| Document | Status | Role |
|---|---|---|
| `docs/specs/01-T074.2-chatloop-center-ia-assisted-interactions.md` | ACTIVE SPEC | ChatLoop Center and IA-assisted interactions product spec. |
| `docs/specs/02-T077.0-boucles-product-surface-spec.md` | ACTIVE SPEC | Canonical Boucles product surface and `/boucles` route spec. |
| `docs/specs/03-T077.4-boucles-flux-signaux-journal-doctrine.md` | ACTIVE SPEC | Flux, Signaux and Journal doctrine for Boucles. |

## Legacy

| Document | Status | Role |
|---|---|---|
| `docs/legacy/01-COMMUNITY_TRANSACTION_MATRIX.md` | LEGACY COMMUNITY / DO NOT USE AS CURRENT SOURCE | Community-era transaction matrix. Use only as historical evidence. |

## Historical Audits

Do not delete historical audits. They preserve evidence, risks and decisions from earlier tasks.

| Document | Status | Role |
|---|---|---|
| `docs/audits/T074-assets-index.md` | HISTORICAL AUDIT | UX asset index for T074 references. |
| `docs/audits/T074.0-technical-audit-current-messaging-mobile-reverb-readiness.md` | HISTORICAL AUDIT | Messaging, mobile and Reverb readiness audit. |
| `docs/audits/T074.1-ux-chatloop-mobile-desktop-admin.md` | HISTORICAL AUDIT | ChatLoop UX audit. |
| `docs/audits/T074.1A-ia-solution-spike-chatloop-assisted-interactions.md` | HISTORICAL AUDIT | IA-assisted interaction spike. |
| `docs/audits/T075.0-organization-native-tenant-audit.md` | HISTORICAL AUDIT | Organization-native tenant audit. |
| `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md` | HISTORICAL AUDIT | Community legacy audit and removal plan. |
| `docs/audits/T077.2-boucles-organization-scoped-runtime-audit-strategy.md` | HISTORICAL AUDIT | Boucles runtime audit and strategy evidence. |
| `docs/audits/*-assets/` | ASSET REFERENCES | Historical UX and visual references. Do not treat as Playwright validation screenshots unless explicitly documented. |

## What To Read

For product or doctrine tasks:
- `docs/02-WORKFLOW_AND_ENGINEERING_PRINCIPLES.md`
- `docs/06-GLOSSARY.md`
- relevant active specs in `docs/specs/`

For architecture or tenant safety tasks:
- `docs/05-DOMAIN_ARCHITECTURE.md`
- `docs/06-GLOSSARY.md`
- `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md`
- `docs/migration/01-COMMUNITY_MIGRATION_STRATEGY.md`
- `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`

For routing and `/boucles` tasks:
- `docs/specs/02-T077.0-boucles-product-surface-spec.md`
- `docs/specs/03-T077.4-boucles-flux-signaux-journal-doctrine.md`
- `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md`
- `ai/context/routing-strategy.md` as an operational summary only

For ChatLoop or IA-assisted interaction tasks:
- `docs/specs/01-T074.2-chatloop-center-ia-assisted-interactions.md`
- `docs/audits/T074.1A-ia-solution-spike-chatloop-assisted-interactions.md` as historical prior art
- relevant `ai/context/*` and `ai/workflows/*` files after reading docs canon

For UI/UX tasks:
- `docs/01-UI_RULES.md`
- `docs/03-COMPONENT_LIBRARY.md` when it becomes populated
- `docs/audits/T074-assets-index.md`
- relevant asset folders in `docs/audits/`

For migration or legacy cleanup:
- `docs/migration/01-COMMUNITY_MIGRATION_STRATEGY.md`
- `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`
- `docs/audits/T075.0-organization-native-tenant-audit.md`
- `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md`
- `docs/legacy/01-COMMUNITY_TRANSACTION_MATRIX.md` as legacy evidence only

## Contradiction Rule

If a historical audit, legacy document, or `ai/context/*` file contradicts an active spec or architecture canon, the active docs canon wins. Do not create a parallel source of truth in `ai/`.

If a contradiction affects implementation scope, document it in the TASK file and ask for review before changing runtime code.

## Dump Warning

A documentation dump reflects only the branch and commit from which it was generated. Always verify the current branch before treating a dump as complete.
