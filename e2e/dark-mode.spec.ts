import { expect, test } from '@playwright/test';
import { logIn } from './helpers';

const isDark = (page: import('@playwright/test').Page) => page.evaluate(() => document.documentElement.classList.contains('dark'));

test('the page follows the system setting until the user chooses', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/login');
    expect(await isDark(page)).toBe(true);

    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/login');
    expect(await isDark(page)).toBe(false);
});

test('the toggle switches the theme and the choice survives a reload and a new page', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await logIn(page, 'demo@acme.test', 'password');
    expect(await isDark(page)).toBe(false);

    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    expect(await isDark(page)).toBe(true);
    await expect(page.getByRole('button', { name: 'Switch to light mode' })).toBeVisible();

    await page.reload();
    expect(await isDark(page)).toBe(true);
    await page.goto('/team');
    expect(await isDark(page)).toBe(true);

    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    expect(await isDark(page)).toBe(false);
    await page.reload();
    expect(await isDark(page)).toBe(false);
});

test('an explicit choice wins over the system setting', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await logIn(page, 'demo@acme.test', 'password');
    expect(await isDark(page)).toBe(true);

    await page.getByRole('button', { name: 'Switch to light mode' }).click();
    await page.reload();
    expect(await isDark(page)).toBe(false);
});

test('the toggle is also on the pages before signing in', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/login');
    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    expect(await isDark(page)).toBe(true);

    await page.goto('/');
    expect(await isDark(page)).toBe(true);
    await expect(page.getByRole('button', { name: 'Switch to light mode' })).toBeVisible();
});
