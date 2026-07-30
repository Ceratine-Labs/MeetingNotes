// @ts-check
const { test } = require('@playwright/test');
const { loginAsDemo } = require('../helpers');

/**
 * Log in as the demo user exactly once per run and persist the session.
 * Every project depends on this, so specs start already authenticated
 * instead of hammering the (correctly) rate-limited login route.
 */
test('authenticate as the demo user', async ({ page }) => {
    await loginAsDemo(page);
    await page.context().storageState({ path: '.auth/demo.json' });
});
