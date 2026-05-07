# Development Environment

## Local Environment

Project runs inside WSL2 Ubuntu.

### WSL Host

- Host: WSL2
- OS: Ubuntu
- Local Linux IP: 127.0.0.1

### Windows Host Access

Windows accesses Laravel through:

- Windows IP: 172.27.130.89
- Local URL:
  https://test.laravel/dashboard

This URL must be used for:
- Playwright
- browser automation
- screenshots
- responsive validation
- UI testing

Do not use localhost from Windows browser tooling.

---

# Production Environment

Production product:
- BouclePro.com

Production infrastructure:
- Laravel Cloud

Production URL:
https://bouclepro.com

---

# Database

Local:
- SQLite

Production:
- PostgreSQL

Agents must always verify SQL compatibility.

Avoid:
- SQLite-only queries
- PostgreSQL-incompatible syntax

---

# Stack Versions

- PHP 8.4
- Laravel 13.7
- Livewire 3
- Alpine.js
- Tailwind CSS v4
- Node 22
- Vite

---

# Installed Terminal Tools

Preferred tools installed in WSL:

- batcat
- rg
- fzf
- lazygit
- git
- tmux

Agents should prefer these tools over basic alternatives.

Examples:
- use `batcat` instead of `cat`
- use `rg` instead of `grep`

---

# Browser & Playwright Rules

Agents are encouraged to use browser tooling for:

- screenshots
- UI debugging
- responsive testing
- console inspection
- Livewire inspection
- Alpine.js validation

Preferred workflow:
1. inspect UI
2. inspect console
3. inspect network
4. capture screenshots
5. only then modify code

---

# Standard Commands

## Development

```bash
php artisan serve
npm run dev
```

## Tests

```bash
php artisan test
```

## Build

```bash
npm run build
```

---

# Git Workflow

- Main branch: main
- One branch per task
- Never commit directly to main
- Keep commits atomic and focused

Branch naming example:

TASK-051-navbar-livewire-fix

---


# HTTPS Local Development

Local development uses HTTPS with a local development certificate.

Playwright/browser automation must use:

```javascript
ignoreHTTPSErrors: true

---

# Important Rules

Before modifying frontend behavior:
- inspect visually
- inspect DOM
- inspect console
- inspect Livewire requests
- inspect Alpine state

Never assume UI behavior without browser validation.