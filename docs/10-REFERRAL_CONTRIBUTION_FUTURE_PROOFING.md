# 10-REFERRAL_CONTRIBUTION_FUTURE_PROOFING

**Status:** Architecture Note — Future-Proofing

**Purpose:**
Document the architecture boundaries, product intent, and anti-drift rules for the referral and contribution system.

This document is NOT an implementation spec.

It is a stabilization note.

It exists to prevent architectural drift toward undesirable patterns.

---

**References:**
- 06-DOMAIN_ARCHITECTURE_V2.md
- 07-GLOSSARY.md
- 02-PRODUCT_PRINCIPLES.md
- AGENTS.md
- CLAUDE.md

---

# 1. Purpose

The referral MVP is a foundation of contribution.

It is NOT:

- a prospecting system,
- an acquisition engine,
- a growth hack,
- a commercial pipeline.

This document exists as a future-proofing note.

It defines:

- what the system already does,
- what the system prepares without implementing,
- what the system must never become,
- the conceptual boundaries between points, rewards, badges, and contributions.

The goal is to keep the referral system aligned with the product philosophy:

> entraide, transmission, progression, mouvement collectif.

---

# 2. Current Referral MVP

The referral system currently provides:

- **Referrals:** a Member can invite another person to join an Organization.
- **Referral rewards:** points are awarded when an invited person activates.
- **Activation flow:** the invited person receives an invitation, registers, and activates their membership.
- **Level 1 rewards:** the inviting Member receives points for a direct referral.
- **Level 2 rewards:** the inviting Member receives points when their referral makes their own referral (one level deep only).
- **Member visibility:** referred Members can see their referral history on the dashboard and /points page.
- **Navigation:** referral-related navigation is exposed to Members.
- **Light admin:** Organization admins can see referral data in a minimal interface.

**Assumed limitations:**

- No multi-level reward chain (limited to 2 levels).
- No public leaderboard.
- No commission or passive income.
- No gamification mechanics.
- No reputation scoring.
- No social graph.
- No AI recommendation.
- No automated CRM follow-up.

These limitations are intentional design choices, not gaps.

---

# 3. Product Intent

The referral system exists to enable:

- **entraide** — helping others discover the Organization,
- **transmission** — passing along opportunities to join,
- **progression** — collective growth through mutual support,
- **mouvement collectif** — strengthening the Organization organically,
- **qualité relationnelle** — valuing meaningful invitations over volume,
- **réduction de charge mentale** — keeping the system simple and predictable.

The act of inviting is framed as:

> an act of mutual aid, not commercial acquisition.

The system should feel:

- natural,
- lightweight,
- human,
- intentional.

It should never feel:

- pushy,
- competitive,
- transactional,
- exploitative.

---

# 4. Conceptual Boundaries

It is critical to maintain clear separation between these concepts:

| Concept | Nature | Current State |
|---|---|---|
| Transactional points | Earned through exchanges, convertible | Implemented |
| Invitation rewards | Points awarded for successful referrals | Implemented (level 1 + 2) |
| Symbolic recognition | Non-financial acknowledgment | Not implemented (future) |
| Badges | Visual markers of contribution | Not implemented (future) |
| Contribution engine | Measures useful actions | Not implemented (future) |
| Reputation | Trust signal over time | Not implemented (future, if ever) |

**Rules:**

- Rewards are not commitment.
- Badges are not roles.
- Contributions are not scores.
- Reputation is not hierarchy.
- Points are not income.

Each concept must be evaluated independently before implementation.

No concept should implicitly unlock another.

---

# 5. Anti-Drift Rules

The following patterns are explicitly forbidden in the referral system:

| Forbidden Pattern | Rationale |
|---|---|
| MLM / multi-level marketing | Creates perverse incentives, exploitative dynamics |
| Commission on referrals | Turns mutual aid into a sales pipeline |
| Passive income from referrals | Encourages gamification of recruitment |
| Commercial levels / ranks | Introduces hierarchy, pressure, competition |
| Public aggressive leaderboard | Creates social pressure, anxiety |
| "Top referrer" rankings | Gamifies attention, favors volume over quality |
| Growth hacking tactics | Noise, spam, manipulation |
| Social pressure to recruit | Betrays the trust of the invitation model |
| Toxic gamification (streaks, timers, FOMO) | Captures attention, creates anxiety |
| Attention capture mechanics | Dark patterns, notification spam |
| CRM-style prospecting automation | Transforms Members into leads |

The referral system must remain:

- invitation-based,
- voluntary,
- human-paced,
- relationship-respecting.

---

# 6. Future Contribution Engine

The platform may eventually need a lightweight Contribution Engine.

This is NOT implemented now.

This section describes only the conceptual shape of what it could be.

**Possible purpose:**

- measure useful contributions within an Organization,
- value qualitative actions (helping, sharing, mentoring),
- help identify Members who facilitate collective progress,
- stay sober — not a gamification system,
- stay Organization-scoped,
- optionally contextualized by Loop.

**Possible signals (not designed, not implemented):**

- referral invitations (existing),
- service quality,
- loop participation,
- mentorship moments,
- constructive reviews.

**Key constraints:**

- Must NOT become a reputation marketplace.
- Must NOT create pressure to "perform."
- Must NOT replace human judgment.
- Must be opt-in for the Organization.
- Must respect Loop boundaries without conflating them with tenant boundaries.

---

