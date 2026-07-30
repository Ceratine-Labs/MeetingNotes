// @ts-check

/** Credentials created by `php artisan demo:seed`. */
const DEMO_EMAIL = 'demo@notefiend.test';
const DEMO_PASSWORD = 'demo-password-123';

/**
 * Log in as the seeded demo user and land on the dashboard.
 * @param {import('@playwright/test').Page} page
 */
async function loginAsDemo(page) {
    await page.goto('/login');
    await page.fill('input[name="email"]', DEMO_EMAIL);
    await page.fill('input[name="password"]', DEMO_PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/app/dashboard');
}

module.exports = { loginAsDemo, DEMO_EMAIL, DEMO_PASSWORD };
