> **AGENT CONTEXT ONLY**
>
> This file is an operational summary for agents. It is not canonical project documentation. If this file conflicts with `docs/`, `docs/` wins.

# Browser & Visual Tooling

## Canonical Sources

- `docs/README.md`
- `docs/01-UI_RULES.md`
- `ai/playwright/README.md`
- `ai/tooling/terminal-tools.md`

Use this file as a browser-validation checklist only.

Use browser and visual tools for:

- Livewire debugging
- DOM inspection
- browser console inspection
- screenshots
- responsive validation
- Alpine.js inspection
- Tailwind verification

---

# Preferred Workflow

1. inspect UI
2. inspect DOM
3. inspect console
4. inspect network behavior
5. inspect Livewire requests
6. only then modify code

---

# Validation Rules

Do not assume:
- frontend behavior
- Alpine state
- Livewire synchronization
- responsive correctness

without browser verification.
