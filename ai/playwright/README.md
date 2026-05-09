# Playwright QA System

## Overview

Agentic Playwright QA system for BouclePro.com testing.

Supports realistic SaaS workflows:
- form automation
- file uploads
- JS console inspection
- screenshots
- video recording
- trace generation
- responsive validation

---

# Strict Account Separation

This system enforces strict separation between test accounts for multi-tenant safety.

## Account Types

| Account Type | Environment Variables | Purpose | Current Use |
|--------------|----------------------|---------|-------------|
| **Admin** | `TEST_ADMIN_*` | Admin routes (`/admin/*`), dashboard | ✅ smoke.spec.js |
| **Global Member 1** | `TEST_MEMBER1_*` | Global platform tests (Alice, OUTSIDE CPME) | ✅ All member tests |
| **Global Member 2** | `TEST_MEMBER2_*` | Multi-user scenarios (Cyril, OUTSIDE CPME) | ✅ Available |
| **CPME Member 1** | `TEST_MEMBER_OF_CPME1_*` | CPME tenant tests (Bob) | 🔜 Reserved (future) |
| **CPME Member 2** | `TEST_MEMBER_OF_CPME2_*` | CPME tenant tests (John) | 🔜 Reserved (future) |

**Rules:**
1. Admin tests → `TEST_ADMIN_*` ONLY
2. Global platform tests → `TEST_MEMBER1_*` or `TEST_MEMBER2_*` ONLY
3. CPME tenant tests → `TEST_MEMBER_OF_CPME1_*` or `TEST_MEMBER_OF_CPME2_*` ONLY
4. **NEVER** use CPME accounts for global platform tests
5. **NEVER** use member accounts for admin routes

---

# Why WebKit Matters

WebKit (Safari engine) is intentionally enabled.

Goals:
- improve frontend robustness
- enforce standards-compliant CSS/JS
- detect browser-specific rendering issues
- validate iPhone/iPad compatibility
- force AI agents to produce more portable frontend code

The additional execution time is considered acceptable for long-term quality benefits.

WebKit may require additional Linux dependencies under WSL2:
sudo npx playwright install-deps

---

## Quick Start

### Run all tests

```bash
npx playwright test
```

### Run single test file

```bash
npx playwright test tests/e2e/publish-article.spec.js
```

### Run with UI mode (for debugging)

```bash
npx playwright test --ui
```

### Run in headed mode (see browser)

```bash
npx playwright test --headed
```

---

## Reports

### Open HTML report

```bash
npx playwright show-report ai/playwright/reports/html
```

### View trace (after test failure)

```bash
npx playwright show-trace ai/playwright/test-results/trace-xxxx.zip
```

---

## Artifacts Location

| Type | Location |
|------|----------|
| Screenshots | `ai/playwright/screenshots/` |
| Videos | `ai/playwright/test-results/` |
| Traces | `ai/playwright/test-results/` |
| HTML reports | `ai/playwright/reports/html/` |
| JSON results | `ai/playwright/reports/results.json` |

---

## Architecture

```
ai/playwright/
├── config/         # Configuration files
├── devices/         # Device profiles
├── helpers/         # Reusable helpers
│   ├── auth.js      # Login/logout functions (strict account separation)
│   ├── screenshot.js # Screenshot utilities
│   ├── console.js   # JS console logging
│   └── trace.js     # Trace utilities
├── users/           # Test user profiles (strict separation naming)
│   ├── admin.js              # TEST_ADMIN_* (admin user)
│   ├── global-member-1.js    # TEST_MEMBER1_* (Alice, OUTSIDE CPME)
│   ├── global-member-2.js    # TEST_MEMBER2_* (Cyril, OUTSIDE CPME)
│   ├── cpme-member-1.js      # TEST_MEMBER_OF_CPME1_* (Bob, RESERVED)
│   ├── cpme-member-2.js      # TEST_MEMBER_OF_CPME2_* (John, RESERVED)
│   └── index.js              # Barrel export with documentation
├── scenarios/       # Business workflows
└── screenshots/     # Captured screenshots
```

---

## Helpers Usage

### Authentication (Strict Account Separation)

```javascript
import { loginAsMember, loginAsAdmin, login, logout } from '../../ai/playwright/helpers/auth.js';

// Login as global platform member (uses TEST_MEMBER1 - Alice)
// Use for: article publishing, messaging, global workflows
await loginAsMember(page);

// Login as admin (uses TEST_ADMIN)
// Use ONLY for: /admin/* routes, admin dashboard, admin features
await loginAsAdmin(page);

// Login with custom credentials
await login(page, 'email@example.com', 'password');

// Logout
await logout(page);
```

### Screenshots

