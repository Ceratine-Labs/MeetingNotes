// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsDemo } = require('../helpers');

// Registration and login must start signed out.
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Authentication', () => {
    test('a new visitor can register and lands on the dashboard', async ({ page }) => {
        await page.goto('/register');

        // Unique per run so the suite can repeat without a wipe.
        const email = `e2e-${Date.now()}@example.com`;
        await page.fill('input[name="name"]', 'E2E Visitor');
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', 'a-strong-password-1');
        await page.fill('input[name="password_confirmation"]', 'a-strong-password-1');
        await page.check('input[name="terms"]');
        await page.click('button[type="submit"]');

        await page.waitForURL('**/app/dashboard');
        // The dashboard shell proves the account and workspace exist; the
        // user's name only renders inside the closed account menu.
        await expect(page.getByRole('link', { name: 'Dashboard' })).toBeVisible();
    });

    test('the demo user can log in', async ({ page }) => {
        await loginAsDemo(page);
        await expect(page).toHaveURL(/app\/dashboard/);
    });
});
