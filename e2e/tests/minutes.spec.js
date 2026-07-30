// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Minutes pipeline (fake driver)', () => {
    test('paste to finished minutes, then export', async ({ page }) => {
        await page.goto('/app/minutes/new');
        await page.fill(
            'textarea[name="pasted_text"]',
            'Nadia: Right, budgets. The Q4 number is sitting at 520.\n' +
            'Thabo: Take it down to 480 and shift the rest into Q1.\n' +
            'Sarah: I will confirm the launch date with product this week.'
        );
        // The page has other submit buttons in the shell (user menu), so
        // target the form's own labelled button.
        await page.getByRole('button', { name: 'Generate minutes' }).click();

        // QUEUE_CONNECTION=sync + FakeDriver: generation completed inside the
        // store request, so the show page arrives already ready.
        await page.waitForURL('**/app/minutes/**');
        await expect(page.getByText('1. Meeting Information')).toBeVisible();
        await expect(page.getByText('9. Next Steps')).toBeVisible();
        await expect(page.getByText('Product Launch Steering').first()).toBeVisible();

        // Markdown export is available on every plan and needs no LLM.
        const url = new URL(page.url());
        const exportResponse = await page.request.get(`${url.pathname}/export/md`);
        expect(exportResponse.ok()).toBeTruthy();
        expect(await exportResponse.text()).toContain('Meeting Information');
    });

    test('the library lists seeded demo meetings in their states', async ({ page }) => {
        await page.goto('/app/minutes');

        await expect(page.getByRole('link', { name: 'Q3 Budget Review' })).toBeVisible();
        // Status badges in the table body; the filter dropdown also contains
        // these words, so scope the locator to badges.
        await expect(page.locator('tbody .badge', { hasText: 'processing' })).toBeVisible();
        await expect(page.locator('tbody .badge', { hasText: 'failed' })).toBeVisible();
    });
});
