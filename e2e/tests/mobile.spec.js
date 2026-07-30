// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Mobile', () => {
    test('public pages have no horizontal overflow', async ({ page }) => {
        for (const path of ['/', '/pricing', '/login']) {
            await page.goto(path);
            const overflow = await page.evaluate(
                () => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - window.innerWidth
            );
            expect(overflow, `${path} overflows horizontally`).toBeLessThanOrEqual(0);
        }
    });

    test('action items table collapses to pertinent columns with expandable detail', async ({ page }) => {
        await page.goto('/app/action-items');

        // Folded column content is hidden on mobile until expanded.
        const firstDetail = page.locator('.mn-row-detail').first();
        await expect(firstDetail).toBeHidden();

        const toggle = page.locator('[data-mn-row-toggle]').first();
        await toggle.click();
        await expect(firstDetail).toBeVisible();
        await expect(firstDetail.getByText('Priority')).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');

        await toggle.click();
        await expect(firstDetail).toBeHidden();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });
});
