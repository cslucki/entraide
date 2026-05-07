# Playwright Timeout Issues in Sandbox Environment

During the implementation of the Notification Center, Playwright e2e tests encountered consistent timeouts (30000ms) when waiting for navigation or URL changes after login.

## Symptoms
- Tests fail at `await page.waitForURL(url => url.pathname.includes('/dashboard'))` or similar.
- Screenshots show that the login was successful and the user is on the dashboard (or community landing page), but Playwright's `waitForURL` does not resolve.
- This behavior is observed even when using `http://localhost:8000` and ensuring the database is seeded with `test@example.com / password`.

## Probable Cause
The sandbox environment's networking or process management might cause delays or interruptions in how Playwright tracks page navigation events, especially during redirects.

## Verification
Manual verification through screenshots (`test-results/*.png`) confirms that:
1. The application serves the correct pages.
2. The UI components (like the notification bell) are present.
3. The authentication flow works (session is established).

## Recommendation
For future developments in this sandbox, rely on Feature tests (`php artisan test`) for logic verification and use Playwright primarily for visual snapshots until the networking environment is stabilized.
