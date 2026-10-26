import { expect, test } from '@playwright/test';
import { PASSWORD, linkFromMail, signUp, uniqueEmail, waitForMail } from './helpers';

/** Asks for a new address from the profile page and returns the confirmation link mailed to it. */
async function requestChange(page: import('@playwright/test').Page, oldEmail: string, newEmail: string): Promise<string> {
    await page.goto('/profile');
    await page.fill('#email', newEmail);
    await page.fill('#current_password', PASSWORD);
    await page.getByRole('button', { name: 'Save' }).first().click();

    await expect(page.getByTestId('pending-email')).toContainText(newEmail);

    // The new address gets the link, the old one a heads-up.
    const confirmation = await waitForMail(newEmail, /Confirm your new email/i);
    await waitForMail(oldEmail, /Someone asked to change your email/i);

    return linkFromMail(confirmation, /profile\/email\/confirm/);
}

test('a new email address only replaces the old one after its link is opened', async ({ page, context }) => {
    const oldEmail = await signUp(page, 'Erin Emailer');
    const newEmail = uniqueEmail('changed');

    const link = await requestChange(page, oldEmail, newEmail);

    // Until then nothing has changed: the account still uses the old address.
    await page.goto('/profile');
    await expect(page.locator('#email')).toHaveValue(oldEmail);

    await page.goto(link);
    await expect(page.getByText('Your email address was changed.')).toBeVisible();
    await expect(page.locator('#email')).toHaveValue(newEmail);
    await expect(page.getByTestId('pending-email')).toHaveCount(0);

    // The old address is told it happened, and only the new one signs in now.
    await waitForMail(oldEmail, /Your email address was changed/i);

    await context.clearCookies();
    await page.goto('/login');
    await page.fill('#email', oldEmail);
    await page.fill('#password', PASSWORD);
    await page.click('form button');
    await expect(page.getByText(/do not match|failed/i)).toBeVisible();

    await page.fill('#email', newEmail);
    await page.fill('#password', PASSWORD);
    await page.click('form button');
    await page.waitForURL('**/dashboard');
});

test('a pending email change can be cancelled, and its link stops working', async ({ page }) => {
    const oldEmail = await signUp(page, 'Carl Canceller');
    const newEmail = uniqueEmail('cancelled');

    const link = await requestChange(page, oldEmail, newEmail);

    await page.getByRole('button', { name: 'Cancel the change' }).click();
    await expect(page.getByTestId('pending-email')).toHaveCount(0);

    const response = await page.goto(link);
    expect(response?.status()).toBe(410);

    await page.goto('/profile');
    await expect(page.locator('#email')).toHaveValue(oldEmail);
});
