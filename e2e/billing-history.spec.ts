import { expect, test } from '@playwright/test';
import { logIn } from './helpers';

test('the billing page loads the invoice list after the page and hides the portal without Stripe', async ({ page }) => {
    await logIn(page, 'demo@acme.test', 'password');

    await page.goto('/billing');

    await expect(page.getByRole('heading', { name: 'Invoices' })).toBeVisible();
    // The fake gateway has no invoices; the list is fetched after the page, so wait for it.
    await expect(page.getByText('No invoices yet.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Update payment method' })).toHaveCount(0);
});
