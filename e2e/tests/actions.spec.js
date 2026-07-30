// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Action items register', () => {
    test('lists open items, ticks one off, and reopens it', async ({ page }) => {
        await page.goto('/app/action-items');

        // Seeded: budget A1 is done, so the open view must not show it.
        await expect(page.getByText('Book the venue for the launch event')).toBeVisible();
        await expect(page.getByText('Circulate the revised budget spreadsheet')).toBeHidden();

        // Tick the venue item off and watch it leave the open view.
        const venueRow = page.locator('tr', { hasText: 'Book the venue for the launch event' }).first();
        await venueRow.locator('button[title="Mark done"]').click();
        await expect(page.locator('.swal2-title')).toHaveText(/marked done/);
        await expect(page.getByText('Book the venue for the launch event')).toBeHidden();

        // Restore state via the done filter so the suite can rerun cleanly.
        await page.goto('/app/action-items?status=done');
        const doneRow = page.locator('tr', { hasText: 'Book the venue for the launch event' }).first();
        await doneRow.locator('button[title="Reopen"]').click();
        await expect(page.locator('.swal2-title')).toHaveText(/reopened/);
    });

    test('owner filter narrows the list', async ({ page }) => {
        await page.goto('/app/action-items?owner=' + encodeURIComponent('Neal Cruickshank'));

        await expect(page.getByText('Draft the press release')).toBeVisible();
        await expect(page.getByText('Confirm the launch date with the product team')).toBeHidden();
    });
});
