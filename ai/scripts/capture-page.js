import { chromium } from 'playwright';

const BASE_URL = 'https://test.laravel';


async function login(page, email, password) {
  await page.goto(`${BASE_URL}/login`);

  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);

  await page.click('button[type="submit"]');

  await page.waitForLoadState('networkidle');
}

const pages = [
  {
    name: 'homepage-desktop',
    url: '/',
    viewport: { width: 1440, height: 900 },
  },

  {
    name: 'homepage-mobile',
    url: '/',
    viewport: { width: 390, height: 844 },
  },

  {
    name: 'dashboard-admin',
    url: '/dashboard',
    viewport: { width: 1440, height: 900 },
    auth: {
      email: 'test@example.com',
      password: 'password',
    },
  },

  {
    name: 'dashboard-member',
    url: '/dashboard',
    viewport: { width: 1440, height: 900 },
    auth: {
      email: 'alice@example.com',
      password: 'password123',
    },
  },

  {
    name: 'dashboard-cpme-mobile',
    url: '/dashboard',
    viewport: { width: 390, height: 844 },
    auth: {
      email: 'cyril@teletravailleurs.com',
      password: 'password123',
    },
  },
];

(async () => {
  const browser = await chromium.launch({
    headless: true,
  });

  for (const pageConfig of pages) {
    const context = await browser.newContext({
      viewport: pageConfig.viewport,
      ignoreHTTPSErrors: true,
    });

    const page = await context.newPage();

    page.on('console', msg => {
      if (msg.type() === 'error') {
        console.log(`Console error: ${msg.text()}`);
      }
    });

    page.on('pageerror', error => {
      console.log(`Page error: ${error.message}`);
    });

    page.on('requestfailed', request => {
      console.log(`Request failed: ${request.url()}`);
    });

    console.log(`Capturing: ${pageConfig.name}`);

      if (pageConfig.auth) {
        console.log(`Logging in: ${pageConfig.auth.email}`);

        await login(
          page,
          pageConfig.auth.email,
          pageConfig.auth.password
        );
      }


    await page.goto(`${BASE_URL}${pageConfig.url}`, {
      waitUntil: 'networkidle',
    });

    await page.screenshot({
      path: `ai/screenshots/${pageConfig.name}.png`,
      fullPage: true,
    });

    await context.close();
  }

  await browser.close();

  console.log('Screenshots completed.');
})();
