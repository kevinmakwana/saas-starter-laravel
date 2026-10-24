import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { logIn } from './helpers';

// The export allows 3 attempts an hour per user, so each test uses its own seeded user and a single attempt (CI retries once).

test('a user can download their personal data as JSON', async ({ page }) => {
    await logIn(page, 'member@acme.test', 'password');

    await page.goto('/profile');
    await page.locator('#export_password').fill('password');

    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download', exact: true }).click();
    const file = await download;

    expect(file.suggestedFilename()).toBe('account-data.json');
    const data = JSON.parse(await readFile((await file.path()) as string, 'utf8'));
    expect(data.account.email).toBe('member@acme.test');
    expect(data.teams.map((team: { name: string }) => team.name)).toContain('Acme Demo');
    expect(data.account).not.toHaveProperty('password');
    await expect(page.getByRole('status')).toHaveText('Downloaded.');
});

test('a wrong password downloads nothing and says why', async ({ page }) => {
    await logIn(page, 'admin@acme.test', 'password');

    await page.goto('/profile');
    await page.locator('#export_password').fill('not-the-password');
    await page.getByRole('button', { name: 'Download', exact: true }).click();

    await expect(page.getByText('The password is incorrect.')).toBeVisible();
    await expect(page.locator('#export_password')).toHaveValue('not-the-password');
});
