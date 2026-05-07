# Browser & Playwright Workflow

## Goal

Validate frontend behavior using browser tooling and Playwright.

Do not assume frontend behavior without browser validation.

---

# Use Cases

Playwright and browser tooling should be used for:

- screenshots
- responsive testing
- UI debugging
- console inspection
- Livewire validation
- Alpine.js validation
- navigation testing
- visual verification

---

# Browser Access

Local development URL:

```text
https://test.laravel/dashboard
```

Do not use localhost from Windows browser tooling.

---

# Preferred Validation Workflow

1. open browser
2. inspect UI
3. inspect console
4. inspect network requests
5. inspect Livewire requests
6. inspect Alpine state
7. capture screenshots
8. only then modify code

---

# Responsive Validation

Always validate:
- desktop
- tablet
- mobile

Important for:
- Livewire
- navigation
- Alpine.js
- modals
- dropdowns

---

# Console Inspection

Always inspect:
- JavaScript errors
- Livewire errors
- Alpine errors
- failed requests
- hydration issues

---

# Screenshot Rules

Screenshots may be stored inside:

```text
ai/screenshots/
```

Useful for:
- debugging
- reviews
- regressions
- handoffs

---

# Important Rules

Never assume:
- responsive correctness
- Alpine synchronization
- Livewire synchronization
- visual consistency

without browser validation.
