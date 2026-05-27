import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA } from '../helpers/data.js';

test.describe('T146 Auth — Member 1 Login', () => {
  test('T146-002: Login as member 1 and verify authenticated', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    expect(page.url()).not.toContain('/login');
    expect(page.url()).not.toBe('about:blank');
  });
});
