import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { logIn, PASSWORD, signUp } from './helpers';

/** The language picker in the header (the phone menu has a second one, hidden at desktop width). Its label is translated, so it is found by its options. */
const picker = (page: Page) => page.locator('select:visible', { has: page.locator('option[value="es"]') });

test('a visitor gets the language their browser asks for, and can change it on the sign-in page', async ({ browser }) => {
    const context = await browser.newContext({ locale: 'de-DE' });
    const page = await context.newPage();

    await page.goto('/login');
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    await expect(page.getByRole('button', { name: 'Anmelden' })).toBeVisible();

    // An explicit choice beats the browser, and sticks.
    await picker(page).selectOption('es');
    await expect(page.getByRole('button', { name: 'Iniciar sesión' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'es');
    await page.reload();
    await expect(page.getByRole('button', { name: 'Iniciar sesión' })).toBeVisible();

    await context.close();
});

test('a language chosen while signed in is kept on the account, in the screens, the messages and across sign-ins', async ({ page, context }) => {
    const email = await signUp(page, 'Lena Sprache');

    await picker(page).selectOption('de');
    await expect(page.getByRole('link', { name: 'Projekte' }).first()).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');

    // Messages from the server are in German too.
    await page.goto('/projects/create');
    await page.fill('#name', 'Mein Projekt');
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Projekt erstellt.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Aufgabe hinzufügen' })).toBeVisible();

    // The bell, the billing page and the validation messages.
    await expect(page.getByRole('button', { name: /^Benachrichtigungen/ })).toBeVisible();
    await page.goto('/billing');
    await expect(page.getByRole('heading', { name: 'Abrechnung' })).toBeVisible();
    await page.goto('/team');
    await page.fill('#invite-email', 'nobody@example.test');
    await page.getByRole('button', { name: 'Einladung senden' }).click();
    await expect(page.getByText('Einladung an nobody@example.test gesendet.')).toBeVisible();

    // Signing out and in again: the account remembers.
    await context.clearCookies();
    await logIn(page, email, PASSWORD);
    await expect(page.getByRole('link', { name: 'Projekte' }).first()).toBeVisible();

    // And it can go back.
    await picker(page).selectOption('en');
    await expect(page.getByRole('link', { name: 'Projects' }).first()).toBeVisible();
});

test('the login error is in the visitor\'s language', async ({ browser }) => {
    const context = await browser.newContext({ locale: 'es-ES' });
    const page = await context.newPage();

    await page.goto('/login');
    await page.fill('#email', 'demo@acme.test');
    await page.fill('#password', 'wrong-password');
    await page.getByRole('button', { name: 'Iniciar sesión' }).click();
    await expect(page.getByText('Estas credenciales no coinciden con nuestros registros.')).toBeVisible();

    await context.close();
});

// German words are long. The pages must still fit a phone, and stay readable in both themes.
test('German pages fit a phone and are readable in both themes', async ({ browser }) => {
    const context = await browser.newContext({ locale: 'de-DE', viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    await logIn(page, 'demo@acme.test', 'password');

    for (const scheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme: scheme });

        for (const path of ['/dashboard', '/projects', '/team', '/billing', '/audit-log', '/profile']) {
            await page.goto(path);
            await page.waitForLoadState('networkidle');

            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            expect(overflow, `${path} is ${overflow}px wider than the screen`).toBeLessThanOrEqual(0);

            const { violations } = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();
            expect(violations.flatMap((v) => v.nodes.map((n) => n.target.join(' '))), `${path} (${scheme}) has low-contrast text`).toEqual([]);
        }
    }

    await context.close();
});
