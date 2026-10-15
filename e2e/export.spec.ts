import { expect, test } from '@playwright/test';
import { logIn } from './helpers';

test('a Business team can download a project as CSV', async ({ page }) => {
    await logIn(page, 'demo@acme.test', 'password');

    await page.goto('/projects');
    await page.locator('a[href*="/projects/"]').filter({ hasNotText: /New|Create/ }).first().click();

    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV' }).click();

    expect((await download).suggestedFilename()).toMatch(/-tasks\.csv$/);
});
