import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { logIn } from './helpers';

test('a Business team can download a project as CSV', async ({ page }) => {
    await logIn(page, 'demo@acme.test', 'password');

    await page.goto('/projects');
    await page.locator('a[href*="/projects/"]').filter({ hasNotText: /New|Create/ }).first().click();

    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV' }).click();

    expect((await download).suggestedFilename()).toMatch(/-tasks\.csv$/);
});

test('an owner can download the audit log as CSV', async ({ page }) => {
    await logIn(page, 'demo@acme.test', 'password');

    await page.goto('/audit-log');
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV' }).click();
    const file = await download;

    expect(file.suggestedFilename()).toBe('audit-log.csv');
    const csv = await readFile((await file.path()) as string, 'utf8');
    expect(csv.split('\n')[0]).toBe('id,at,actor,action,subject_type,subject_id,summary');
    // The seeded demo team has activity, so there is more than the header.
    expect(csv.trim().split('\n').length).toBeGreaterThan(1);
});
