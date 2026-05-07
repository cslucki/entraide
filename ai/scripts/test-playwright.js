import { chromium } from 'playwright';

(async () => {

  const browser = await chromium.launch({
    headless: true
  });

  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: {
      width: 1440,
      height: 900
    }
  });

  const page = await context.newPage();

  await page.goto('https://test.laravel', {
    waitUntil: 'networkidle'
  });

  await page.screenshot({
    path: 'ai/screenshots/homepage.png',
    fullPage: true
  });

  console.log('Screenshot saved.');

  await browser.close();

})();