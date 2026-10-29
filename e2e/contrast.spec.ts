import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { logIn } from './helpers';

// Text has to be readable in both themes. axe measures the real rendered colors against WCAG AA.
for (const scheme of ['light', 'dark'] as const) {
    test(`text contrast is readable in ${scheme} mode`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme });

        const pages = ['/', '/login', '/register'];
        for (const path of pages) {
            await page.goto(path);
            await expectReadable(page, path);
        }

        await logIn(page, 'demo@acme.test', 'password');
        for (const path of ['/dashboard', '/projects', '/team', '/billing', '/audit-log', '/profile']) {
            await page.goto(path);
            await page.waitForLoadState('networkidle');
            await expectReadable(page, path);
        }
    });
}

async function expectReadable(page: import('@playwright/test').Page, path: string): Promise<void> {
    const { violations, passes } = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();
    // Guard against a scan that looked at nothing: the rule must have checked some text on the page.
    expect(passes.find((rule) => rule.id === 'color-contrast')?.nodes.length ?? 0, `${path} was not scanned`).toBeGreaterThan(0);
    const detail = violations.flatMap((v) => v.nodes.map((n) => `${n.target.join(' ')}: ${n.any[0]?.message ?? v.help}`));
    expect(detail, `${path} has low-contrast text`).toEqual([]);
}
