import { test, expect } from '@playwright/test';

/**
 * TASK-1743 — le menu avatar de Main, sur un ecran de telephone.
 *
 * Pourquoi un test de NAVIGATEUR et pas seulement un test PHP : le defaut
 * etait une regle CSS (`.nav-menu{display:none}` sous 760 px) et un script
 * inerte. Un test qui lit le HTML serait reste VERT pendant que la barre etait
 * invisible et que le second menu ne s'ouvrait pas. Seule une mesure de
 * visibilite et un vrai clic repondent a la question posee.
 */
const TELEPHONE = { width: 390, height: 844 };

test.describe('TASK-1743 — menu avatar mobile de Main', () => {
  test.use({ viewport: TELEPHONE, ignoreHTTPSErrors: true });

  test.beforeEach(async ({ page }) => {
    await page.goto('https://test.laravel/login');
    await page.fill('#email', 'main.member2@bouclepro.test');
    await page.fill('#password', 'password');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
  });

  test('l avatar est visible d emblee, sans burger', async ({ page }) => {
    await page.goto('https://test.laravel/org/main');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.bpam-trigger').first()).toBeVisible();
    // Le burger a ete retire : s'il revenait, il recacherait l'avatar.
    await expect(page.locator('.burger')).toHaveCount(0);
  });

  test('le menu s ouvre et porte les entrees attendues', async ({ page }) => {
    await page.goto('https://test.laravel/org/main');
    await page.waitForLoadState('networkidle');

    await page.locator('.bpam-trigger').first().click();
    const panneau = page.locator('.bpam-panel').first();
    await expect(panneau).toBeVisible();

    for (const entree of ['Tableau de bord', 'Profil et paramètres', 'Boucles', 'Un bug ?', 'Déconnexion']) {
      await expect(panneau.getByText(entree, { exact: false })).toBeVisible();
    }
  });

  test('« A propos » reste atteignable dans la barre', async ({ page }) => {
    // Comme sur le bureau, et comme sur OFSH et LaunchPals : cette entree vit
    // a cote du menu, pas dedans. Elle doit rester VISIBLE sur telephone —
    // c'est elle que le burger emportait.
    await page.goto('https://test.laravel/org/main');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.bp-hero-v2 .nav-menu a').first()).toBeVisible();
    await expect(page.locator('.bp-hero-v2 .nav-menu').getByText('propos', { exact: false })).toBeVisible();
  });
});
