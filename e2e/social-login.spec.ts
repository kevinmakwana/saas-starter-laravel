import { expect, test } from '@playwright/test';

// The providers' own pages are never reached: the browser is stopped at the redirect. Everything after the provider answers
// (new account, linking, two-factor) is covered by the feature tests with a faked provider.
test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    test.skip((await page.getByTestId('social-login').count()) === 0, 'no OAuth credentials configured for this run');
});

test('the sign-in and sign-up pages offer Google and GitHub', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'Sign in with Google' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sign in with GitHub' })).toBeVisible();

    await page.goto('/register');
    await expect(page.getByRole('link', { name: 'Sign up with GitHub' })).toBeVisible();
});

test('the GitHub button sends the browser to GitHub with the right client, return address and state', async ({ page, baseURL }) => {
    await page.route('https://github.com/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: 'GitHub' }));

    const request = page.waitForRequest((r) => r.url().startsWith('https://github.com/login/oauth/authorize'));
    await page.getByRole('link', { name: 'Sign in with GitHub' }).click();
    const url = new URL((await request).url());

    expect(url.searchParams.get('client_id')).toBe('e2e-github-id');
    expect(url.searchParams.get('redirect_uri')).toBe(`${baseURL}/auth/github/callback`);
    expect(url.searchParams.get('scope')).toContain('user:email');
    expect(url.searchParams.get('state')?.length ?? 0).toBeGreaterThan(20);
});

test('saying no at the provider returns to the sign-in page with a message', async ({ page }) => {
    await page.goto('/auth/github/callback?error=access_denied');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText('Signing in with GitHub was cancelled.')).toBeVisible();
});

test('the buttons fit a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });

    for (const path of ['/login', '/register']) {
        await page.goto(path);
        await expect(page.getByTestId('social-login')).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, `${path} is ${overflow}px wider than the screen`).toBeLessThanOrEqual(0);
    }
});
