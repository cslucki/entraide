# T145-DECISION-MATRIX.md — Decision Matrix

**Purpose:** Document every architectural decision made during T145, with rationale and alternatives.

| # | Decision | Rationale | Alternatives | Status |
|---|----------|-----------|-------------|--------|
| 1 | **Verdict GO** — ≤5 RUNs | 7 sub-agents convergent : code correct, DB state vide | NO-GO (refuser scope) → écarté car cause claire | ✅ |
| 2 | Default Organization = seeded Community | Organization = Community alias sur même table « communities » | Nouveau modèle → inutile, Organization extends Community | ✅ |
| 3 | Résolution via ResolveUrlOrganization (existant) | Middleware déjà présent, 3 fallbacks déjà codés | Nouveau middleware → complexité inutile | ✅ |
| 4 | Auth routes gardées par $platformGlobalExact | $platformGlobalExact protège login/register/etc. | Nouveau middleware auth-guard → redondant | ✅ |
