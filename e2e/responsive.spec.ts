import { expect, test } from '@playwright/test';
import { logIn } from './helpers';

// A page that scrolls sideways on a phone looks broken. Check the main pages at a common phone width.
test('the main pages do not scroll sideways on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await logIn(page, 'demo@acme.test', 'password');

    for (const path of ['/dashboard', '/projects', '/team', '/billing', '/audit-log', '/profile']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, `${path} is ${overflow}px wider than the screen`).toBeLessThanOrEqual(0);
    }
});

test('the notification panel fits inside a phone screen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await logIn(page, 'demo@acme.test', 'password');

    await page.getByRole('button', { name: /^Notifications/ }).click();
    const box = await page.getByTestId('notification-panel').boundingBox();

    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(390);
});

test('a task with a long file name does not push the project page sideways on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await logIn(page, 'demo@acme.test', 'password');
    await page.goto('/projects');
    await page.locator('a[href*="/projects/"]').filter({ hasNotText: /New|Create/ }).first().click();
    await page.getByRole('button', { name: /^Files for/ }).first().click();

    await page.locator('input[type=file]').first().setInputFiles({
        name: `${'a-very-long-quarterly-planning-document-name-'.repeat(3)}.pdf`,
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\nx'),
    });
    await expect(page.getByText('File attached.')).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, `the project page is ${overflow}px wider than the screen`).toBeLessThanOrEqual(0);
});
