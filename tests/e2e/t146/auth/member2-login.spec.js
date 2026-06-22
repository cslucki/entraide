import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA } from '../helpers/data.js';

test.describe('T146 Auth — Member 2 Login', () => {
  test('T146-003: Login as member 2 and verify authenticated', async ({ page }) => {
    await login(page, QA.M2.email, QA.M2.password);
    expect(page.url()).not.toContain('/login');
    expect(page.url()).not.toBe('about:blank');
  });
});