# 7. Symbolic Recognition & Badges

Badges may eventually exist as symbolic recognition markers.

This is NOT implemented now.

**Possible badge concepts:**

| Badge | Meaning |
|---|---|
| Pioneer | Early contributor to the Organization or a Loop |
| Connector | Member who actively facilitates introductions |
| Ambassador | Member who represents the Organization externally |
| Looper | Member who helps others enter and engage in Loops |

**Design constraints for badges:**

- Symbolic only. No automatic financial advantage.
- No hierarchical status. A badge is not a rank.
- No aggressive public competition. Badges are personal, not leaderboard fodder.
- Badges must be organization-defined or platform-defined — not user-claimed.
- Badge criteria must be transparent and stable.

Badges must not create:

- social exclusion,
- pressure to collect,
- implicit obligations.

---

# 8. Looper Definition

The term "Looper" describes a specific type of Member.

**Looper is:**

- a human facilitator,
- a driving Member who helps others enter and engage,
- someone who contributes to collective movement,
- a social and symbolic role.

**Looper is NOT:**

- a commercial grade,
- an MLM status,
- a hierarchical position,
- a tenant role,
- a paid position,
- a management layer.

The Looper concept reinforces the idea that:

> contribution is social, not transactional.

This definition must remain stable regardless of future badge or reward evolution.

---

# 9. AI Future Extensions

AI may eventually assist with contribution-related features.

This is NOT implemented now.

**Possible future AI capabilities (conceptual only):**

| Capability | Description |
|---|---|
| IA Connector | Helps Members find relevant connections |
| Welcome Agent | Assists new Members with onboarding |
| Reconnexion humaine | Suggests re-engagement with inactive Members |
| Synthèse d'activité collective | Summarizes collective contributions |
| Recommandations douces | Gentle suggestions for meaningful actions |
| Détection de surcharge relationnelle | Flags when a Member is over-extended |
| Onboarding progressif | Contextual help for new joiners |

**AI design rules for contribution features:**

- AI must reduce friction, not create noise.
- AI must remain organization-scoped.
- AI may be loop-contextualized — but never treat Loop as a tenant boundary.
- AI must never manipulate attention.
- AI must never apply social pressure.
- AI recommendations must be soft, not prescriptive.

---

# 10. Architecture Alignment

The contribution architecture must align with the established domain architecture.

**Fundamental rules:**

- Organization = Tenant.
- Loop != Tenant.
- Member belongs to exactly one Organization.
- Contributions may be contextualized within a Loop.
- Contributions must never redefine the tenant boundary.
- Loop is a collaborative context, not a security scope.

**Architecture hierarchy:**

```
Platform
└── Organization (tenant boundary)
    └── Loops (collaborative contexts)
        └── Members
            └── Contributions
```

**Legacy note:**

"Community" terminology may still appear in the Laravel codebase. This is legacy technical debt.

In product-facing documentation, UI, and prompts:

> Always prefer Organization, Loop, Member, and Contribution.

Refer to docs/07-GLOSSARY.md for canonical vocabulary.

---

# 11. Implementation Boundaries

The following are explicitly OUT OF SCOPE for the current referral MVP.

Nothing below is implemented now. Nothing below should be implemented without passing the Future Decision Gates (see section 12).

**Out of scope:**

- New database tables for contributions or reputation.
- New migrations.
- New Eloquent models.
- Reputation engine (scoring, ranking, trust metrics).
- Social graph (member-to-member relationship mapping).
- Relational AI (proactive matching, automated introductions).
- Leaderboard of any kind.
- Advanced admin workflow for contribution management.
- Badge assignment system.
- Notification automation for referrals.
- CRM features (prospect tracking, funnel metrics, conversion optimization).
- Gamification mechanics (streaks, achievements, progression bars, levels).
- Public profiles with contribution stats.

---

# 12. Future Decision Gates

Before implementing any new contribution-related feature, the following gates must be satisfied:

| Gate | Requirement |
|---|---|
| Product validation | Clearly stated product need, not speculation |
| Tenant safety | No tenant boundary violation |
| UX anti-overload | No cognitive load increase, no pressure |
| Anti-MLM | No multi-level, commission, or passive income patterns |
| SQLite + PostgreSQL | Dual runtime compatibility verified |
| Playwright validation | UI changes covered by Playwright tests |
| Data storage decision | Explicit decision on what data is stored, for how long, and why |

**Additional principles:**

- Every new feature must be evaluated independently.
- No feature implicitly unlocks another feature.
- Badges do not automatically imply rewards.
- Contributions do not automatically imply reputation.
- AI features require explicit prompt architecture and scope definition.

---

# Appendix A: Vocabulary Reference

| Term | Status | Definition |
|---|---|---|
| Organization | Canonical | Tenant boundary |
| Loop | Canonical | Collaborative context, NOT tenant |
| Member | Canonical | User belonging to an Organization |
| Contribution | Canonical | Qualitative action within the platform |
| Reward | Canonical | Points awarded for invitations |
| Badge | Future | Symbolic recognition marker |
| Reputation | Future (if ever) | Trust signal over time |
| Community | Legacy only | Technical Laravel terminology |

---

# Appendix B: Product Philosophy Alignment

The referral system must always feel:

- calm,
- human,
- intentional,
- lightweight,
- trustworthy.

It must never feel:

- aggressive,
- exploitative,
- competitive,
- noisy,
- growth-obsessed.
