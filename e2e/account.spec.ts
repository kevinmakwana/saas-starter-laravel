import { expect, test } from '@playwright/test';
import { logIn } from './helpers';

test('an owner with other team members cannot delete their account', async ({ page }) => {
    // The seeded demo user owns "Acme Demo", which also has an admin and a member.
    await logIn(page, 'demo@acme.test', 'password');

    await page.goto('/profile');
    await page.getByRole('button', { name: 'Delete Account' }).first().click();
    await page.getByPlaceholder('Password').fill('password');
    await page.getByRole('dialog').getByRole('button', { name: 'Delete Account' }).click();

    await expect(page.getByText('Transfer ownership or remove them before deleting your account.')).toBeVisible();

    // Still signed in, and the account still exists.
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/dashboard$/);
});
