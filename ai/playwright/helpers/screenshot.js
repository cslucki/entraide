import path from 'path';
import fs from 'fs';

const SCREENSHOT_DIR = 'ai/playwright/screenshots';

export function ensureScreenshotDir() {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

export async function captureScreenshot(page, name, options = {}) {
    ensureScreenshotDir();
    const filepath = path.join(SCREENSHOT_DIR, `${name}.png`);
    await page.screenshot({
        path: filepath,
        fullPage: options.fullPage ?? true,
        ...options,
    });
    return filepath;
}

export async function captureFailureScreenshot(page, testInfo) {
    ensureScreenshotDir();
    const filepath = path.join(
        SCREENSHOT_DIR,
        `failure-${testInfo.title.replace(/\s+/g, '-')}-${Date.now()}.png`
    );
    await page.screenshot({
        path: filepath,
        fullPage: true,
    });
    return filepath;
}
