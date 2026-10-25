import { createHmac } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { PASSWORD, signUp } from './helpers';

/** The 6-digit code an authenticator app shows for a base32 secret, `stepOffset` 30-second steps from now. */
function totp(secret: string, stepOffset = 0): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/=+$/, '')) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)));

    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000) + stepOffset));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const value = hmac.readUInt32BE(offset) & 0x7fffffff;

    return String(value % 1_000_000).padStart(6, '0');
}

test('a user turns on 2FA, then signs in with a code and with a recovery code', async ({ page, context }) => {
    // A fresh user: the seeded demo accounts are shared by the other specs and must stay password-only.
    const email = await signUp(page, 'Tess Twofactor');

    await page.goto('/profile');
    await page.getByRole('button', { name: 'Set up' }).click();

    // Starting a setup asks for the password first, then returns to the profile.
    await page.waitForURL('**/confirm-password');
    await page.fill('#password', PASSWORD);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await page.waitForURL('**/profile');

    await page.getByRole('button', { name: 'Set up' }).click();
    const secret = (await page.getByTestId('setup-key').textContent())!.trim();
    await expect(page.getByTestId('qr-code').locator('svg')).toBeVisible();

    await page.fill('#two_factor_code', '000000');
    await page.getByRole('button', { name: 'Turn on' }).click();
    await expect(page.getByText('That code is not right')).toBeVisible();

    await page.fill('#two_factor_code', totp(secret, -1));
    await page.getByRole('button', { name: 'Turn on' }).click();

    // allTextContents() does not wait, so let the codes appear first.
    await expect(page.getByTestId('recovery-codes')).toBeVisible();
    const codes = await page.getByTestId('recovery-codes').locator('li').allTextContents();
    expect(codes).toHaveLength(8);
    await expect(page.getByText('Two-factor authentication is on')).toBeVisible();

    // Sign in again: the password alone is not enough.
    await context.clearCookies();
    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', PASSWORD);
    await page.click('form button');
    await page.waitForURL('**/two-factor-challenge');

    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);

    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', PASSWORD);
    await page.click('form button');
    await page.waitForURL('**/two-factor-challenge');

    await page.fill('#code', '000000');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByText('That code is not valid.')).toBeVisible();

    await page.fill('#code', totp(secret));
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/dashboard');

    // And once more with a recovery code, which then no longer works.
    await context.clearCookies();
    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', PASSWORD);
    await page.click('form button');
    await page.waitForURL('**/two-factor-challenge');

    await page.getByRole('button', { name: 'Use a recovery code' }).click();
    await page.fill('#recovery_code', codes[0].trim());
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/dashboard');

    await page.goto('/profile');
    await expect(page.getByText('You have 7 recovery codes left')).toBeVisible();
});
