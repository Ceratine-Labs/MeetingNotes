// @ts-check
const { test, expect } = require('@playwright/test');

// The public pages are what a signed-out visitor sees; drop the shared
// demo session for this file.
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Public site', () => {
    test('landing page renders hero, demo card and pricing strip', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByRole('heading', { name: /transcript into minutes/i })).toBeVisible();
        await expect(page.locator('[data-mn-demo]')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Full pricing detail' })).toBeVisible();
    });

    test('pricing page lists the seeded public plans and FAQ', async ({ page }) => {
        await page.goto('/pricing');
        await expect(page.getByRole('heading', { name: 'Free', exact: true })).toBeVisible();
        await expect(page.getByText('Questions people actually ask')).toBeVisible();
    });

    test('manifest and service worker endpoints are served', async ({ request }) => {
        const manifest = await request.get('/manifest.webmanifest');
        expect(manifest.ok()).toBeTruthy();
        expect((await manifest.json()).icons.length).toBeGreaterThanOrEqual(3);

        const sw = await request.get('/sw.js');
        expect(sw.ok()).toBeTruthy();
    });
});
