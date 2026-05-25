# T140.5 — Mission Orchestration

## Roles

| Role | Responsabilité |
|------|---------------|
| **PROJECT_SUPERVISOR** | Coordination, séquencement, gates, décisions d'escalade. |
| **REVIEW_SUPERVISOR** | Contrôle périmètre, risques, conformité architecture. |
| **TECH_WRITER** | **Seul writer.** Exécute les patches. Un seul sous-agent actif à la fois. |
| **TEST_WORKERS** | Read-only / test-only. Validation des patches. |

## Sub-tasks

| ID | Périmètre | Statut |
|----|-----------|--------|
| **T140.5A** | Channels broadcast + ResolveApiOrganization | 🔜 IN PROGRESS |
| T140.5B | LoopService + LoopMessageService | ⏸ BLOCKED |
| T140.5C | ReferralService + RewardDispatcher | ⏸ BLOCKED |
| T140.5D | Controllers P0 (Loop, Home, Transaction, Profile, Request, Service, Blog, BlogComment, API) | ⏸ BLOCKED |
| T140.5E | Controllers P1 (Auth, Admin, CommunityLanding) | ⏸ BLOCKED |
| T140.5F | Livewire Explorer | ⏸ BLOCKED |

## Gates

- **T140.5A → T140.5B** : Full test suite vert, audit doc livrée, DIFF NOTE validée.
- Jamais démarrer T140.5B+ tant que T140.5A n'est pas mergé dans develop.

## Escalation

- Tout conflit architecture → REVIEW_SUPERVISOR
- Tout changement de périmètre → Stéphane
- Tout test rouge avant merge → STOP, diagnostiquer, escalader

## Branche

T140.5A : `TASK-140-5A-channels-resolve-api-organization`
