import { expect, test } from '@playwright/test';
import { linkFromMail, register, signUp, uniqueEmail, verifyEmail, waitForMail } from './helpers';

// The layout has a bell for wide screens and one for the phone menu; only one is visible at a time.
const badge = (page: import('@playwright/test').Page) => page.locator('[data-testid="notification-badge"]:visible');

test('an owner is told about a plan change and a new member, and can open and clear them', async ({ browser }) => {
    const ownerContext = await browser.newContext();
    const owner = await ownerContext.newPage();
    const invitee = await (await browser.newContext()).newPage();

    // A fake clock lets the test jump past the one-minute refresh instead of waiting for it.
    await owner.clock.install();
    await signUp(owner, 'Nora Notified');

    // Nothing yet.
    await expect(owner.getByRole('button', { name: 'Notifications', exact: true })).toBeVisible();
    await expect(badge(owner)).toHaveCount(0);

    // Subscribing to Pro changes the plan: the owner gets one.
    await owner.goto('/billing');
    await owner.getByRole('button', { name: 'Subscribe' }).first().click();
    await expect(owner.getByText("You're now on the Pro plan.")).toBeVisible();
    await expect(badge(owner)).toHaveText('1');
    await owner.getByRole('button', { name: 'Notifications, 1 unread' }).click();
    await expect(owner.getByTestId('notification-panel').getByText("Nora Notified's Team is now on the Pro plan.")).toBeVisible();
    await owner.keyboard.press('Escape');
    await expect(owner.getByTestId('notification-panel')).toHaveCount(0);

    // Someone accepts an invitation. The owner's bell updates by itself, with no reload.
    const inviteeEmail = uniqueEmail('joiner');
    await owner.goto('/team');
    await owner.fill('#invite-email', inviteeEmail);
    await owner.getByRole('button', { name: 'Send invite' }).click();
    const acceptUrl = await linkFromMail(await waitForMail(inviteeEmail, /invited/i), /invitations\//);
    await register(invitee, 'Jo Joiner', inviteeEmail);
    await verifyEmail(invitee, inviteeEmail);
    await invitee.goto(acceptUrl);
    await expect(invitee.getByText(/You joined Nora Notified's Team/)).toBeVisible();

    await owner.clock.fastForward(61_000);
    await expect(badge(owner)).toHaveText('2');

    // Newest first. Opening it goes to the team page and marks just that one read.
    await owner.getByRole('button', { name: 'Notifications, 2 unread' }).click();
    const panel = owner.getByTestId('notification-panel');
    // The first button is "Mark all read"; then the newest notification, then the older one.
    await expect(panel.getByRole('button').nth(1)).toContainText('Jo Joiner joined Nora Notified');
    await expect(panel.getByRole('button').nth(2)).toContainText('is now on the Pro plan');
    await panel.getByRole('button', { name: /Jo Joiner joined/ }).click();
    await owner.waitForURL('**/team');
    await expect(badge(owner)).toHaveText('1');

    // Mark the rest read.
    await owner.getByRole('button', { name: 'Notifications, 1 unread' }).click();
    await owner.getByRole('button', { name: 'Mark all read' }).click();
    await expect(badge(owner)).toHaveCount(0);
    await expect(owner.getByRole('button', { name: 'Notifications', exact: true })).toBeVisible();
    await expect(panel.locator('[data-unread="true"]')).toHaveCount(0);

    await ownerContext.close();
    await invitee.context().close();
});

test('the seeded demo owner starts with notifications to look at', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#email', 'demo@acme.test');
    await page.fill('#password', 'password');
    await page.click('form button');
    await page.waitForURL('**/dashboard');

    await page.getByRole('button', { name: /^Notifications/ }).click();
    const panel = page.getByTestId('notification-panel');
    await expect(panel.getByText('Acme Demo is now on the Business plan.')).toBeVisible();
    await expect(panel.getByText('Avery Admin joined Acme Demo.')).toBeVisible();
    await expect(panel.getByText('Morgan Member joined Acme Demo.')).toBeVisible();
});
