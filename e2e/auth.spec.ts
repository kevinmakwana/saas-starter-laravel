import { expect, test } from '@playwright/test';
import { logIn, register, signUp, uniqueEmail, verifyEmail } from './helpers';

test('the seeded demo user can log in and sees the Business team', async ({ page }) => {
    await logIn(page, 'demo@acme.test', 'password');

    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByText('Business', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Audit log' }).first()).toBeVisible();
});

test('wrong credentials are rejected', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#email', 'demo@acme.test');
    await page.fill('#password', 'not-the-password');
    await page.click('form button');

    await expect(page.getByText(/do not match|credentials/i)).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
});

test('registering requires email verification, then creates a personal Free team', async ({ page }) => {
    const email = uniqueEmail('new');
    await register(page, 'Nina Newcomer', email);

    // Unverified users cannot reach the dashboard.
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/verify-email/);

    await verifyEmail(page, email);

    await expect(page.getByText('Free', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Switch team')).toContainText("Nina Newcomer's Team");
});

test('a signed-up user can create a project with a task', async ({ page }) => {
    await signUp(page, 'Tara Tasker');

    await page.goto('/projects/create');
    await page.fill('#name', 'Launch plan');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('heading', { name: 'Launch plan' })).toBeVisible();

    await page.getByLabel('New task title').fill('Write the announcement');
    await page.getByRole('button', { name: 'Add task' }).click();
    await expect(page.getByText('Write the announcement')).toBeVisible();
});
