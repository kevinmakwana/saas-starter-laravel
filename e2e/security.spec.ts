import { expect, test } from '@playwright/test';
import { logIn } from './helpers';

test('the content security policy does not break any main page', async ({ page }) => {
    const violations: string[] = [];
    page.on('console', (message) => {
        if (/content security policy/i.test(message.text())) violations.push(message.text());
    });
    page.on('pageerror', (error) => violations.push(error.message));

    const login = await page.goto('/login');
    expect(login?.headers()['content-security-policy']).toContain("default-src 'self'");
    expect(login?.headers()['x-frame-options']).toBe('SAMEORIGIN');

    await logIn(page, 'demo@acme.test', 'password');

    for (const path of ['/dashboard', '/projects', '/team', '/billing', '/audit-log', '/profile']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        // The Vue app mounted, so scripts (including the nonce'd Ziggy one) ran.
        await expect(page.locator('#app')).not.toBeEmpty();
    }

    expect(violations).toEqual([]);
});