```javascript
import { captureScreenshot, captureFailureScreenshot } from '../../ai/playwright/helpers/screenshot.js';

// Capture full-page screenshot
await captureScreenshot(page, 'dashboard-view');

// Capture on test failure
test.afterEach(async ({ page }, testInfo) => {
    if (testInfo.status !== 'passed') {
        await captureFailureScreenshot(page, testInfo);
    }
});
```

### Console Logging

```javascript
import { setupConsoleLogging, assertNoConsoleErrors } from '../../ai/playwright/helpers/console.js';

test.beforeEach(async ({ page }) => {
    setupConsoleLogging(page);
});

test.afterEach(async () => {
    assertNoConsoleErrors();
});
```

---

## Environment Variables

All test credentials are loaded from `.env` via `tests/setup.js`.

### Required Variables

```bash
# Admin user - for admin routes and dashboard only
TEST_ADMIN_LOGIN=admin@example.com
TEST_ADMIN_PASSWORD=password123

# Global platform members - OUTSIDE any tenant/community
TEST_MEMBER1_LOGIN=alice@example.com
TEST_MEMBER1_PASSWORD=password123
TEST_MEMBER2_LOGIN=cyril@teletravailleurs.com
TEST_MEMBER2_PASSWORD=password123

# CPME community members - RESERVED for future tenant-isolation testing
TEST_MEMBER_OF_CPME1_LOGIN="bob@example.com"
TEST_MEMBER_OF_CPME1_PASSWORD="password123"
TEST_MEMBER_OF_CPME2_LOGIN="john@example.com"
TEST_MEMBER_OF_CPME2_PASSWORD="password123"
```

**Validation:** Tests fail immediately if required env vars are missing.

---

## Device Profiles

```javascript
import { desktopViewport, tabletViewport, mobileViewport } from '../../ai/playwright/devices/index.js';
```

### Available Devices

- **Desktop**: 1280x720, 1920x1080
- **Tablet**: 768x1024, 1024x768
- **Mobile**: 375x667, 414x896

---

## Test Structure

### Global Platform Tests (use loginAsMember)

```javascript
import { test, expect } from '@playwright/test';
import { loginAsMember, captureScreenshot } from '../../ai/playwright/helpers/auth.js';

test.describe('Feature Name', () => {
    test.beforeEach(async ({ page }) => {
        // Uses TEST_MEMBER1 (Alice) - OUTSIDE CPME
        await loginAsMember(page);
    });

    test('specific scenario', async ({ page }) => {
        // Arrange
        await page.goto('/route');

        // Act
        await page.fill('input[name="field"]', 'value');
        await page.click('button[type="submit"]');

        // Assert
        await expect(page).toHaveURL(/expected/);
        await captureScreenshot(page, 'result');
    });
});
```

### Admin Tests (use loginAsAdmin)

```javascript
import { test, expect } from '@playwright/test';
import { loginAsAdmin, captureScreenshot } from '../../ai/playwright/helpers/auth.js';

test.describe('Admin Features', () => {
    test.beforeEach(async ({ page }) => {
        // Uses TEST_ADMIN - admin user only
        await loginAsAdmin(page);
    });

    test('admin dashboard', async ({ page }) => {
        await page.goto('/admin/dashboard');
        await expect(page).toHaveURL(/\/admin\/dashboard/);
    });
});
```

---

## Best Practices

1. **Strict account separation** - always use correct account type for test scope
2. **Always login at test level** - use test.describe() with beforeEach
3. **Capture screenshots** - at key steps for debugging
4. **Check console errors** - use setupConsoleLogging
5. **Wait for network idle** - for async operations
6. **Use specific selectors** - prefer name attributes over CSS classes
7. **Validate URLs** - use `toHaveURL()` with regex

---

## Existing Scenarios

| Test | Account | Purpose |
|------|---------|---------|
| smoke.spec.js | Global Member, Admin | Basic navigation, member dashboard, admin dashboard |
| login-member.spec.js | Global Member 1 | Member login flow |
| publish-article.spec.js | Global Member 1 | Article creation and publishing |

---

## Future Multi-Tenant Testing

CPME accounts (`TEST_MEMBER_OF_CPME1_*`, `TEST_MEMBER_OF_CPME2_*`) are **RESERVED** for future tenant-isolation testing:

- Community-specific messaging
- Tenant-scoped content
- Multi-tenant isolation validation
- Community-onboarding workflows

**Do NOT** use these accounts for global platform tests.

---

## Debugging Tips

### Run single test in headed mode

```bash
npx playwright test -g "test name" --headed
```

### Run with inspector

```bash
npx playwright codegen https://test.laravel
```

### Check traces

```bash
npx playwright show-trace ai/playwright/test-results/trace-file.zip
```
