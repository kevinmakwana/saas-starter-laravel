import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { createProject, PASSWORD, signUp } from './helpers';

/** Makes a token. The first one in a session asks for the password, then returns to a blank form. */
async function createToken(page: Page, name: string, access: 'Read only' | 'Read and write'): Promise<string> {
    const submit = async () => {
        await page.fill('#token_name', name);
        await page.locator('#token_access').selectOption({ label: access });
        await page.getByRole('button', { name: 'Create token' }).click();
        await page.getByTestId('new-token').or(page.getByText('This is a secure area')).waitFor();
    };

    await page.goto('/profile');
    await submit();

    if (page.url().includes('confirm-password')) {
        await page.fill('#password', PASSWORD);
        await page.getByRole('button', { name: 'Confirm' }).click();
        await page.waitForURL('**/profile');
        await submit();
    }

    await expect(page.getByTestId('new-token')).toBeVisible();
    return (await page.getByTestId('new-token').locator('code').textContent())!.trim();
}

test('a member makes a token, uses it against the API, and revoking it stops it', async ({ page, playwright, baseURL }) => {
    await signUp(page, 'Ari Apikey');
    await createProject(page, 'Seen by the browser');

    const token = await createToken(page, 'Deploy script', 'Read and write');
    await expect(page.getByTestId('api-tokens')).toContainText('Deploy script');
    await expect(page.getByTestId('api-tokens')).toContainText('Read and write');

    // A client with no cookies: only the token identifies it.
    const api = await playwright.request.newContext({ baseURL: baseURL!, extraHTTPHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });

    const created = await api.post('/api/v1/projects', { data: { name: 'Made through the API' } });
    expect(created.status()).toBe(201);
    const project = (await created.json()).data;
    expect(project.name).toBe('Made through the API');

    const task = await api.post(`/api/v1/projects/${project.id}/tasks`, { data: { title: 'First task' } });
    expect(task.status()).toBe(201);

    const list = await api.get('/api/v1/projects');
    expect((await list.json()).data.map((p: { name: string }) => p.name)).toEqual(expect.arrayContaining(['Seen by the browser', 'Made through the API']));

    // The browser sees what the API made, and the token shows as used.
    await page.goto('/projects');
    await expect(page.getByText('Made through the API')).toBeVisible();
    await page.goto('/profile');
    await expect(page.getByTestId('api-tokens')).not.toContainText('Last used never');

    // No token, no way in; the cookie session of the browser does not count either.
    expect((await (await playwright.request.newContext({ baseURL: baseURL! })).get('/api/v1/projects', { headers: { Accept: 'application/json' } })).status()).toBe(401);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Revoke Deploy script' }).click();
    await expect(page.getByText('API token revoked.')).toBeVisible();
    expect((await api.get('/api/v1/projects')).status()).toBe(401);
});

test('a read-only token can look but not change anything', async ({ page, playwright, baseURL }) => {
    await signUp(page, 'Rae Reader');
    const token = await createToken(page, 'Reporting', 'Read only');

    const api = await playwright.request.newContext({ baseURL: baseURL!, extraHTTPHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });

    expect((await api.get('/api/v1/projects')).status()).toBe(200);
    expect((await api.post('/api/v1/projects', { data: { name: 'Nope' } })).status()).toBe(403);
});

for (const scheme of ['light', 'dark'] as const) {
    test(`the token section is readable in ${scheme} mode and fits a phone`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await page.setViewportSize({ width: 390, height: 844 });
        await signUp(page, 'Cleo Contrast');

        // With the one-time token showing, then with only the list.
        await createToken(page, 'A rather long token name that has to wrap on a small screen without pushing the page sideways', 'Read and write');
        await expectFine(page, 'the new token');
        await page.goto('/profile');
        await expect(page.getByTestId('api-tokens')).toBeVisible();
        await expectFine(page, 'the token list');
    });
}

async function expectFine(page: Page, what: string): Promise<void> {
    const { violations, passes } = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze();
    expect(passes.find((rule) => rule.id === 'color-contrast')?.nodes.length ?? 0, `${what} was not scanned`).toBeGreaterThan(0);
    expect(violations.flatMap((v) => v.nodes.map((n) => n.target.join(' '))), `${what} has low-contrast text`).toEqual([]);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, `${what}: the page is ${overflow}px wider than the screen`).toBeLessThanOrEqual(0);
}
